<?php

use App\Enums\Flow\FlowPaymentStatus;
use App\Enums\Reservation\ReservationStatus;
use App\Enums\Reservation\ReviewReason;
use App\Models\FlowPayment;
use App\Models\Reservation;
use App\Services\AiAgentHub\Tools\AiToolCatalog;
use App\Services\Flow\AiToolNodes;
use App\Services\Flow\FlowPaymentService;
use App\Services\Reservations\ReservationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CatalogFixtures as C;
use Tests\Support\IntegrationFixtures as Fx;
use Tests\Support\ReservationFixtures as R;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'services.billing.enforce' => false,
        'ai.tools.enabled' => true,
        'ai.tools.reservations' => true,
    ]);
});

it('keeps the reservation tools away from the hub until they are switched on', function () {
    config(['ai.tools.reservations' => false]);

    expect(array_column(AiToolCatalog::all(), 'name'))->toBe([
        'search_products', 'get_product', 'cart_add', 'cart_remove', 'cart_view', 'create_payment',
    ]);

    $scene = R::scenario();
    R::service($scene['tenant']);

    C::call($scene, 'list_reservation_services')
        ->assertStatus(422)
        ->assertJsonPath('code', 'tool_not_enabled');

    config(['ai.tools.reservations' => true]);

    expect(AiToolCatalog::all())->toHaveCount(13);
});

it('tells the model what day it is in the shop, so "tomorrow" has a date', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-03 02:30:00', 'UTC'));

    expect(AiToolNodes::preamble(['request_reservation'], 'America/Sao_Paulo'))
        ->toContain('Today is Monday 2026-11-02, 23:30 in the shop\'s local time.')
        ->and(AiToolNodes::preamble(['search_products'], 'America/Sao_Paulo'))
        ->not->toContain('Today is');
});

it('lists the free times the calendar has, minus what is already booked', function () {
    $scene = R::scenario();
    $service = R::service($scene['tenant'], ['capacity' => 1]);
    $date = R::day($scene['tenant']);

    // A phone booking at 19:00 written down by the team.
    app(ReservationService::class)->createManual($service, R::localStart($scene['tenant'], $date, '19:00'), 2, ['customer_name' => 'Ana'], $scene['owner'], false);

    $response = C::call($scene, 'check_availability', ['service_id' => $service->id, 'date' => $date])->assertOk();

    expect($response->json('result.times'))->toContain('18:00')->toContain('20:00')->not->toContain('19:00')
        ->and($response->json('result.times'))->toHaveCount(12);
});

it('confirms a free time by itself and takes the confirmed step after the reply', function () {
    $scene = R::scenario();
    $service = R::service($scene['tenant']);
    $date = R::day($scene['tenant']);

    $response = C::call($scene, 'request_reservation', [
        'service_id' => $service->id, 'date' => $date, 'time' => '20:00', 'party_size' => 4, 'customer_name' => 'Maria',
    ])->assertOk();

    $reservation = Reservation::firstOrFail();

    expect($response->json('ok'))->toBeTrue()
        ->and($response->json('message_for_model'))->toContain('CONFIRMED')->toContain($reservation->code)
        ->and($reservation->status)->toBe(ReservationStatus::Confirmed)
        ->and($reservation->customer_name)->toBe('Maria')
        ->and(Fx::notes($scene['conversation'], ReservationService::INFO_BOOKED))->toHaveCount(1)
        ->and(R::currentNode($scene)?->id)->toBe($scene['confirmed']->id)
        ->and($scene['conversation']->fresh()->flowState->state_data['reservation_code'])->toBe($reservation->code);
});

it('refuses a full time with the times still free, and writes nothing', function () {
    $scene = R::scenario();
    $service = R::service($scene['tenant'], ['capacity' => 1]);
    $date = R::day($scene['tenant']);

    app(ReservationService::class)->createManual($service, R::localStart($scene['tenant'], $date, '20:00'), 1, ['customer_name' => 'Ana'], $scene['owner'], false);

    $response = C::call($scene, 'request_reservation', [
        'service_id' => $service->id, 'date' => $date, 'time' => '20:00', 'party_size' => 2,
    ])->assertOk();

    expect($response->json('ok'))->toBeFalse()
        ->and($response->json('result.error'))->toBe('full')
        ->and($response->json('result.free_times'))->toContain('19:00')
        ->and(Reservation::count())->toBe(1);
});

it('holds the time for the team when the service does not confirm by itself', function () {
    $scene = R::scenario();
    $service = R::service($scene['tenant'], ['auto_confirm' => false, 'capacity' => 1]);
    $date = R::day($scene['tenant']);

    $response = C::call($scene, 'request_reservation', [
        'service_id' => $service->id, 'date' => $date, 'time' => '20:00', 'party_size' => 2,
    ])->assertOk();

    $reservation = Reservation::firstOrFail();

    expect($reservation->status)->toBe(ReservationStatus::Pending)
        ->and($reservation->review_reason)->toBe(ReviewReason::NeedsConfirmation)
        ->and($response->json('message_for_model'))->toContain('NOT confirmed');

    // The time is held: nobody else gets it while the team decides.
    $other = C::call($scene, 'check_availability', ['service_id' => $service->id, 'date' => $date])->json('result.times');
    expect($other)->not->toContain('20:00');

    // The team confirms; the AI tells the customer and the flow moves on.
    C::$state['reply'] = 'Sua reserva está confirmada!';
    app(ReservationService::class)->decide($reservation, $scene['owner'], ReservationService::ACTION_CONFIRM);

    $reservation->refresh();

    expect($reservation->status)->toBe(ReservationStatus::Confirmed)
        ->and($reservation->notify_status)->toBe(Reservation::NOTIFY_SENT)
        ->and(Fx::sentTexts($scene['conversation']))->toContain('Sua reserva está confirmada!')
        ->and(R::currentNode($scene)?->id)->toBe($scene['confirmed']->id)
        ->and(collect(C::$state['runs'])->last()['message']['content'] ?? '')->toContain('CONFIRMED');
});

it('charges the deposit, holds the slot, and confirms when the money arrives', function () {
    $scene = R::scenario();
    $service = R::service($scene['tenant'], ['payment_mode' => 'deposit', 'deposit_cents' => 5000, 'capacity_unit' => 'people', 'capacity' => 10]);
    $date = R::day($scene['tenant']);

    $response = C::call($scene, 'request_reservation', [
        'service_id' => $service->id, 'date' => $date, 'time' => '20:00', 'party_size' => 3,
    ])->assertOk();

    $reservation = Reservation::firstOrFail();
    $payment = FlowPayment::firstOrFail();

    expect($reservation->status)->toBe(ReservationStatus::AwaitingPayment)
        ->and($reservation->amount_due_cents)->toBe(15000)
        ->and($payment->reservation_id)->toBe($reservation->id)
        ->and($payment->amount_cents)->toBe(15000)
        ->and(C::$state['charges'][0]['value'])->toBe(15000)
        ->and($response->json('result.sent_to_customer'))->toBeTrue()
        ->and($reservation->hold_expires_at?->equalTo($payment->expires_at))->toBeTrue();

    C::$state['reply'] = 'Pagamento recebido, reserva confirmada!';
    (new FlowPaymentService)->settle($payment, FlowPaymentStatus::Paid);

    $reservation->refresh();

    expect($reservation->status)->toBe(ReservationStatus::Confirmed)
        ->and($reservation->paid_at)->not->toBeNull()
        ->and(Fx::sentTexts($scene['conversation']))->toContain('Pagamento recebido, reserva confirmada!')
        ->and(R::currentNode($scene)?->id)->toBe($scene['confirmed']->id);
});

it('frees the slot when the deposit is not paid in time', function () {
    $scene = R::scenario();
    $service = R::service($scene['tenant'], ['payment_mode' => 'full', 'price_cents' => 8000, 'capacity' => 1]);
    $date = R::day($scene['tenant']);

    C::call($scene, 'request_reservation', ['service_id' => $service->id, 'date' => $date, 'time' => '20:00'])->assertOk();

    (new FlowPaymentService)->settle(FlowPayment::firstOrFail(), FlowPaymentStatus::Expired);

    expect(Reservation::firstOrFail()->status)->toBe(ReservationStatus::Expired)
        ->and(C::call($scene, 'check_availability', ['service_id' => $service->id, 'date' => $date])->json('result.times'))->toContain('20:00');
});

it('sends a reservation that asks for money to the team when the node cannot charge', function () {
    $scene = R::scenario(['payment' => ['enabled' => false]]);
    $service = R::service($scene['tenant'], ['payment_mode' => 'deposit', 'deposit_cents' => 5000]);

    C::call($scene, 'request_reservation', ['service_id' => $service->id, 'date' => R::day($scene['tenant']), 'time' => '20:00'])->assertOk();

    $reservation = Reservation::firstOrFail();

    expect($reservation->status)->toBe(ReservationStatus::Pending)
        ->and($reservation->review_reason)->toBe(ReviewReason::PaymentUnavailable)
        ->and(FlowPayment::count())->toBe(0);
});

it('lets the customer take a time the team offered', function () {
    $scene = R::scenario();
    $service = R::service($scene['tenant'], ['auto_confirm' => false]);
    $date = R::day($scene['tenant']);

    C::call($scene, 'request_reservation', ['service_id' => $service->id, 'date' => $date, 'time' => '20:00'])->assertOk();
    $reservation = Reservation::firstOrFail();

    app(ReservationService::class)->decide($reservation, $scene['owner'], ReservationService::ACTION_OFFER, R::localStart($scene['tenant'], $date, '21:00'));
    expect($reservation->fresh()->status)->toBe(ReservationStatus::Offered);

    $response = C::call($scene, 'accept_offered_time', ['reservation_id' => $reservation->id])->assertOk();

    $reservation->refresh();

    expect($response->json('message_for_model'))->toContain('CONFIRMED')
        ->and($reservation->status)->toBe(ReservationStatus::Confirmed)
        ->and($reservation->localStart($scene['tenant']->displayTimezone())->format('H:i'))->toBe('21:00');
});

it('moves and cancels the customer\'s own reservation, but not a paid one', function () {
    $scene = R::scenario();
    // Unwired "confirmed": the AI keeps the conversation after confirming.
    $scene['node']->outgoingEdges()->where('condition_value', 'reservation_confirmed')->delete();
    $service = R::service($scene['tenant'], ['cancel_until_hours' => 1]);
    $date = R::day($scene['tenant']);

    C::call($scene, 'request_reservation', ['service_id' => $service->id, 'date' => $date, 'time' => '20:00'])->assertOk();
    $reservation = Reservation::firstOrFail();

    C::call($scene, 'reschedule_reservation', ['reservation_id' => $reservation->id, 'date' => $date, 'time' => '21:00'])->assertOk();
    expect($reservation->fresh()->localStart($scene['tenant']->displayTimezone())->format('H:i'))->toBe('21:00');

    $reservation->forceFill(['amount_due_cents' => 5000, 'paid_at' => now()])->save();

    C::call($scene, 'cancel_reservation', ['reservation_id' => $reservation->id])
        ->assertOk()
        ->assertJsonPath('result.error', 'paid');

    $reservation->forceFill(['amount_due_cents' => 0, 'paid_at' => null])->save();

    C::call($scene, 'cancel_reservation', ['reservation_id' => $reservation->id])->assertOk()->assertJsonPath('ok', true);

    expect($reservation->fresh()->status)->toBe(ReservationStatus::Cancelled)
        ->and($reservation->fresh()->cancelled_by)->toBe(ReservationService::BY_CUSTOMER);
});

it('takes a request as the customer said it when no service is set up', function () {
    $scene = R::scenario();

    C::call($scene, 'request_reservation', [
        'service' => 'Mesa para aniversário', 'date' => R::day($scene['tenant']), 'time' => '20:00', 'party_size' => 12,
    ])->assertOk();

    $reservation = Reservation::firstOrFail();

    expect($reservation->status)->toBe(ReservationStatus::Pending)
        ->and($reservation->review_reason)->toBe(ReviewReason::NoSchedule)
        ->and($reservation->service_name)->toBe('Mesa para aniversário');
});

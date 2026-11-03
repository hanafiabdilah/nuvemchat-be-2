<?php

use App\Enums\Reservation\ReservationStatus;
use App\Models\Reservation;
use App\Services\Reservations\ReservationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
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

function reservationManager(\App\Models\Tenant $tenant): \App\Models\User
{
    return Fx::user($tenant, ['reservations.view', 'reservations.manage']);
}

it('creates a service and refuses a deposit without an amount', function () {
    $tenant = Fx::tenant();
    Sanctum::actingAs(reservationManager($tenant));

    $this->postJson('/api/reservation-services', [
        'name' => 'Mesa', 'duration_minutes' => 90, 'capacity' => 40, 'capacity_unit' => 'people',
        'payment_mode' => 'deposit',
    ])->assertStatus(422)->assertJsonValidationErrors('deposit_cents');

    $response = $this->postJson('/api/reservation-services', [
        'name' => 'Mesa', 'duration_minutes' => 90, 'capacity' => 40, 'capacity_unit' => 'people',
        'payment_mode' => 'deposit', 'deposit_cents' => 2500,
        'weekly_hours' => ['fri' => [['open' => '18:00', 'close' => '00:00']]],
    ])->assertCreated();

    expect($response->json('data.auto_confirm'))->toBeTrue()
        ->and($response->json('data.weekly_hours.fri'))->toBe([['open' => '18:00', 'close' => '00:00']])
        ->and($response->json('data.weekly_hours.mon'))->toBe([]);
});

it('keeps the calendar to people allowed to read it', function () {
    $tenant = Fx::tenant();
    Sanctum::actingAs(Fx::user($tenant, ['flows.update']));

    $this->getJson('/api/reservations')->assertForbidden();
    // The flow builder still lists the services for its picker.
    $this->getJson('/api/reservation-services')->assertOk();
});

it('writes down a phone booking, refuses a full time, and takes it anyway when forced', function () {
    $tenant = Fx::tenant();
    $service = R::service($tenant, ['capacity' => 1]);
    Sanctum::actingAs(reservationManager($tenant));
    $date = R::day($tenant);

    $payload = ['service_id' => $service->id, 'date' => $date, 'time' => '19:00', 'party_size' => 2, 'customer_name' => 'Ana'];

    $first = $this->postJson('/api/reservations', $payload)->assertCreated();
    expect($first->json('data.status'))->toBe('confirmed')->and($first->json('data.source'))->toBe('manual');

    $this->postJson('/api/reservations', $payload + ['customer_name' => 'Bia'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'slot_unavailable')
        ->assertJsonPath('reason', 'full');

    $this->postJson('/api/reservations', $payload + ['force' => true])->assertCreated();

    expect(Reservation::count())->toBe(2);
});

it('draws the week as counts per service and day', function () {
    $tenant = Fx::tenant();
    $service = R::service($tenant, ['capacity' => 2]);
    $user = reservationManager($tenant);
    Sanctum::actingAs($user);
    $date = R::day($tenant);

    app(ReservationService::class)->createManual($service, R::localStart($tenant, $date, '19:00'), 2, ['customer_name' => 'Ana'], $user, false);

    $response = $this->getJson("/api/reservations/calendar?from={$date}&to={$date}")->assertOk();

    expect($response->json("services.0.days.{$date}.bookings"))->toBe(1)
        ->and($response->json("services.0.days.{$date}.people"))->toBe(2)
        ->and($response->json('summary.next_7_days'))->toBe(1);

    $list = $this->getJson("/api/reservations?from={$date}&to={$date}")->assertOk();
    expect($list->json('data.0.customer_name'))->toBe('Ana');
});

it('lets the agent answering the chat confirm the request it came in', function () {
    $scene = R::scenario();
    $service = R::service($scene['tenant'], ['auto_confirm' => false]);

    C::call($scene, 'request_reservation', ['service_id' => $service->id, 'date' => R::day($scene['tenant']), 'time' => '20:00'])->assertOk();
    $reservation = Reservation::firstOrFail();

    $stranger = Fx::user($scene['tenant'], ['reservations.confirm']);
    Sanctum::actingAs($stranger);

    // No access to the connection the request came in on: nothing to answer.
    $this->postJson("/api/reservations/{$reservation->id}/decision", ['action' => 'confirm'])->assertNotFound();

    $agent = Fx::user($scene['tenant'], ['reservations.confirm']);
    $scene['conversation']->connection->users()->attach($agent->id);
    Sanctum::actingAs($agent);

    $this->getJson("/api/conversations/{$scene['conversation']->id}/reservations")
        ->assertOk()
        ->assertJsonPath('data.0.status', 'pending');

    $this->postJson("/api/reservations/{$reservation->id}/decision", ['action' => 'offer'])
        ->assertStatus(422);

    $this->postJson("/api/reservations/{$reservation->id}/decision", ['action' => 'confirm'])
        ->assertOk()
        ->assertJsonPath('data.status', 'confirmed');

    $this->postJson("/api/reservations/{$reservation->id}/decision", ['action' => 'decline'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'reservation_not_open');
});

it('marks the customer\'s conversations with the reservation that needs the team', function () {
    $scene = R::scenario();
    $service = R::service($scene['tenant'], ['auto_confirm' => false]);

    C::call($scene, 'request_reservation', ['service_id' => $service->id, 'date' => R::day($scene['tenant']), 'time' => '20:00'])->assertOk();

    $resource = (new \App\Http\Resources\ContactResource($scene['conversation']->contact->fresh()))->resolve();

    expect($resource['reservation']['status'])->toBe('pending')
        ->and($resource['reservation']['time'])->toBe('20:00');
});

it('records how a booking went, and deletes only the team\'s own entries', function () {
    $scene = R::scenario();
    $service = R::service($scene['tenant']);
    $user = reservationManager($scene['tenant']);
    Sanctum::actingAs($user);

    C::call($scene, 'request_reservation', ['service_id' => $service->id, 'date' => R::day($scene['tenant']), 'time' => '20:00'])->assertOk();
    $byAi = Reservation::firstOrFail();

    $this->postJson("/api/reservations/{$byAi->id}/status", ['status' => 'no_show'])
        ->assertOk()
        ->assertJsonPath('data.status', 'no_show');

    $this->deleteJson("/api/reservations/{$byAi->id}")
        ->assertStatus(422)
        ->assertJsonPath('code', 'only_manual_delete');

    $manual = app(ReservationService::class)->createManual($service, R::localStart($scene['tenant'], R::day($scene['tenant'], 3), '12:00'), 1, ['customer_name' => 'Ana'], $user, false);

    $this->deleteJson("/api/reservations/{$manual->id}")->assertNoContent();

    expect(Reservation::pluck('status')->all())->toBe([ReservationStatus::NoShow]);
});

it('marks a day full and the calendar stops offering it', function () {
    $tenant = Fx::tenant();
    $date = R::day($tenant);
    $service = R::service($tenant, ['date_overrides' => [['date' => $date, 'ranges' => [], 'full' => true]]]);

    $day = app(\App\Services\Reservations\ReservationAvailability::class)->day($service, $date);

    expect($day['reason'])->toBe('day_full')->and($day['slots'])->toBe([]);
});

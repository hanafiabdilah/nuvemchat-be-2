<?php

namespace App\Services\AiAgentHub\Tools;

use App\Enums\Flow\FlowPaymentStatus;
use App\Enums\Reservation\ReservationPaymentMode;
use App\Enums\Reservation\ReservationStatus;
use App\Enums\Reservation\ReviewReason;
use App\Jobs\RunReservationConfirmedBranch;
use App\Models\Conversation;
use App\Models\FlowNode;
use App\Models\FlowState;
use App\Models\ReservableService;
use App\Models\Reservation;
use App\Models\Tenant;
use App\Services\BusinessHours;
use App\Services\Flow\AiToolNodes;
use App\Services\Flow\FlowExecutor;
use App\Services\Reservations\ReservationAvailability;
use App\Services\Reservations\ReservationSchedule;
use App\Services\Reservations\ReservationService;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * The reservation tools of the "AI agent with actions" node — see
 * AiToolCatalog for what the model is told, and AiToolExecutor for the shape
 * every answer takes.
 *
 * The model collects what the customer wants; everything else is read here:
 * which times the calendar has free, whether a booking is confirmed on the
 * spot, held for a payment or sent to the team, and what it pays. A refusal is
 * a sentence for the model to pass on, with what is free instead: a customer
 * told only "no" asks again, one told "19:30 or 20:00" picks one.
 */
final class ReservationTools
{
    public function __construct(
        private ReservationAvailability $availability,
        private ReservationService $reservations,
        private FlowExecutor $flows,
    ) {}

    /** @param  array<string, mixed>  $arguments */
    public function run(string $tool, array $arguments, Tenant $tenant, Conversation $conversation, FlowState $flowState, FlowNode $node): array
    {
        $serviceIds = AiToolNodes::capabilities($node->data ?? [])['reservation_service_ids'];

        return match ($tool) {
            'list_reservation_services' => $this->listServices($tenant, $serviceIds),
            'check_availability' => $this->checkAvailability($tenant, $serviceIds, (int) $arguments['service_id'], (string) $arguments['date'], isset($arguments['party_size']) ? (int) $arguments['party_size'] : null),
            'request_reservation' => $this->request($tenant, $serviceIds, $conversation, $flowState, $node, $arguments),
            'my_reservations' => $this->mine($tenant, $conversation),
            'accept_offered_time' => $this->acceptOffered($tenant, $conversation, $flowState, $node, (int) $arguments['reservation_id']),
            'reschedule_reservation' => $this->reschedule($tenant, $conversation, $flowState, $node, $arguments),
            'cancel_reservation' => $this->cancel($tenant, $conversation, $flowState, (int) $arguments['reservation_id'], isset($arguments['reason']) ? (string) $arguments['reason'] : null),
            default => self::refuse('unknown_tool', "There is no tool called \"{$tool}\"."),
        };
    }

    // ─────────────────────────────  Services  ─────────────────────────────

    /** @param  list<int>  $serviceIds */
    private function listServices(Tenant $tenant, array $serviceIds): array
    {
        $currency = $tenant->currency();
        $services = $this->offered($tenant, $serviceIds)->get();

        if ($services->isEmpty()) {
            return [
                'ok' => true,
                'result' => ['services' => []],
                'message_for_model' => 'No services are set up. Take the request as the customer says it — what, the day, the time, how many people — with request_reservation (use `service` for what they want); the shop will confirm it.',
            ];
        }

        $items = $services->map(fn (ReservableService $service) => array_filter([
            'service_id' => $service->id,
            'name' => $service->name,
            'description' => $service->description ? Str::limit($service->description, 300) : null,
            'duration_minutes' => (int) $service->duration_minutes,
            'counts' => $service->countsPeople() ? 'people' : 'bookings',
            'max_people' => $service->maxPartySize(),
            'opening_hours' => ReservationSchedule::summary($service),
            'special_dates' => $this->specialDates($service),
            'books_from' => $this->noticePhrase((int) $service->min_notice_minutes),
            'books_until' => "up to {$service->max_days_ahead} days ahead",
            'price' => $service->formattedPrice($currency) !== null ? $service->formattedPrice($currency).($service->countsPeople() ? ' per person' : '') : null,
            'payment_first' => $this->paymentPhrase($service, $currency),
            'confirmation' => $service->auto_confirm ? 'right away when the time is free' : 'by the shop',
            'customer_can_change' => $service->cancel_until_hours === null
                ? 'no — only the shop can move or cancel a confirmed reservation'
                : "until {$service->cancel_until_hours} hours before",
        ], fn ($value) => $value !== null && $value !== []))->values()->all();

        return [
            'ok' => true,
            'result' => ['services' => $items],
            'message_for_model' => count($items).' service(s): '.collect($items)
                ->map(fn ($s) => "{$s['name']} (service_id {$s['service_id']}, {$s['duration_minutes']} min".(isset($s['payment_first']) ? ", {$s['payment_first']} first" : '').')')
                ->implode('; ').'.',
        ];
    }

    /** @param  list<int>  $serviceIds */
    private function checkAvailability(Tenant $tenant, array $serviceIds, int $serviceId, string $date, ?int $partySize): array
    {
        $service = $this->offered($tenant, $serviceIds)->find($serviceId);

        if (! $service) {
            return self::refuse('not_found', "There is no service with service_id {$serviceId}. Use list_reservation_services.");
        }

        $timezone = $tenant->displayTimezone();
        $day = ReservationSchedule::parseDate($date, $timezone);

        if ($day === null) {
            return self::refuse('invalid_date', "\"{$date}\" is not a date. Use YYYY-MM-DD.");
        }

        $assumed = $partySize === null && $service->countsPeople();
        $party = max(1, $partySize ?? 1);
        $result = $this->availability->day($service, $day->format('Y-m-d'), $party);

        $free = collect($result['slots'])->where('available', true)->values();
        $limit = max(5, (int) config('reservations.max_slots_for_model', 24));
        $times = $free->take($limit)->map(fn ($slot) => $slot['time'])->all();
        $label = $day->format('l Y-m-d');

        if ($times === []) {
            $next = in_array($result['reason'], [ReservationAvailability::REASON_PARTY_TOO_LARGE, ReservationAvailability::REASON_TOO_FAR, ReservationAvailability::REASON_INACTIVE], true)
                ? []
                : $this->availability->nextDates($service, $day->addDay()->format('Y-m-d'), $party);

            return [
                'ok' => true,
                'result' => array_filter([
                    'service_id' => $service->id,
                    'date' => $day->format('Y-m-d'),
                    'weekday' => $day->format('l'),
                    'times' => [],
                    'reason' => $result['reason'],
                    'next_available_dates' => $next ?: null,
                ], fn ($v) => $v !== null),
                'message_for_model' => "{$service->name}, {$label}: nothing free — ".$this->reasonPhrase($result['reason'], $service, $party, $result['note'])
                    .($next !== [] ? ' The next days with free times: '.$this->datesPhrase($next, $timezone).'.' : ''),
            ];
        }

        $more = $free->count() - count($times);

        return [
            'ok' => true,
            'result' => array_filter([
                'service_id' => $service->id,
                'date' => $day->format('Y-m-d'),
                'weekday' => $day->format('l'),
                'party_size' => $party,
                'times' => $times,
                'more' => $more > 0 ? $more : null,
            ], fn ($v) => $v !== null),
            'message_for_model' => "{$service->name}, {$label}".($service->countsPeople() ? ", for {$party} ".($party === 1 ? 'person' : 'people') : '').': free at '.implode(', ', $times)
                .($more > 0 ? " (and {$more} more later — ask the customer for a time window)" : '').'.'
                .($assumed ? ' This assumed 1 person: ask how many people before booking.' : ''),
        ];
    }

    // ─────────────────────────────  Bookings  ─────────────────────────────

    /**
     * @param  list<int>  $serviceIds
     * @param  array<string, mixed>  $arguments
     */
    private function request(Tenant $tenant, array $serviceIds, Conversation $conversation, FlowState $flowState, FlowNode $node, array $arguments): array
    {
        if (! $conversation->contact_id) {
            return self::refuse('no_customer', 'This conversation has no customer to book for. Offer to pass it to the shop.');
        }

        $offered = $this->offered($tenant, $serviceIds)->get();
        $service = null;

        if (isset($arguments['service_id'])) {
            $service = $offered->firstWhere('id', (int) $arguments['service_id']);

            if (! $service) {
                return self::refuse('not_found', "There is no service with service_id {$arguments['service_id']}. Use list_reservation_services.");
            }
        } elseif ($offered->count() === 1) {
            $service = $offered->first();
        } elseif ($offered->count() > 1) {
            return self::refuse('service_required', 'Ask the customer which of these they want, then pass its service_id: '
                .$offered->map(fn (ReservableService $s) => "{$s->name} (service_id {$s->id})")->implode(', ').'.');
        }

        if ($service?->countsPeople() && ! isset($arguments['party_size'])) {
            return self::refuse('party_size_required', "{$service->name} is booked per person: ask the customer how many people, then call request_reservation with party_size.");
        }

        $start = $this->localStart($tenant, (string) $arguments['date'], (string) $arguments['time']);

        if (is_array($start)) {
            return $start;
        }

        $party = max(1, (int) ($arguments['party_size'] ?? 1));

        $outcome = $this->reservations->request(
            $conversation,
            $flowState,
            $node,
            $service,
            (string) ($arguments['service'] ?? ''),
            $start,
            $party,
            ['customer_name' => $arguments['customer_name'] ?? null, 'notes' => $arguments['notes'] ?? null],
            $this->unsureReason($arguments),
        );

        if (! $outcome['ok']) {
            return $this->refusal($service, $start, $party, (string) $outcome['error'], $tenant);
        }

        /** @var Reservation $reservation */
        $reservation = $outcome['reservation'];

        if ($outcome['existing'] ?? false) {
            return [
                'ok' => true,
                'result' => $this->payload($reservation, $tenant) + ['already_requested' => true],
                'message_for_model' => 'This reservation already exists: '.$this->describe($reservation, $tenant).'. Do not file it again.',
            ];
        }

        return $this->settled($reservation, $tenant, $conversation, $flowState, $node, 'Reservation filed: ');
    }

    private function mine(Tenant $tenant, Conversation $conversation): array
    {
        $reservations = $this->open($tenant, $conversation);

        if ($reservations->isEmpty()) {
            return ['ok' => true, 'result' => ['reservations' => []], 'message_for_model' => 'The customer has no open reservations.'];
        }

        $items = $reservations->map(fn (Reservation $r) => $this->payload($r, $tenant) + [
            'customer_can_change' => $this->reservations->customerChangeProblem($r) === null,
        ])->values()->all();

        return [
            'ok' => true,
            'result' => ['reservations' => $items],
            'message_for_model' => 'Open reservations: '.$reservations->map(fn (Reservation $r) => $this->describe($r, $tenant)." (reservation_id {$r->id})")->implode('; ').'.',
        ];
    }

    private function acceptOffered(Tenant $tenant, Conversation $conversation, FlowState $flowState, FlowNode $node, int $reservationId): array
    {
        $reservation = $this->customersReservation($tenant, $conversation, $reservationId);

        if (! $reservation) {
            return self::refuse('not_found', "reservation_id {$reservationId} is not one of this customer's reservations. Use my_reservations.");
        }

        $problem = $this->reservations->acceptOffer($reservation);

        if ($problem === ReservationAvailability::REASON_PAST) {
            return self::refuse('in_the_past', 'The time the shop offered has already passed. Ask the customer for another time and use reschedule_reservation.');
        }

        if ($problem === 'not_offered') {
            return self::refuse('not_offered', "Reservation {$reservation->code} has no offered time to accept (it is {$reservation->status->value}). Use my_reservations.");
        }

        if ($problem !== null) {
            return self::refuse($problem, 'The time the shop offered was taken in the meantime. Use check_availability for another time and reschedule_reservation.');
        }

        return $this->settled($reservation->refresh(), $tenant, $conversation, $flowState, $node, 'The customer took the offered time: ');
    }

    /** @param  array<string, mixed>  $arguments */
    private function reschedule(Tenant $tenant, Conversation $conversation, FlowState $flowState, FlowNode $node, array $arguments): array
    {
        $reservationId = (int) $arguments['reservation_id'];
        $reservation = $this->customersReservation($tenant, $conversation, $reservationId);

        if (! $reservation) {
            return self::refuse('not_found', "reservation_id {$reservationId} is not one of this customer's reservations. Use my_reservations.");
        }

        if ($problem = $this->changeRefusal($reservation)) {
            return $problem;
        }

        $start = $this->localStart($tenant, (string) $arguments['date'], (string) $arguments['time']);

        if (is_array($start)) {
            return $start;
        }

        $problem = $this->reservations->reschedule($reservation, $start, $this->unsureReason($arguments));

        if ($problem !== null) {
            return $this->refusal($reservation->service, $start, (int) $reservation->party_size, $problem, $tenant);
        }

        return $this->settled($reservation->refresh(), $tenant, $conversation, $flowState, $node, 'Moved: ');
    }

    private function cancel(Tenant $tenant, Conversation $conversation, FlowState $flowState, int $reservationId, ?string $reason): array
    {
        $reservation = $this->customersReservation($tenant, $conversation, $reservationId);

        if (! $reservation) {
            return self::refuse('not_found', "reservation_id {$reservationId} is not one of this customer's reservations. Use my_reservations.");
        }

        if ($problem = $this->changeRefusal($reservation)) {
            return $problem;
        }

        $this->reservations->cancel($reservation, ReservationService::BY_CUSTOMER, $reason);
        $this->flows->storeReservationVariables($flowState, $reservation->refresh());

        return [
            'ok' => true,
            'result' => $this->payload($reservation, $tenant),
            'message_for_model' => "Cancelled reservation {$reservation->code}. The shop has been told.",
        ];
    }

    // ─────────────────────────────  Helpers  ─────────────────────────────

    /**
     * What a filed (or moved, or accepted) reservation became, worded for the
     * model: the code to give when it is confirmed, the payment that went out
     * when it is held for one, "the shop will confirm" when it waits for them
     * — with "they are back later" when the team is off duty now.
     */
    private function settled(Reservation $reservation, Tenant $tenant, Conversation $conversation, FlowState $flowState, FlowNode $node, string $lead): array
    {
        $this->flows->storeReservationVariables($flowState, $reservation);
        $what = $this->describe($reservation, $tenant);

        if ($reservation->status === ReservationStatus::AwaitingPayment) {
            return $this->charge($reservation, $tenant, $conversation, $flowState, $node, $lead);
        }

        if ($reservation->status === ReservationStatus::Confirmed) {
            RunReservationConfirmedBranch::dispatch($reservation->id);

            return [
                'ok' => true,
                'result' => $this->payload($reservation, $tenant),
                'message_for_model' => "{$lead}{$what}. It is CONFIRMED. Give the customer the code {$reservation->code}.",
            ];
        }

        $why = match ($reservation->review_reason) {
            ReviewReason::PartyTooLarge => ' (more people than this service usually takes)',
            ReviewReason::AiUnsure => ' (you asked the shop to check it)',
            ReviewReason::PaymentUnavailable => ' (it asks for a payment of '.$reservation->formattedAmountDue().', which the shop will arrange)',
            default => '',
        };

        $offDuty = $conversation->connection && ! BusinessHours::isOpen($conversation->connection)
            ? ' The shop is outside working hours right now: say they will confirm when they are back.'
            : '';

        return [
            'ok' => true,
            'result' => $this->payload($reservation, $tenant) + ['team_available_now' => $offDuty === ''],
            'message_for_model' => "{$lead}{$what}. The time is held but NOT confirmed yet: the shop will check it{$why}. Tell the customer the shop will confirm and that you will let them know here — do not promise the time.{$offDuty}",
        ];
    }

    /** A reservation held for its payment: issue the charge, which goes to the customer by itself. */
    private function charge(Reservation $reservation, Tenant $tenant, Conversation $conversation, FlowState $flowState, FlowNode $node, string $lead): array
    {
        $existing = $reservation->latestPayment;

        if ($existing && $existing->status === FlowPaymentStatus::Pending) {
            return [
                'ok' => true,
                'result' => $this->payload($reservation, $tenant) + ['payment' => $existing->formattedAmount(), 'already_issued' => true],
                'message_for_model' => "{$lead}{$this->describe($reservation, $tenant)}. Its payment of {$existing->formattedAmount()} was already sent and is still open — do not charge again. It is confirmed as soon as it is paid.",
            ];
        }

        $payment = $this->flows->chargeReservation($flowState, $node, $reservation);
        $reservation->refresh();

        if ($payment->status === FlowPaymentStatus::Failed) {
            return [
                'ok' => true,
                'result' => $this->payload($reservation, $tenant) + ['payment_failed' => true],
                'message_for_model' => "{$lead}{$this->describe($reservation, $tenant)}. The payment it asks for could not be created, so the shop will check the reservation and arrange the payment. Tell the customer the shop will confirm and that you will let them know here — do not promise the time.",
            ];
        }

        if ($reservation->status === ReservationStatus::Confirmed) {
            RunReservationConfirmedBranch::dispatch($reservation->id);

            return [
                'ok' => true,
                'result' => $this->payload($reservation, $tenant),
                'message_for_model' => "{$lead}{$this->describe($reservation, $tenant)}. It is paid and CONFIRMED. Give the customer the code {$reservation->code}.",
            ];
        }

        $minutes = $payment->expires_at ? max(1, (int) round(now()->diffInMinutes($payment->expires_at))) : null;

        return [
            'ok' => true,
            'result' => $this->payload($reservation, $tenant) + array_filter([
                'payment' => $payment->formattedAmount(),
                'expires_in_minutes' => $minutes,
                'sent_to_customer' => true,
            ]),
            'message_for_model' => "{$lead}{$this->describe($reservation, $tenant)}. The time is held until it is paid: a payment of {$payment->formattedAmount()} confirms it, and the payment details were already sent to the customer in separate messages — do not repeat the code or the link. "
                .($minutes ? "Tell them it is valid for {$minutes} minutes and that the reservation is confirmed as soon as it is paid." : 'Tell them the reservation is confirmed as soon as it is paid.'),
        ];
    }

    /** @param  array<string, mixed>  $arguments */
    private function unsureReason(array $arguments): ?string
    {
        $flag = $arguments['needs_team_check'] ?? false;

        if (! in_array($flag, [true, 1, '1', 'true'], true)) {
            return null;
        }

        $reason = trim((string) ($arguments['reason'] ?? ''));

        return $reason !== '' ? $reason : 'The AI asked the shop to check this request.';
    }

    /** @param  list<int>  $serviceIds */
    private function offered(Tenant $tenant, array $serviceIds)
    {
        return ReservableService::forTenant($tenant->id)
            ->where('active', true)
            ->when($serviceIds !== [], fn ($q) => $q->whereIn('id', $serviceIds))
            ->with('tenant')
            ->orderBy('position')
            ->orderBy('name');
    }

    /** @return Collection<int, Reservation> */
    private function open(Tenant $tenant, Conversation $conversation): Collection
    {
        if (! $conversation->contact_id) {
            return collect();
        }

        return Reservation::forTenant($tenant->id)
            ->where('contact_id', $conversation->contact_id)
            ->open()
            ->with('service')
            ->orderBy('starts_at')
            ->limit(10)
            ->get();
    }

    private function customersReservation(Tenant $tenant, Conversation $conversation, int $id): ?Reservation
    {
        if (! $conversation->contact_id) {
            return null;
        }

        return Reservation::forTenant($tenant->id)
            ->where('contact_id', $conversation->contact_id)
            ->with(['service', 'tenant'])
            ->find($id);
    }

    private function changeRefusal(Reservation $reservation): ?array
    {
        return match ($this->reservations->customerChangeProblem($reservation)) {
            null => null,
            'not_upcoming' => self::refuse('not_upcoming', "Reservation {$reservation->code} is {$reservation->status->value} and can no longer be changed."),
            'paid' => self::refuse('paid', "Reservation {$reservation->code} is already paid: changing or cancelling it, and any refund, are handled by the shop. Offer to pass the customer to the shop."),
            'not_allowed' => self::refuse('not_allowed', "Reservation {$reservation->code} can only be moved or cancelled by the shop. Offer to pass the customer to the shop."),
            default => self::refuse('too_late', "Reservation {$reservation->code} can only be changed in the chat up to {$reservation->service?->cancel_until_hours} hours before it starts, and it is closer than that now. Offer to pass the customer to the shop."),
        };
    }

    /** @return CarbonImmutable|array refusal */
    private function localStart(Tenant $tenant, string $date, string $time): CarbonImmutable|array
    {
        $day = ReservationSchedule::parseDate($date, $tenant->displayTimezone());

        if ($day === null) {
            return self::refuse('invalid_date', "\"{$date}\" is not a date. Use YYYY-MM-DD.");
        }

        $minutes = ReservationSchedule::parseTime($time);

        if ($minutes === null) {
            return self::refuse('invalid_time', "\"{$time}\" is not a time. Use HH:MM (24-hour).");
        }

        return $day->addMinutes($minutes);
    }

    /** A time that could not be booked, with what is free instead. Nothing was written. */
    private function refusal(?ReservableService $service, CarbonImmutable $start, int $party, string $reason, Tenant $tenant): array
    {
        if ($reason === 'too_many_reservations') {
            return self::refuse($reason, 'This customer already has the most open reservations one customer may hold in the chat. Offer to pass them to the shop.');
        }

        if (! $service) {
            return self::refuse($reason, "{$start->format('Y-m-d H:i')} cannot be booked — ".$this->reasonPhrase($reason, null, $party, null).' Nothing was filed.');
        }

        $date = $start->format('Y-m-d');
        // The free times closest to the one asked for, in order: a customer
        // who wanted 20:00 is offered 19:00 and 21:00, not the morning.
        $asked = $start->hour * 60 + $start->minute;
        $times = in_array($reason, [ReservationAvailability::REASON_PAST, ReservationAvailability::REASON_TOO_FAR, ReservationAvailability::REASON_INACTIVE], true)
            ? []
            : collect($this->availability->day($service, $date, $party)['slots'])
                ->where('available', true)
                ->pluck('time')
                ->sortBy(fn (string $time) => abs((ReservationSchedule::parseTime($time) ?? 0) - $asked))
                ->take(8)
                ->sort()
                ->values()
                ->all();
        $next = $times === [] && ! in_array($reason, [ReservationAvailability::REASON_TOO_FAR, ReservationAvailability::REASON_INACTIVE], true)
            ? $this->availability->nextDates($service, $start->addDay()->format('Y-m-d'), $party)
            : [];

        $alternatives = match (true) {
            $times !== [] => ' Still free that day: '.implode(', ', $times).'.',
            $next !== [] => ' The next days with free times: '.$this->datesPhrase($next, $tenant->displayTimezone()).'.',
            default => '',
        };

        return self::refuse(
            $reason,
            "{$start->format('Y-m-d H:i')} cannot be booked — ".$this->reasonPhrase($reason, $service, $party, ReservationSchedule::overrideNote($service, $date)).' Nothing was filed.'.$alternatives,
            array_filter(['free_times' => $times ?: null, 'next_available_dates' => $next ?: null]),
        );
    }

    private function reasonPhrase(?string $reason, ?ReservableService $service, int $party, ?string $note): string
    {
        $timezone = $service?->tenant?->displayTimezone() ?? config('app.timezone', 'UTC');

        return match ($reason) {
            ReservationAvailability::REASON_CLOSED => 'the shop is closed then'.($note ? " ({$note})" : '').'.',
            ReservationAvailability::REASON_DAY_FULL => 'the shop is fully booked that day'.($note ? " ({$note})" : '').'.',
            ReservationAvailability::REASON_NOT_A_SLOT => 'that is not one of the offered start times (or it does not fit before closing). Use a time from check_availability.',
            ReservationAvailability::REASON_PAST => 'that time has already passed.',
            ReservationAvailability::REASON_TOO_SOON => 'reservations need '.$this->noticePhrase((int) $service?->min_notice_minutes, bare: true).' of notice.',
            ReservationAvailability::REASON_TOO_FAR => 'reservations are taken up to '.($service?->max_days_ahead ?? 0).' days ahead (until '.CarbonImmutable::now($timezone)->addDays((int) $service?->max_days_ahead)->format('Y-m-d').').',
            ReservationAvailability::REASON_PARTY_TOO_LARGE => "this service takes at most {$service?->maxPartySize()} people per reservation; {$party} is too many to check here. File the request anyway — the shop decides large groups.",
            ReservationAvailability::REASON_INACTIVE => 'this service is not taking reservations.',
            ReservationAvailability::REASON_FULL => 'that time is fully booked.',
            default => 'the shop\'s calendar does not allow it.',
        };
    }

    /** @return list<string> "2026-12-25: closed (Christmas)" — the upcoming special dates, for the model. */
    private function specialDates(ReservableService $service): array
    {
        $timezone = $this->availability->timezone($service);
        $today = CarbonImmutable::now($timezone)->format('Y-m-d');
        $last = CarbonImmutable::now($timezone)->addDays(max(0, (int) $service->max_days_ahead))->format('Y-m-d');

        return collect(ReservationSchedule::normalizeOverrides($service->date_overrides))
            ->filter(fn (array $o) => $o['date'] >= $today && $o['date'] <= $last)
            ->take(10)
            ->map(fn (array $o) => $o['date'].': '.match (true) {
                ($o['full'] ?? false) === true => 'fully booked',
                $o['ranges'] === [] => 'closed',
                default => 'open '.collect($o['ranges'])->map(fn ($r) => "{$r['open']}–{$r['close']}")->implode(', '),
            }.(isset($o['note']) ? " ({$o['note']})" : ''))
            ->values()
            ->all();
    }

    private function noticePhrase(int $minutes, bool $bare = false): string
    {
        $phrase = match (true) {
            $minutes <= 0 => 'no',
            $minutes % 1440 === 0 => ($minutes / 1440).' day(s)',
            $minutes % 60 === 0 => ($minutes / 60).' hour(s)',
            default => "{$minutes} minutes",
        };

        if ($bare) {
            return $phrase === 'no' ? 'no' : "at least {$phrase}";
        }

        return $minutes <= 0 ? 'any time ahead' : "at least {$phrase} ahead";
    }

    private function paymentPhrase(ReservableService $service, string $currency): ?string
    {
        if (! $service->asksForPayment()) {
            return null;
        }

        $amount = $service->payment_mode === ReservationPaymentMode::Deposit ? (int) $service->deposit_cents : (int) $service->price_cents;
        $kind = $service->payment_mode === ReservationPaymentMode::Deposit ? 'a deposit' : 'the full price';

        return "{$kind} of ".Money::format($amount, $currency).($service->countsPeople() ? ' per person' : '');
    }

    /** @param  list<string>  $dates */
    private function datesPhrase(array $dates, string $timezone): string
    {
        return collect($dates)->map(fn (string $d) => CarbonImmutable::parse($d, $timezone)->format('l Y-m-d'))->implode(', ');
    }

    /** "K7QF3M — Mesa, Saturday 2026-11-07 19:00, 6 people (confirmed)" */
    private function describe(Reservation $reservation, Tenant $tenant): string
    {
        $timezone = $tenant->displayTimezone();
        $start = $reservation->localStart($timezone);
        $status = match ($reservation->status) {
            ReservationStatus::Pending => ' (waiting for the shop to confirm)',
            ReservationStatus::Offered => ' (the shop offered '.($reservation->localOffered($timezone)?->format('l Y-m-d H:i') ?? 'another time').' instead — waiting for the customer)',
            ReservationStatus::AwaitingPayment => ' (held until '.$reservation->formattedAmountDue().' is paid)',
            ReservationStatus::Confirmed => ' (confirmed'.($reservation->amount_due_cents > 0 && $reservation->isPaid() ? ', paid' : '').')',
            default => " ({$reservation->status->value})",
        };

        return "{$reservation->code} — {$reservation->service_name}, {$start->format('l Y-m-d H:i')}, {$reservation->party_size} "
            .($reservation->party_size === 1 ? 'person' : 'people').$status;
    }

    /** @return array<string, mixed> */
    private function payload(Reservation $reservation, Tenant $tenant): array
    {
        $timezone = $tenant->displayTimezone();
        $start = $reservation->localStart($timezone);
        $offered = $reservation->localOffered($timezone);

        return array_filter([
            'reservation_id' => $reservation->id,
            'code' => $reservation->code,
            'service' => $reservation->service_name,
            'date' => $start->format('Y-m-d'),
            'weekday' => $start->format('l'),
            'time' => $start->format('H:i'),
            'party_size' => (int) $reservation->party_size,
            'status' => $reservation->status->value,
            'offered_date' => $offered?->format('Y-m-d'),
            'offered_time' => $offered?->format('H:i'),
            'amount_due' => $reservation->amount_due_cents > 0 ? $reservation->formattedAmountDue() : null,
            'paid' => $reservation->amount_due_cents > 0 ? $reservation->isPaid() : null,
        ], fn ($value) => $value !== null);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array{ok: false, result: array<string, mixed>, message_for_model: string}
     */
    private static function refuse(string $code, string $message, array $extra = []): array
    {
        return [
            'ok' => false,
            'result' => array_merge(['error' => $code], $extra),
            'message_for_model' => $message,
        ];
    }
}

<?php

namespace App\Services\Reservations;

use App\Enums\Conversation\Status as ConversationStatus;
use App\Enums\Flow\FlowPaymentStatus;
use App\Enums\Flow\NodeType;
use App\Enums\Reservation\ReservationSource;
use App\Enums\Reservation\ReservationStatus;
use App\Enums\Reservation\ReviewReason;
use App\Events\ConversationUpdated;
use App\Events\ReservationAttention;
use App\Events\ReservationUpdated;
use App\Exceptions\UserFacingException;
use App\Jobs\AnnounceReservationDecision;
use App\Jobs\RemindReservationReview;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\FlowNode;
use App\Models\FlowPayment;
use App\Models\FlowState;
use App\Models\ReservableService;
use App\Models\Reservation;
use App\Models\User;
use App\Services\Contact\ContactIdentity;
use App\Services\Conversation\SystemMessage;
use App\Services\Flow\AiToolNodes;
use App\Services\Flow\PaymentNodes;
use App\Services\Money\MarketMoney;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The calendar of a workspace — writing bookings down, answering requests,
 * moving and calling them off — for the AI in a conversation and for the
 * team in the dashboard alike.
 *
 * Three rules everything here serves:
 *
 *  - **one calendar.** The AI's bookings and the ones the team wrote down for
 *    a phone call are the same rows, so a time taken at the desk is taken for
 *    the AI too. That is what makes confirming by itself safe: a time the
 *    calendar shows free is free.
 *  - **a time is checked under the service's row lock, then written.** Two
 *    customers asking for the last table in the same second both see it
 *    free; only the first to take the lock gets it, the second is told it is
 *    full.
 *  - **no amount comes from the model.** What a booking has to pay is read
 *    from the service (ReservableService::amountDueFor), never from the tool
 *    call that asked for it.
 *
 * A request is confirmed on the spot when its service confirms by itself and
 * nothing gives pause (a large group, the AI unsure). A service that asks for
 * money holds the slot while its charge runs and confirms when it is paid.
 * Everything else waits for an operator, who answers from the conversation or
 * the calendar — accept, decline, offer another time — and the AI tells the
 * customer (FlowExecutor::announceReservationDecision).
 *
 * Every change writes a note in the thread (rendered by the dashboard's
 * `lib/infoMessage.ts`) and refreshes the inbox marker on the customer's
 * conversations (ContactResource.reservation).
 */
final class ReservationService
{
    public const INFO_BOOKED = 'reservation_booked';

    public const INFO_REQUESTED = 'reservation_requested';

    public const INFO_AWAITING_PAYMENT = 'reservation_awaiting_payment';

    public const INFO_CONFIRMED = 'reservation_confirmed';

    public const INFO_DECLINED = 'reservation_declined';

    public const INFO_OFFERED = 'reservation_offered';

    public const INFO_RESCHEDULED = 'reservation_rescheduled';

    public const INFO_CANCELLED = 'reservation_cancelled';

    public const INFO_EXPIRED = 'reservation_expired';

    public const INFO_PAID = 'reservation_paid';

    public const INFO_PAID_LATE = 'reservation_paid_late';

    public const INFO_PAYMENT_NOT_CREATED = 'reservation_payment_not_created';

    /** Who called a reservation off. */
    public const BY_CUSTOMER = 'customer';

    public const BY_TEAM = 'team';

    public const BY_SYSTEM = 'system';

    /** What an operator can answer a request with. */
    public const ACTION_CONFIRM = 'confirm';

    public const ACTION_DECLINE = 'decline';

    public const ACTION_OFFER = 'offer';

    public const ACTION_CANCEL = 'cancel';

    public const ACTIONS = [self::ACTION_CONFIRM, self::ACTION_DECLINE, self::ACTION_OFFER, self::ACTION_CANCEL];

    /** What the AI tells the customer after the money settled. */
    public const ANNOUNCE_PAID = 'paid';

    public const ANNOUNCE_EXPIRED = 'expired';

    /** Statuses the team may set by hand on the calendar. */
    public const TEAM_STATUSES = ['confirmed', 'completed', 'no_show'];

    /** Letters and digits nobody misreads over the phone: no 0/O, 1/I/L, 2/Z, 5/S, 8/B. */
    private const CODE_ALPHABET = 'ACDEFGHJKMNPQRTUVWXY34679';

    public function __construct(private ReservationAvailability $availability) {}

    // ─────────────────────────────  The AI  ─────────────────────────────

    /**
     * File a reservation for the customer of a conversation (the AI's tool).
     *
     * A time the calendar refuses (closed, full, outside the hours, too
     * soon…) is refused — nothing is written, and the caller offers what is
     * free. Otherwise the status follows the service: confirmed on the spot;
     * `awaiting_payment` when it asks for money this node can charge (the
     * caller then issues the charge — FlowExecutor::chargeReservation);
     * `pending` with a reason when the team has to look at it.
     *
     * @param  array{customer_name?: ?string, notes?: ?string}  $details
     * @return array{ok: bool, error?: string, reservation?: Reservation, existing?: bool}
     */
    public function request(
        Conversation $conversation,
        ?FlowState $flowState,
        ?FlowNode $node,
        ?ReservableService $service,
        string $serviceLabel,
        CarbonImmutable $localStart,
        int $partySize,
        array $details,
        ?string $unsureReason = null,
    ): array {
        $contact = $conversation->contact;
        $tenantId = (int) ($service?->tenant_id ?? $conversation->connection?->tenant_id);
        $label = $service?->name ?? (trim($serviceLabel) !== '' ? mb_substr(trim($serviceLabel), 0, 120) : 'Reservation');
        $unsure = $this->unsure($unsureReason);
        $partySize = max(1, $partySize);

        if (! $service && $localStart->isPast()) {
            return ['ok' => false, 'error' => ReservationAvailability::REASON_PAST];
        }

        $amountDue = $service?->amountDueFor($partySize) ?? 0;
        $review = $this->reviewFor($service, $partySize, $unsure);
        $canCharge = $node !== null && self::nodeCharges($node);

        $status = match (true) {
            $review !== null => ReservationStatus::Pending,
            $amountDue > 0 && $canCharge => ReservationStatus::AwaitingPayment,
            $amountDue > 0 => ReservationStatus::Pending,
            default => ReservationStatus::Confirmed,
        };

        if ($status === ReservationStatus::Pending && $review === null) {
            $review = ReviewReason::PaymentUnavailable;
        }

        $identity = ContactIdentity::for($contact);
        $name = trim((string) ($details['customer_name'] ?? '')) ?: (ContactIdentity::name($contact) ?? $contact?->name);
        $duration = $service ? max(1, (int) $service->duration_minutes) : (int) config('reservations.default_duration_minutes', 60);

        $outcome = DB::transaction(function () use ($conversation, $flowState, $node, $service, $label, $tenantId, $localStart, $partySize, $details, $review, $unsure, $identity, $name, $duration, $status, $amountDue) {
            if ($service) {
                ReservableService::whereKey($service->id)->lockForUpdate()->first();
            }

            // One request at a time per customer: a model that calls the tool
            // twice in a row must find the first one, not race it.
            Contact::whereKey($conversation->contact_id)->lockForUpdate()->first();

            // Asked twice for the same thing — a retry, a model that called the
            // tool again — is the same request, not a second one.
            $existing = Reservation::where('tenant_id', $tenantId)
                ->where('contact_id', $conversation->contact_id)
                ->where('starts_at', $localStart->utc())
                ->where(fn ($q) => $service ? $q->where('reservable_service_id', $service->id) : $q->whereNull('reservable_service_id'))
                ->whereIn('status', ReservationStatus::openValues())
                ->first();

            if ($existing) {
                return ['ok' => true, 'reservation' => $existing, 'existing' => true];
            }

            $open = Reservation::where('tenant_id', $tenantId)
                ->where('contact_id', $conversation->contact_id)
                ->open()
                ->count();

            if ($open >= max(1, (int) config('reservations.max_upcoming_per_contact', 5))) {
                return ['ok' => false, 'error' => 'too_many_reservations'];
            }

            // A party larger than the service takes is the team's to judge, so
            // its size is not held against it here — the capacity still is.
            if ($service && ($problem = $this->availability->problem($service, $localStart, $partySize, checkParty: false))) {
                return ['ok' => false, 'error' => $problem];
            }

            $reservation = Reservation::create([
                'tenant_id' => $tenantId,
                'reservable_service_id' => $service?->id,
                'service_name' => $label,
                'code' => $this->newCode($tenantId),
                'status' => $status,
                'source' => ReservationSource::Ai,
                'starts_at' => $localStart->utc(),
                'ends_at' => $localStart->addMinutes($duration)->utc(),
                'party_size' => $partySize,
                'customer_name' => $name ? mb_substr($name, 0, 160) : null,
                'customer_phone' => $identity['phone'] ?? null,
                'customer_email' => $identity['email'] ?? null,
                'notes' => ($notes = trim((string) ($details['notes'] ?? ''))) !== '' ? mb_substr($notes, 0, 1000) : null,
                'review_reason' => $review,
                'review_note' => $review === ReviewReason::AiUnsure ? $unsure : null,
                'contact_id' => $conversation->contact_id,
                'conversation_id' => $conversation->id,
                'flow_id' => $flowState?->flow_id,
                'flow_node_id' => $node?->id,
                'amount_due_cents' => $amountDue,
                'currency' => $this->currencyFor($tenantId, $service),
                // Held while the charge is created; the charge's own deadline
                // replaces this once it exists (holdForPayment).
                'hold_expires_at' => $status === ReservationStatus::AwaitingPayment ? now()->addMinutes($this->holdMinutes($node)) : null,
                'confirmed_at' => $status === ReservationStatus::Confirmed ? now() : null,
            ]);

            return ['ok' => true, 'reservation' => $reservation];
        });

        if (! $outcome['ok'] || ($outcome['existing'] ?? false)) {
            return $outcome;
        }

        /** @var Reservation $reservation */
        $reservation = $outcome['reservation'];

        match ($reservation->status) {
            ReservationStatus::Confirmed => $this->note($reservation, self::INFO_BOOKED),
            ReservationStatus::AwaitingPayment => $this->note($reservation, self::INFO_AWAITING_PAYMENT, ['amount_cents' => $reservation->amount_due_cents, 'currency' => $reservation->currency]),
            default => $this->note($reservation, self::INFO_REQUESTED, ['review_reason' => $reservation->review_reason?->value]),
        };

        if ($reservation->status === ReservationStatus::Pending) {
            $this->needsReview($reservation);
        }

        $this->changed($reservation);

        Log::info('ReservationService: the AI took a reservation', [
            'reservation_id' => $reservation->id,
            'tenant_id' => $tenantId,
            'status' => $reservation->status->value,
            'review_reason' => $reservation->review_reason?->value,
        ]);

        return $outcome;
    }

    /**
     * The customer took the time the team offered (the AI's tool). The time
     * was checked when the team named it, but the calendar may have filled
     * since, so it is checked again — the size and the customer's notice
     * rules are the team's call and are not.
     *
     * @return ?string why not; null when taken
     */
    public function acceptOffer(Reservation $reservation): ?string
    {
        $service = $reservation->service;

        $problem = DB::transaction(function () use ($reservation, $service) {
            if ($service) {
                ReservableService::whereKey($service->id)->lockForUpdate()->first();
            }

            $locked = Reservation::whereKey($reservation->id)->lockForUpdate()->first();

            if (! $locked || $locked->status !== ReservationStatus::Offered || ! $locked->offered_starts_at) {
                return 'not_offered';
            }

            if ($locked->offered_starts_at->isPast()) {
                return ReservationAvailability::REASON_PAST;
            }

            if ($service && ($problem = $this->availability->problem(
                $service,
                $locked->offered_starts_at->setTimezone($this->availability->timezone($service)),
                (int) $locked->party_size,
                $locked->id,
                rules: false,
                onGrid: false,
                checkParty: false,
            ))) {
                return $problem;
            }

            $locked->forceFill($this->confirmedAt($locked, $locked->offered_starts_at, $this->chargeFor($locked)))->save();

            return null;
        });

        if ($problem !== null) {
            return $problem;
        }

        $reservation->refresh();
        $this->note($reservation, $reservation->status === ReservationStatus::AwaitingPayment ? self::INFO_AWAITING_PAYMENT : self::INFO_CONFIRMED, ['by_customer' => true]);
        $this->changed($reservation);

        return null;
    }

    /**
     * The customer wants another time (the AI's tool). Checked like a new
     * booking — against everything else, not against itself — and settled the
     * same way: a time the team has not seen is no more certain because it
     * replaces one they had. Money already paid stays paid.
     *
     * @return ?string why not; null when moved
     */
    public function reschedule(Reservation $reservation, CarbonImmutable $localStart, ?string $unsureReason = null): ?string
    {
        $service = $reservation->service;
        $unsure = $this->unsure($unsureReason);

        if (! $service && $localStart->isPast()) {
            return ReservationAvailability::REASON_PAST;
        }

        $problem = DB::transaction(function () use ($reservation, $service, $localStart, $unsure) {
            if ($service) {
                ReservableService::whereKey($service->id)->lockForUpdate()->first();

                if ($problem = $this->availability->problem($service, $localStart, (int) $reservation->party_size, $reservation->id, checkParty: false)) {
                    return $problem;
                }
            }

            $locked = Reservation::whereKey($reservation->id)->lockForUpdate()->first();
            $review = $this->reviewFor($service, (int) $locked->party_size, $unsure);
            $charge = $this->chargeFor($locked);

            $status = match (true) {
                $review !== null => ReservationStatus::Pending,
                $locked->owesPayment() && $charge => ReservationStatus::AwaitingPayment,
                $locked->owesPayment() => ReservationStatus::Pending,
                default => ReservationStatus::Confirmed,
            };

            $locked->forceFill([
                'status' => $status,
                'starts_at' => $localStart->utc(),
                'ends_at' => $localStart->addMinutes($locked->duration())->utc(),
                'offered_starts_at' => null,
                'review_reason' => $status === ReservationStatus::Pending ? ($review ?? ReviewReason::PaymentUnavailable) : null,
                'review_note' => $review === ReviewReason::AiUnsure ? $unsure : null,
                'decided_by_user_id' => null,
                'decided_at' => null,
                'decision_note' => null,
                'notify_status' => null,
                'notify_reason' => null,
                'reminded_at' => null,
                // An unpaid charge keeps running for the new time; a fresh one
                // gets the node's own window.
                'hold_expires_at' => $status === ReservationStatus::AwaitingPayment
                    ? ($locked->status === ReservationStatus::AwaitingPayment && $locked->hold_expires_at ? $locked->hold_expires_at : now()->addMinutes($this->holdMinutes($charge)))
                    : null,
                'confirmed_at' => $status === ReservationStatus::Confirmed ? ($locked->confirmed_at ?? now()) : null,
            ])->save();

            return null;
        });

        if ($problem !== null) {
            return $problem;
        }

        $reservation->refresh();
        $this->note($reservation, self::INFO_RESCHEDULED);

        if ($reservation->status === ReservationStatus::Pending) {
            $this->needsReview($reservation);
        }

        $this->changed($reservation);

        return null;
    }

    /**
     * Whether the customer may still move or call off this reservation in the
     * chat. Null when they may; the reason otherwise.
     *
     * A request the team has not confirmed is still the customer's to change.
     * A confirmed one follows the service's "until X hours before", and one
     * already paid is the team's: a refund is a person's decision.
     */
    public function customerChangeProblem(Reservation $reservation): ?string
    {
        if (! $reservation->status->isOpen() || $reservation->starts_at->isPast()) {
            return 'not_upcoming';
        }

        if ($reservation->isPaid() && $reservation->amount_due_cents > 0) {
            return 'paid';
        }

        if ($reservation->status !== ReservationStatus::Confirmed || ! $reservation->reservable_service_id) {
            return null;
        }

        $hours = $reservation->service?->cancel_until_hours;

        if ($hours === null) {
            return 'not_allowed';
        }

        if ($reservation->starts_at->lessThan(now()->addHours($hours))) {
            return 'too_late';
        }

        return null;
    }

    // ─────────────────────────────  The team  ─────────────────────────────

    /**
     * An operator answers a request: confirm, decline, offer another time, or
     * call off one already in play.
     *
     * Confirming one that is `offered` confirms the offered time — the
     * customer agreed to it some other way (a phone call), and the time on the
     * card is the one the team put there. Confirming one whose service asks
     * for money the node can charge holds it for payment instead: the charge
     * goes out with the AI's message, and the money confirms it.
     *
     * The customer is told by the AI when it still holds the conversation;
     * otherwise `notify_status` says the operator has to.
     *
     * @throws UserFacingException reservation_not_open, offer_time_required, offer_in_the_past
     */
    public function decide(Reservation $reservation, User $user, string $action, ?CarbonImmutable $offerAt = null, ?string $note = null): Reservation
    {
        $note = $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 500) : null;

        DB::transaction(function () use ($reservation, $user, $action, $offerAt, $note) {
            $locked = Reservation::whereKey($reservation->id)->lockForUpdate()->firstOrFail();

            $allowed = $action === self::ACTION_CANCEL ? $locked->status->isOpen() : $locked->status->awaitsDecision();

            if (! $allowed || $locked->starts_at->isPast()) {
                throw new UserFacingException(__('reservations.not_open'), 422, 'reservation_not_open');
            }

            $attributes = [
                'decided_by_user_id' => $user->id,
                'decided_at' => now(),
                'decision_note' => $note,
                'notify_status' => $locked->conversation_id ? Reservation::NOTIFY_QUEUED : null,
                'notify_reason' => null,
            ];

            match ($action) {
                self::ACTION_CONFIRM => $attributes += $this->confirmedAt(
                    $locked,
                    $locked->status === ReservationStatus::Offered ? $locked->offered_starts_at : null,
                    $this->chargeFor($locked),
                ),
                self::ACTION_DECLINE => $attributes += ['status' => ReservationStatus::Declined, 'hold_expires_at' => null],
                self::ACTION_OFFER => $attributes += $this->offered($offerAt),
                self::ACTION_CANCEL => $attributes += [
                    'status' => ReservationStatus::Cancelled,
                    'cancelled_at' => now(),
                    'cancelled_by' => self::BY_TEAM,
                    'cancellation_reason' => $note !== null ? mb_substr($note, 0, 255) : null,
                    'hold_expires_at' => null,
                ],
            };

            $locked->forceFill($attributes)->save();
        });

        $reservation->refresh();

        $by = ['by' => $user->name];

        match ($action) {
            self::ACTION_CONFIRM => $this->note($reservation, $reservation->status === ReservationStatus::AwaitingPayment ? self::INFO_AWAITING_PAYMENT : self::INFO_CONFIRMED, $by),
            self::ACTION_DECLINE => $this->note($reservation, self::INFO_DECLINED, $by + ['note' => $note]),
            self::ACTION_OFFER => $this->note($reservation, self::INFO_OFFERED, $by + $this->whenParams($reservation, $reservation->offered_starts_at)),
            self::ACTION_CANCEL => $this->note($reservation, self::INFO_CANCELLED, ['cancelled_by' => self::BY_TEAM] + $by),
        };

        $this->changed($reservation);

        if ($reservation->notify_status === Reservation::NOTIFY_QUEUED) {
            AnnounceReservationDecision::dispatch($reservation->id, $action)->afterCommit();
        }

        Log::info('ReservationService: the team answered a reservation', [
            'reservation_id' => $reservation->id,
            'action' => $action,
            'user_id' => $user->id,
            'status' => $reservation->status->value,
        ]);

        return $reservation;
    }

    /**
     * Write down a booking that came by phone or at the door.
     *
     * The team is held to the hours and the capacity — the dashboard says
     * "that time is full" — but not to the customer's rules (notice, how far
     * ahead), and `$force` is how they take a booking anyway: they know about
     * the extra chair that the calendar does not.
     *
     * @param  array<string, mixed>  $details  customer_name, customer_phone, customer_email, notes, status, amount_due_cents, paid
     *
     * @throws SlotUnavailable
     */
    public function createManual(ReservableService $service, CarbonImmutable $localStart, int $partySize, array $details, User $user, bool $force): Reservation
    {
        $status = ($details['status'] ?? null) === ReservationStatus::Pending->value ? ReservationStatus::Pending : ReservationStatus::Confirmed;

        $reservation = DB::transaction(function () use ($service, $localStart, $partySize, $details, $user, $force, $status) {
            ReservableService::whereKey($service->id)->lockForUpdate()->first();

            if (! $force && ($problem = $this->availability->problem($service, $localStart, $partySize, rules: false, onGrid: false))) {
                throw new SlotUnavailable($problem);
            }

            $paid = (bool) ($details['paid'] ?? false);

            return Reservation::create(array_merge($this->contactFields($details), [
                'tenant_id' => $service->tenant_id,
                'reservable_service_id' => $service->id,
                'service_name' => $service->name,
                'code' => $this->newCode($service->tenant_id),
                'status' => $status,
                'source' => ReservationSource::Manual,
                'starts_at' => $localStart->utc(),
                'ends_at' => $localStart->addMinutes((int) $service->duration_minutes)->utc(),
                'party_size' => $partySize,
                'review_reason' => $status === ReservationStatus::Pending ? ReviewReason::NeedsConfirmation : null,
                'created_by_user_id' => $user->id,
                'amount_due_cents' => array_key_exists('amount_due_cents', $details) && $details['amount_due_cents'] !== null
                    ? max(0, (int) $details['amount_due_cents'])
                    : $service->amountDueFor($partySize),
                'currency' => $this->currencyFor((int) $service->tenant_id, $service),
                'paid_at' => $paid ? now() : null,
                'confirmed_at' => $status === ReservationStatus::Confirmed ? now() : null,
            ]));
        });

        $this->announce($reservation);

        return $reservation;
    }

    /**
     * The team edits a booking: who, how many, when, at which service.
     *
     * A change of time, size or service is checked like a new booking —
     * against everything else, not against itself — unless `$force`.
     *
     * @param  array<string, mixed>  $details
     *
     * @throws SlotUnavailable
     */
    public function updateManual(Reservation $reservation, ReservableService $service, CarbonImmutable $localStart, int $partySize, array $details, bool $force): Reservation
    {
        $previousStart = $reservation->starts_at;

        DB::transaction(function () use ($reservation, $service, $localStart, $partySize, $details, $force) {
            ReservableService::whereKey($service->id)->lockForUpdate()->first();

            $moved = $service->id !== $reservation->reservable_service_id
                || ! $localStart->utc()->equalTo($reservation->starts_at)
                || $partySize !== (int) $reservation->party_size;

            if ($moved && ! $force && $reservation->status->isOpen()
                && ($problem = $this->availability->problem($service, $localStart, $partySize, $reservation->id, rules: false, onGrid: false))) {
                throw new SlotUnavailable($problem);
            }

            $attributes = array_merge($this->contactFields($details), [
                'reservable_service_id' => $service->id,
                'service_name' => $service->id !== $reservation->reservable_service_id ? $service->name : $reservation->service_name,
                'starts_at' => $localStart->utc(),
                'ends_at' => $localStart->addMinutes($service->id !== $reservation->reservable_service_id ? (int) $service->duration_minutes : $reservation->duration())->utc(),
                'party_size' => $partySize,
            ]);

            if (array_key_exists('amount_due_cents', $details) && $details['amount_due_cents'] !== null) {
                $attributes['amount_due_cents'] = max(0, (int) $details['amount_due_cents']);
            }

            if (array_key_exists('paid', $details)) {
                $attributes['paid_at'] = $details['paid'] ? ($reservation->paid_at ?? now()) : null;
            }

            $reservation->fill($attributes)->save();
        });

        $reservation->refresh();

        if (! $reservation->starts_at->equalTo($previousStart)) {
            $this->note($reservation, self::INFO_RESCHEDULED);
        }

        $this->changed($reservation);

        return $reservation;
    }

    /**
     * The team records how a booking went, or confirms one by hand.
     *
     * Requests waiting on a decision are answered through decide(), which
     * tells the customer; this is the calendar's own bookkeeping. Bringing back
     * one that was called off takes its place again, so the time is checked
     * first — unless `$force`.
     *
     * @throws SlotUnavailable
     */
    public function setStatus(Reservation $reservation, ReservationStatus $to, bool $force = false): void
    {
        if ($to === $reservation->status) {
            return;
        }

        DB::transaction(function () use ($reservation, $to, $force) {
            $service = $reservation->service;

            if ($reservation->status->isClosed() && $service && ! $force) {
                ReservableService::whereKey($service->id)->lockForUpdate()->first();

                $problem = $this->availability->problem(
                    $service,
                    $reservation->localStart($this->availability->timezone($service)),
                    (int) $reservation->party_size,
                    $reservation->id,
                    rules: false,
                    onGrid: false,
                );

                // A booking whose time has passed is being recorded, not taken.
                if ($problem !== null && $problem !== ReservationAvailability::REASON_PAST) {
                    throw new SlotUnavailable($problem);
                }
            }

            $reservation->forceFill([
                'status' => $to,
                'hold_expires_at' => null,
                'review_reason' => null,
                'offered_starts_at' => null,
                'confirmed_at' => $to === ReservationStatus::Confirmed ? ($reservation->confirmed_at ?? now()) : $reservation->confirmed_at,
                'cancelled_at' => null,
                'cancelled_by' => null,
                'cancellation_reason' => null,
            ])->save();
        });

        if ($to === ReservationStatus::Confirmed) {
            $this->note($reservation, self::INFO_CONFIRMED);
        }

        $this->changed($reservation);
    }

    /**
     * Call it off. The slot is free from this moment. A customer's
     * cancellation is announced to the team: a refund, or a seat the kitchen
     * was holding, is theirs to deal with.
     */
    public function cancel(Reservation $reservation, string $by, ?string $reason = null): void
    {
        if (! $reservation->status->isOpen()) {
            return;
        }

        $reservation->forceFill([
            'status' => ReservationStatus::Cancelled,
            'cancelled_at' => now(),
            'cancelled_by' => $by,
            'cancellation_reason' => $reason !== null && trim($reason) !== '' ? mb_substr(trim($reason), 0, 255) : null,
            'hold_expires_at' => null,
        ])->save();

        $this->note($reservation, self::INFO_CANCELLED, ['cancelled_by' => $by]);
        $this->changed($reservation);

        if ($by === self::BY_CUSTOMER) {
            $this->attention($reservation, ReservationAttention::KIND_CANCELLED);
        }
    }

    // ─────────────────────────────  The money  ─────────────────────────────

    /** The charge exists: the slot is held until the charge's own deadline. */
    public function holdForPayment(Reservation $reservation, FlowPayment $payment): void
    {
        $reservation->forceFill([
            'hold_expires_at' => $payment->expires_at ?? $reservation->hold_expires_at,
        ])->save();

        $this->announce($reservation);
    }

    /**
     * A reservation that should have been charged could not be — the gateway
     * refused, or nobody holds the conversation to send the charge in.
     *
     * Back to the team, not off the calendar: the customer asked, the time is
     * held, and only the money is missing. A request the team already
     * accepted stays accepted and unpaid; the card says so.
     */
    public function paymentNotCreated(Reservation $reservation, string $reason): void
    {
        $accepted = $reservation->decided_at !== null;

        $reservation->forceFill([
            'status' => $accepted ? ReservationStatus::Confirmed : ReservationStatus::Pending,
            'review_reason' => ReviewReason::PaymentUnavailable,
            'review_note' => mb_substr($reason, 0, 255),
            'hold_expires_at' => null,
            'confirmed_at' => $accepted ? ($reservation->confirmed_at ?? now()) : null,
        ])->save();

        $this->note($reservation, self::INFO_PAYMENT_NOT_CREATED, ['reason' => mb_substr($reason, 0, 255)]);

        if (! $accepted) {
            $this->needsReview($reservation);
        }

        $this->changed($reservation);
    }

    /**
     * The gateway settled a charge for this reservation. Called from
     * FlowPaymentService::settle(), which runs once per charge.
     *
     * Paid confirms it — even when the money arrived after the hold ran out,
     * as long as the time is still free; when somebody else took it in the
     * meantime, it stays expired with the payment recorded and a note,
     * because confirming it would double-book the place. Unpaid frees the
     * slot, but only the newest charge decides. Either way the AI tells the
     * customer (announceReservationDecision).
     */
    public function syncFromPayment(FlowPayment $payment): void
    {
        $reservation = $payment->reservation_id ? Reservation::with('service')->find($payment->reservation_id) : null;

        if (! $reservation) {
            return;
        }

        $paid = $payment->status === FlowPaymentStatus::Paid || $payment->paid_at !== null;

        if (! $paid) {
            $latest = $reservation->payments()->first();

            if (($latest && $latest->id !== $payment->id) || $reservation->status !== ReservationStatus::AwaitingPayment) {
                return;
            }

            $reservation->forceFill([
                'status' => ReservationStatus::Expired,
                'hold_expires_at' => null,
                'notify_status' => $reservation->conversation_id ? Reservation::NOTIFY_QUEUED : null,
            ])->save();

            $this->note($reservation, self::INFO_EXPIRED);
            $this->changed($reservation);
            $this->queueAnnouncement($reservation, self::ANNOUNCE_EXPIRED);

            return;
        }

        $outcome = DB::transaction(function () use ($reservation, $payment) {
            $locked = Reservation::whereKey($reservation->id)->lockForUpdate()->first();

            if (! $locked || ($locked->isPaid() && $locked->status === ReservationStatus::Confirmed)) {
                return null;
            }

            $paidAt = $payment->paid_at ?? now();

            if (in_array($locked->status, [ReservationStatus::Cancelled, ReservationStatus::Declined], true)) {
                // Called off, then paid anyway: the money is real and somebody
                // has to give it back. Recorded, and the thread says so.
                $locked->forceFill(['paid_at' => $paidAt])->save();

                return self::INFO_PAID_LATE;
            }

            if ($locked->status === ReservationStatus::Expired && $reservation->service) {
                ReservableService::whereKey($reservation->service->id)->lockForUpdate()->first();

                $problem = $this->availability->problem(
                    $reservation->service,
                    $locked->localStart($this->availability->timezone($reservation->service)),
                    (int) $locked->party_size,
                    $locked->id,
                    rules: false,
                    onGrid: false,
                    checkParty: false,
                );

                if ($problem !== null) {
                    $locked->forceFill(['paid_at' => $paidAt])->save();

                    return self::INFO_PAID_LATE;
                }
            }

            $locked->forceFill([
                'status' => in_array($locked->status, [ReservationStatus::Completed, ReservationStatus::NoShow], true) ? $locked->status : ReservationStatus::Confirmed,
                'paid_at' => $paidAt,
                'confirmed_at' => $locked->confirmed_at ?? now(),
                'hold_expires_at' => null,
                'review_reason' => null,
                'notify_status' => $locked->conversation_id ? Reservation::NOTIFY_QUEUED : null,
                'notify_reason' => null,
            ])->save();

            return self::INFO_PAID;
        });

        if ($outcome === null) {
            return;
        }

        $reservation->refresh();
        $this->note($reservation, $outcome, ['amount_cents' => $payment->amount_cents, 'currency' => $payment->currency]);
        $this->changed($reservation);

        if ($outcome === self::INFO_PAID_LATE) {
            $this->attention($reservation, ReservationAttention::KIND_PAID_LATE);
        } else {
            $this->queueAnnouncement($reservation, self::ANNOUNCE_PAID);
        }

        Log::info('ReservationService: a reservation was paid', [
            'reservation_id' => $reservation->id,
            'flow_payment_id' => $payment->id,
            'status' => $reservation->status->value,
            'outcome' => $outcome,
        ]);
    }

    /**
     * The payment settings of the node that took this reservation, when that
     * node can charge it — what decides between "confirmed" and "awaiting
     * payment" for a booking that owes money.
     *
     * @return array<string, mixed>|null
     */
    public function chargeFor(Reservation $reservation): ?array
    {
        if (! $reservation->owesPayment() || ! $reservation->flow_node_id || ! $reservation->conversation_id) {
            return null;
        }

        $node = FlowNode::find($reservation->flow_node_id);

        return $node && self::nodeCharges($node) ? AiToolNodes::paymentConfig($node->data ?? []) : null;
    }

    /** Whether an "AI agent with actions" node has a payment account to charge reservations with. */
    public static function nodeCharges(FlowNode $node): bool
    {
        return $node->type === NodeType::AiTools && AiToolNodes::capabilities($node->data ?? [])['reservation_payment'];
    }

    // ─────────────────────────────  Bookkeeping  ─────────────────────────────

    /** One reminder for a request still waiting on the team (RemindReservationReview). */
    public function remindIfStillPending(int $reservationId): void
    {
        $claimed = Reservation::whereKey($reservationId)
            ->where('status', ReservationStatus::Pending->value)
            ->whereNull('reminded_at')
            ->where('starts_at', '>', now())
            ->update(['reminded_at' => now()]);

        if ($claimed !== 1) {
            return;
        }

        $reservation = Reservation::find($reservationId);

        if ($reservation) {
            $this->attention($reservation, ReservationAttention::KIND_REMINDER);
        }
    }

    /** Record whether the customer was told (FlowExecutor::announceReservationDecision). */
    public function markNotified(Reservation $reservation, string $status, ?string $reason = null): void
    {
        $reservation->forceFill(['notify_status' => $status, 'notify_reason' => $reason])->save();

        $this->announce($reservation);
    }

    /**
     * Refresh everything that shows this reservation: the calendar and open
     * cards, and the marker on the customer's conversations — live, and for a
     * dashboard that was closed (the delta sync reads `updated_at`).
     */
    public function changed(Reservation $reservation): void
    {
        $this->announce($reservation);

        if (! $reservation->contact_id) {
            return;
        }

        try {
            // Mass update, like contact tags: no observer, no status note —
            // the conversation did not change, what is drawn on it did.
            Conversation::where('contact_id', $reservation->contact_id)->update(['updated_at' => now()]);

            Conversation::with('contact')
                ->where('contact_id', $reservation->contact_id)
                ->where('status', '!=', ConversationStatus::Resolved->value)
                ->latest('id')
                ->limit(5)
                ->get()
                ->each(fn (Conversation $conversation) => broadcast(new ConversationUpdated($conversation)));
        } catch (\Throwable $e) {
            Log::warning('ReservationService: could not refresh the conversations showing a reservation', [
                'reservation_id' => $reservation->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function confirmedAt(Reservation $reservation, ?CarbonImmutable $start, ?array $charge): array
    {
        $attributes = [
            'status' => $charge !== null ? ReservationStatus::AwaitingPayment : ReservationStatus::Confirmed,
            'confirmed_at' => $charge !== null ? null : now(),
            'offered_starts_at' => null,
            // Held from now: the charge goes out with the AI's message, and
            // its own deadline replaces this once it exists.
            'hold_expires_at' => $charge !== null ? now()->addMinutes($this->holdMinutes($charge)) : null,
        ];

        if ($start !== null) {
            $attributes['starts_at'] = $start;
            $attributes['ends_at'] = $start->addMinutes($reservation->duration());
        }

        return $attributes;
    }

    /** @return array<string, mixed> */
    private function offered(?CarbonImmutable $offerAt): array
    {
        if ($offerAt === null) {
            throw new UserFacingException(__('reservations.offer_time_required'), 422, 'offer_time_required');
        }

        if ($offerAt->isPast()) {
            throw new UserFacingException(__('reservations.offer_in_the_past'), 422, 'offer_in_the_past');
        }

        return ['status' => ReservationStatus::Offered, 'offered_starts_at' => $offerAt->utc(), 'hold_expires_at' => null];
    }

    /** Why the team has to look at a request, or null when nothing gives pause. */
    private function reviewFor(?ReservableService $service, int $partySize, ?string $unsure): ?ReviewReason
    {
        return match (true) {
            $service === null => ReviewReason::NoSchedule,
            $partySize > $service->maxPartySize() => ReviewReason::PartyTooLarge,
            $unsure !== null => ReviewReason::AiUnsure,
            ! $service->auto_confirm => ReviewReason::NeedsConfirmation,
            default => null,
        };
    }

    private function unsure(?string $reason): ?string
    {
        return $reason !== null && trim($reason) !== '' ? mb_substr(trim($reason), 0, 255) : null;
    }

    /**
     * How long a booking waiting for its charge keeps the slot before the
     * charge exists: the node's own payment window.
     *
     * @param  FlowNode|array<string, mixed>|null  $source
     */
    private function holdMinutes(FlowNode|array|null $source): int
    {
        $config = $source instanceof FlowNode ? AiToolNodes::paymentConfig($source->data ?? []) : ($source ?? []);

        return max(5, (int) ($config['expires_in_minutes'] ?? PaymentNodes::expiresInMinutes([])));
    }

    private function currencyFor(int $tenantId, ?ReservableService $service): string
    {
        $tenant = $service?->tenant ?? \App\Models\Tenant::find($tenantId);

        return $tenant?->currency() ?? MarketMoney::baseCurrency();
    }

    /** A new request waits for the team: tell them, and remind them once. */
    private function needsReview(Reservation $reservation): void
    {
        $this->attention($reservation, ReservationAttention::KIND_REQUESTED);

        $minutes = (int) config('reservations.review_reminder_minutes', 15);

        // A `sync` queue runs a delayed job at once: the reminder would land
        // with the request it reminds of.
        if ($minutes > 0 && config('queue.default') !== 'sync') {
            RemindReservationReview::dispatch($reservation->id)->delay(now()->addMinutes($minutes))->afterCommit();
        }
    }

    private function queueAnnouncement(Reservation $reservation, string $action): void
    {
        if ($reservation->notify_status === Reservation::NOTIFY_QUEUED) {
            AnnounceReservationDecision::dispatch($reservation->id, $action)->afterCommit();
        }
    }

    private function attention(Reservation $reservation, string $kind): void
    {
        if (! $reservation->conversation_id) {
            return;
        }

        try {
            broadcast(new ReservationAttention($reservation, $kind));
        } catch (\Throwable $e) {
            Log::warning('ReservationService: could not broadcast the reservation notice', [
                'reservation_id' => $reservation->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $details
     * @return array<string, mixed>
     */
    private function contactFields(array $details): array
    {
        $fields = [];

        foreach (['customer_name' => 160, 'customer_phone' => 40, 'customer_email' => 190, 'notes' => 1000] as $key => $max) {
            if (array_key_exists($key, $details)) {
                $value = trim((string) ($details[$key] ?? ''));
                $fields[$key] = $value !== '' ? mb_substr($value, 0, $max) : null;
            }
        }

        return $fields;
    }

    private function newCode(int $tenantId): string
    {
        $alphabet = self::CODE_ALPHABET;
        $last = strlen($alphabet) - 1;

        for ($attempt = 0; $attempt < 12; $attempt++) {
            $code = '';

            for ($i = 0; $i < 6; $i++) {
                $code .= $alphabet[random_int(0, $last)];
            }

            if (! Reservation::where('tenant_id', $tenantId)->where('code', $code)->exists()) {
                return $code;
            }
        }

        // Twelve collisions in a row means a workspace with millions of
        // reservations: one more letter is the cheap way out.
        return $code.$alphabet[random_int(0, $last)].$alphabet[random_int(0, $last)];
    }

    /** @return array{date: string, time: string} */
    private function whenParams(Reservation $reservation, ?CarbonImmutable $at): array
    {
        $local = ($at ?? $reservation->starts_at)->setTimezone($reservation->timezone());

        return ['date' => $local->format('Y-m-d'), 'time' => $local->format('H:i')];
    }

    /**
     * A note in the conversation the reservation was made in. Nothing for one
     * the team wrote down — there is no thread to put it in.
     *
     * @param  array<string, mixed>  $extra
     */
    private function note(Reservation $reservation, string $code, array $extra = []): void
    {
        $conversation = $reservation->conversation;

        if (! $conversation) {
            return;
        }

        $when = $this->whenParams($reservation, null);
        $people = "{$reservation->party_size} ".($reservation->party_size === 1 ? 'person' : 'people');
        $offered = $this->whenParams($reservation, $reservation->offered_starts_at);
        $amount = $reservation->amount_due_cents > 0 ? $reservation->formattedAmountDue() : '';

        $body = match ($code) {
            self::INFO_BOOKED => "Reservation {$reservation->code} confirmed: {$reservation->service_name}, {$when['date']} {$when['time']}, {$people}.",
            self::INFO_REQUESTED => "Reservation request {$reservation->code}: {$reservation->service_name}, {$when['date']} {$when['time']}, {$people} — waiting for the team.",
            self::INFO_AWAITING_PAYMENT => "Reservation {$reservation->code}: {$reservation->service_name}, {$when['date']} {$when['time']}, {$people} — held until {$amount} is paid.",
            self::INFO_CONFIRMED => "Reservation {$reservation->code} confirmed.",
            self::INFO_DECLINED => "Reservation request {$reservation->code} declined.",
            self::INFO_OFFERED => "Another time offered for reservation {$reservation->code}: {$offered['date']} {$offered['time']}.",
            self::INFO_RESCHEDULED => "Reservation {$reservation->code} moved to {$when['date']} {$when['time']}.",
            self::INFO_CANCELLED => "Reservation {$reservation->code} cancelled.",
            self::INFO_EXPIRED => "Reservation {$reservation->code} was released: it was not paid in time.",
            self::INFO_PAID => "Reservation {$reservation->code} paid and confirmed.",
            self::INFO_PAID_LATE => "Reservation {$reservation->code} was paid after it had been released. Check the calendar and refund if needed.",
            self::INFO_PAYMENT_NOT_CREATED => "The payment for reservation {$reservation->code} could not be created.",
            default => "Reservation {$reservation->code} updated.",
        };

        $params = array_filter(array_merge([
            'code' => $reservation->code,
            'service' => $reservation->service_name,
            'date' => $when['date'],
            'time' => $when['time'],
            'party_size' => (int) $reservation->party_size,
            'status' => $reservation->status->value,
        ], $extra), fn ($value) => $value !== null);

        try {
            SystemMessage::info($conversation, $body, $code, $params);
        } catch (\Throwable $e) {
            Log::warning('ReservationService: could not write the note in the thread', [
                'reservation_id' => $reservation->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** Tell every open calendar and card — ids and status only, no customer data. */
    private function announce(Reservation $reservation): void
    {
        try {
            broadcast(new ReservationUpdated($reservation));
        } catch (\Throwable $e) {
            Log::warning('ReservationService: could not broadcast the reservation update', [
                'reservation_id' => $reservation->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}

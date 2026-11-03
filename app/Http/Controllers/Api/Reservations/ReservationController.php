<?php

namespace App\Http\Controllers\Api\Reservations;

use App\Enums\Reservation\ReservationSource;
use App\Enums\Reservation\ReservationStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Reservations\ReservationResource;
use App\Models\Conversation;
use App\Models\ReservableService;
use App\Models\Reservation;
use App\Services\Catalog\PriceParser;
use App\Services\Reservations\ReservationCalendar;
use App\Services\Reservations\ReservationSchedule;
use App\Services\Reservations\ReservationService;
use App\Services\Reservations\SlotUnavailable;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The calendar: every booking, the AI's and the team's — and the team's
 * answer to the ones waiting on them.
 *
 * The team writes down the bookings that never went through the chat — a
 * phone call, a walk-in — so the AI stops offering those times. A time that is
 * full or outside the hours is refused with `code: slot_unavailable` and the
 * reason; sending the same request with `force: true` takes it anyway.
 *
 * A request the AI took is answered with decision() — from the calendar or
 * from the conversation's card — which lets the AI tell the customer.
 */
class ReservationController extends Controller
{
    /** How many answered or past reservations the conversation card shows under the open ones. */
    private const HISTORY = 5;

    public function __construct(private readonly ReservationService $reservations) {}

    public function index(Request $request)
    {
        $tenantId = (int) $request->user()->tenant_id;
        $timezone = $this->timezone($request);

        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'status' => ['nullable', 'array'],
            'status.*' => [Rule::enum(ReservationStatus::class)],
            'service_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:200'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);

        $from = ReservationSchedule::parseDate($data['from'] ?? CarbonImmutable::now($timezone)->format('Y-m-d'), $timezone);
        $to = ReservationSchedule::parseDate($data['to'] ?? $from->addDays(6)->format('Y-m-d'), $timezone);

        // A range is a page of the calendar, not an export: past this it is a
        // request for everything.
        if ($from->diffInDays($to) > 92) {
            $to = $from->addDays(92);
        }

        $reservations = Reservation::forTenant($tenantId)
            ->where('starts_at', '>=', $from->utc())
            ->where('starts_at', '<', $to->addDay()->utc())
            ->when($data['status'] ?? null, fn ($q, $statuses) => $q->whereIn('status', $statuses))
            ->when($data['service_id'] ?? null, fn ($q, $id) => $q->where('reservable_service_id', $id))
            ->when($data['search'] ?? null, function ($q, $search) {
                $like = '%'.mb_strtolower(trim($search)).'%';
                $q->where(fn ($w) => $w
                    ->whereRaw('LOWER(code) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(customer_name) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(customer_phone) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(service_name) LIKE ?', [$like]));
            })
            ->with(['latestPayment', 'tenant'])
            ->orderBy('starts_at')
            ->orderBy('id')
            ->paginate($data['per_page'] ?? 300);

        return ReservationResource::collection($reservations)->additional([
            'summary' => $this->summary($tenantId, $timezone),
            'range' => $this->rangeInfo($from, $to, $timezone),
        ]);
    }

    /**
     * The calendar as counts — every service × every day of the range: its
     * opening hours, how many bookings, how full. What the week grid and the
     * day timeline's row headers read; the bookings themselves are not sent.
     */
    public function calendar(Request $request, ReservationCalendar $calendar)
    {
        $tenantId = (int) $request->user()->tenant_id;
        $timezone = $this->timezone($request);

        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'service_id' => ['nullable', 'array'],
            'service_id.*' => ['integer'],
        ]);

        $from = ReservationSchedule::parseDate($data['from'] ?? CarbonImmutable::now($timezone)->format('Y-m-d'), $timezone);
        $to = ReservationSchedule::parseDate($data['to'] ?? $from->addDays(6)->format('Y-m-d'), $timezone);

        if ($from->diffInDays($to) >= ReservationCalendar::MAX_DAYS) {
            $to = $from->addDays(ReservationCalendar::MAX_DAYS - 1);
        }

        $services = ReservableService::forTenant($tenantId)
            ->when($data['service_id'] ?? null, fn ($q, $ids) => $q->whereIn('id', $ids))
            ->with('tenant')
            ->orderBy('position')
            ->orderBy('name')
            ->get();

        return response()->json([
            'services' => $calendar->build($services, $from, $to, $timezone),
            'summary' => $this->summary($tenantId, $timezone),
            'range' => $this->rangeInfo($from, $to, $timezone),
        ]);
    }

    public function show(Request $request, int $id)
    {
        return new ReservationResource($this->find($request, $id)->load($this->relations()));
    }

    public function store(Request $request)
    {
        $data = $this->validated($request, creating: true);
        $service = $this->service($request, (int) $data['service_id']);

        try {
            $reservation = $this->reservations->createManual(
                $service,
                $this->localStart($request, $data['date'], $data['time']),
                (int) ($data['party_size'] ?? 1),
                $data,
                $request->user(),
                (bool) ($data['force'] ?? false),
            );
        } catch (SlotUnavailable $e) {
            return $this->unavailable($e);
        }

        return (new ReservationResource($reservation->load($this->relations())))->response()->setStatusCode(201);
    }

    public function update(Request $request, int $id)
    {
        $reservation = $this->find($request, $id);
        $data = $this->validated($request, creating: false);
        $timezone = $this->timezone($request);

        $service = isset($data['service_id']) ? $this->service($request, (int) $data['service_id']) : $reservation->service;

        if (! $service) {
            throw ValidationException::withMessages(['service_id' => __('reservations.service_required')]);
        }

        $start = $reservation->localStart($timezone);
        $localStart = $this->localStart(
            $request,
            $data['date'] ?? $start->format('Y-m-d'),
            $data['time'] ?? $start->format('H:i'),
        );

        try {
            $reservation = $this->reservations->updateManual(
                $reservation,
                $service,
                $localStart,
                (int) ($data['party_size'] ?? $reservation->party_size),
                $data,
                (bool) ($data['force'] ?? false),
            );
        } catch (SlotUnavailable $e) {
            return $this->unavailable($e);
        }

        return new ReservationResource($reservation->load($this->relations()));
    }

    /**
     * The calendar's own bookkeeping: confirm by hand, record how it went,
     * bring back one that was called off. A request the AI took that is still
     * waiting on the team is confirmed through decision() instead, so the
     * customer hears about it.
     */
    public function status(Request $request, int $id)
    {
        $reservation = $this->find($request, $id);
        $data = $request->validate([
            'status' => ['required', Rule::in(ReservationService::TEAM_STATUSES)],
            'force' => ['sometimes', 'boolean'],
        ]);

        $to = ReservationStatus::from($data['status']);

        try {
            if ($to === ReservationStatus::Confirmed && $reservation->status->awaitsDecision() && $reservation->conversation_id) {
                $this->reservations->decide($reservation, $request->user(), ReservationService::ACTION_CONFIRM);
            } else {
                $this->reservations->setStatus($reservation, $to, (bool) ($data['force'] ?? false));
            }
        } catch (SlotUnavailable $e) {
            return $this->unavailable($e);
        }

        return new ReservationResource($reservation->refresh()->load($this->relations()));
    }

    /**
     * Answer a reservation: confirm, decline, offer another time, or call it
     * off. From the calendar or from the conversation's card — whoever may
     * manage the calendar, or whoever can read the thread it came from.
     */
    public function decision(Request $request, int $id)
    {
        $data = $request->validate([
            'action' => ['required', 'string', Rule::in(ReservationService::ACTIONS)],
            'date' => ['nullable', 'required_if:action,offer', 'date_format:Y-m-d'],
            'time' => ['nullable', 'required_if:action,offer', 'date_format:H:i'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $reservation = $this->find($request, $id);

        if (! $request->user()->can('reservations.manage') && ($refusal = $this->conversationRefusal($reservation, $request))) {
            return $refusal;
        }

        $offerAt = null;

        if ($data['action'] === ReservationService::ACTION_OFFER) {
            $offerAt = $this->localStart($request, $data['date'], $data['time']);
        }

        $reservation = $this->reservations->decide(
            $reservation,
            $request->user(),
            $data['action'],
            $offerAt,
            $data['note'] ?? null,
        );

        return new ReservationResource($reservation->load($this->relations()));
    }

    /**
     * Only the team's own entries: a reservation the AI took has a
     * conversation that mentions it, and maybe a payment — it is cancelled,
     * not erased.
     */
    public function destroy(Request $request, int $id)
    {
        $reservation = $this->find($request, $id);

        if ($reservation->source !== ReservationSource::Manual) {
            return response()->json([
                'message' => __('reservations.only_manual_delete'),
                'code' => 'only_manual_delete',
            ], 422);
        }

        $reservation->delete();

        return response()->noContent();
    }

    /** The conversation's customer's reservations: the open ones first, then a short history. */
    public function forConversation(Request $request, int $id)
    {
        $user = $request->user();
        $conversation = Conversation::visibleTo($user)->findOrFail($id);

        if ($refusal = $this->exclusiveRefusal($conversation, $request)) {
            return $refusal;
        }

        if (! $conversation->contact_id) {
            return ReservationResource::collection(collect());
        }

        $base = fn () => Reservation::forTenant((int) $user->tenant_id)
            ->where('contact_id', $conversation->contact_id)
            ->with($this->relations());

        $open = $base()->open()->orderBy('starts_at')->get();
        $history = $base()
            ->whereNotIn('id', $open->pluck('id'))
            ->latest('updated_at')
            ->limit(self::HISTORY)
            ->get();

        return ReservationResource::collection($open->concat($history));
    }

    /** @return list<string> */
    private function relations(): array
    {
        return ['latestPayment', 'createdBy', 'decidedBy', 'tenant'];
    }

    /** @return array<string, int> */
    private function summary(int $tenantId, string $timezone): array
    {
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $going = [ReservationStatus::Pending->value, ReservationStatus::AwaitingPayment->value, ReservationStatus::Confirmed->value];

        $count = fn (CarbonImmutable $from, CarbonImmutable $to) => Reservation::forTenant($tenantId)
            ->whereIn('status', $going)
            ->where('starts_at', '>=', $from->utc())
            ->where('starts_at', '<', $to->utc())
            ->count();

        $ahead = fn (ReservationStatus $status) => Reservation::forTenant($tenantId)
            ->where('status', $status->value)
            ->where('starts_at', '>=', now())
            ->count();

        return [
            'today' => $count($today, $today->addDay()),
            'next_7_days' => $count($today, $today->addDays(7)),
            'pending' => $ahead(ReservationStatus::Pending),
            'offered' => $ahead(ReservationStatus::Offered),
            'awaiting_payment' => $ahead(ReservationStatus::AwaitingPayment),
        ];
    }

    /**
     * The page draws the calendar in the business's clock, not the
     * browser's: "today" and "now" are the workspace's.
     *
     * @return array<string, mixed>
     */
    private function rangeInfo(CarbonImmutable $from, CarbonImmutable $to, string $timezone): array
    {
        $now = CarbonImmutable::now($timezone);

        return [
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
            'today' => $now->format('Y-m-d'),
            'now_minutes' => $now->hour * 60 + $now->minute,
            'timezone' => $timezone,
        ];
    }

    private function unavailable(SlotUnavailable $e): JsonResponse
    {
        return response()->json([
            'message' => $e->getMessage(),
            'code' => 'slot_unavailable',
            'reason' => $e->reason,
            'errors' => ['time' => [$e->getMessage()]],
        ], 422);
    }

    private function find(Request $request, int $id): Reservation
    {
        return Reservation::forTenant((int) $request->user()->tenant_id)->with(['tenant', 'service'])->findOrFail($id);
    }

    private function service(Request $request, int $id): ReservableService
    {
        $service = ReservableService::forTenant((int) $request->user()->tenant_id)->with('tenant')->find($id);

        if (! $service) {
            throw ValidationException::withMessages(['service_id' => __('reservations.service_required')]);
        }

        return $service;
    }

    private function timezone(Request $request): string
    {
        return $request->user()->tenant?->displayTimezone() ?? config('app.timezone', 'UTC');
    }

    private function localStart(Request $request, string $date, string $time): CarbonImmutable
    {
        $day = ReservationSchedule::parseDate($date, $this->timezone($request));
        $minutes = ReservationSchedule::parseTime($time);

        if ($day === null) {
            throw ValidationException::withMessages(['date' => __('reservations.invalid_date')]);
        }

        if ($minutes === null) {
            throw ValidationException::withMessages(['time' => __('reservations.invalid_time')]);
        }

        return $day->addMinutes($minutes);
    }

    /** A reservation answers to the thread it came from: no thread you can read, no answer. */
    private function conversationRefusal(Reservation $reservation, Request $request): ?JsonResponse
    {
        if (! $reservation->conversation_id) {
            abort(403);
        }

        $conversation = Conversation::visibleTo($request->user())->find($reservation->conversation_id);

        if (! $conversation) {
            abort(404);
        }

        return $this->exclusiveRefusal($conversation, $request);
    }

    private function exclusiveRefusal(Conversation $conversation, Request $request): ?JsonResponse
    {
        if ($conversation->isReadableBy($request->user())) {
            return null;
        }

        return response()->json([
            'message' => __('reservations.conversation_exclusive'),
            'code' => 'conversation_exclusive',
            'agent' => $conversation->agent?->name,
        ], 403);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'service_id' => [$required, 'integer'],
            'date' => [$required, 'date_format:Y-m-d'],
            'time' => [$required, 'string', 'max:5'],
            'party_size' => ['sometimes', 'integer', 'min:1', 'max:10000'],
            'customer_name' => [$required, 'string', 'max:160'],
            'customer_phone' => ['sometimes', 'nullable', 'string', 'max:40'],
            'customer_email' => ['sometimes', 'nullable', 'email', 'max:190'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'status' => ['sometimes', Rule::in(['pending', 'confirmed'])],
            'amount_due_cents' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:'.PriceParser::MAX_CENTS],
            'paid' => ['sometimes', 'boolean'],
            'force' => ['sometimes', 'boolean'],
        ]);
    }
}

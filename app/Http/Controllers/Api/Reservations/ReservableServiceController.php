<?php

namespace App\Http\Controllers\Api\Reservations;

use App\Enums\Reservation\CapacityUnit;
use App\Enums\Reservation\ReservationPaymentMode;
use App\Http\Controllers\Controller;
use App\Http\Resources\Reservations\ReservableServiceResource;
use App\Models\ReservableService;
use App\Services\Catalog\PriceParser;
use App\Services\Reservations\ReservationAvailability;
use App\Services\Reservations\ReservationSchedule;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * What customers can book — a table, a haircut, a court — with its hours,
 * length, capacity, whether it confirms by itself and what it asks for up
 * front. The "AI agent with actions" node offers these and nothing else.
 */
class ReservableServiceController extends Controller
{
    public function __construct(private readonly ReservationAvailability $availability) {}

    public function index(Request $request)
    {
        $services = ReservableService::forTenant((int) $request->user()->tenant_id)
            ->with('tenant')
            ->withCount('upcomingReservations')
            ->orderBy('position')
            ->orderBy('name')
            ->get();

        return ReservableServiceResource::collection($services);
    }

    public function show(Request $request, int $id)
    {
        return new ReservableServiceResource($this->find($request, $id)->loadCount('upcomingReservations'));
    }

    public function store(Request $request)
    {
        $tenantId = (int) $request->user()->tenant_id;
        $data = $this->validated($request, creating: true);

        $service = ReservableService::create(array_merge($data, [
            'tenant_id' => $tenantId,
            'position' => (int) ReservableService::forTenant($tenantId)->max('position') + 1,
        ]));

        // Refreshed: the columns the request left out take their database
        // defaults, which the freshly created model does not know yet.
        return (new ReservableServiceResource($service->refresh()->loadCount('upcomingReservations')))->response()->setStatusCode(201);
    }

    public function update(Request $request, int $id)
    {
        $service = $this->find($request, $id);
        $service->fill($this->validated($request, creating: false))->save();

        return new ReservableServiceResource($service->refresh()->loadCount('upcomingReservations'));
    }

    /**
     * Deleted only when nobody is booked on it: the bookings would survive
     * (they keep the service's name) but nothing could check their time any
     * more. Switching it off is the way to stop new bookings.
     */
    public function destroy(Request $request, int $id)
    {
        $service = $this->find($request, $id);

        if ($service->upcomingReservations()->exists()) {
            return response()->json([
                'message' => __('reservations.service_has_upcoming'),
                'code' => 'service_has_upcoming',
            ], 422);
        }

        $service->delete();

        return response()->noContent();
    }

    /**
     * The slots of one day, for the team's booking form: every start time,
     * whether it is free, how many places are left. Without the customer's
     * rules (notice, how far ahead) — the team is not held to them.
     */
    public function availability(Request $request, int $id)
    {
        $service = $this->find($request, $id);
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'party_size' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'ignore' => ['nullable', 'integer'],
        ]);

        $day = $this->availability->day($service, $data['date'], (int) ($data['party_size'] ?? 1), $data['ignore'] ?? null, rules: false);

        return response()->json([
            'date' => $day['date'],
            'open' => $day['open'],
            'reason' => $day['reason'],
            'note' => $day['note'],
            'slots' => array_map(fn (array $slot) => [
                'time' => $slot['time'],
                'available' => $slot['available'],
                'remaining' => $slot['remaining'],
                'reason' => $slot['reason'],
            ], $day['slots']),
        ]);
    }

    private function find(Request $request, int $id): ReservableService
    {
        return ReservableService::forTenant((int) $request->user()->tenant_id)->with('tenant')->findOrFail($id);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';
        $money = ['nullable', 'integer', 'min:0', 'max:'.PriceParser::MAX_CENTS];

        $data = $request->validate([
            'name' => [$required, 'string', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'active' => ['sometimes', 'boolean'],
            'duration_minutes' => [$required, 'integer', 'min:5', 'max:1440'],
            'slot_interval_minutes' => ['sometimes', 'nullable', 'integer', 'min:5', 'max:1440'],
            'capacity' => [$required, 'integer', 'min:1', 'max:10000'],
            'capacity_unit' => ['sometimes', Rule::enum(CapacityUnit::class)],
            'max_party_size' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:10000'],
            'weekly_hours' => ['sometimes', 'nullable', 'array'],
            'date_overrides' => ['sometimes', 'nullable', 'array', 'max:120'],
            'min_notice_minutes' => ['sometimes', 'integer', 'min:0', 'max:43200'],
            'max_days_ahead' => ['sometimes', 'integer', 'min:1', 'max:365'],
            'auto_confirm' => ['sometimes', 'boolean'],
            'cancel_until_hours' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:720'],
            'payment_mode' => ['sometimes', Rule::enum(ReservationPaymentMode::class)],
            'price_cents' => array_merge(['sometimes'], $money),
            'deposit_cents' => array_merge(['sometimes'], $money),
        ]);

        if (array_key_exists('weekly_hours', $data)) {
            $data['weekly_hours'] = ReservationSchedule::normalizeWeekly($data['weekly_hours']);
        }

        if (array_key_exists('date_overrides', $data)) {
            $data['date_overrides'] = ReservationSchedule::normalizeOverrides($data['date_overrides']);
        }

        // What the payment mode charges has to be there — read from the
        // request, or from the row when this request does not change it.
        $existing = $creating ? null : ReservableService::forTenant((int) $request->user()->tenant_id)->find((int) $request->route('id'));
        $mode = ReservationPaymentMode::tryFrom((string) ($data['payment_mode'] ?? $existing?->payment_mode?->value ?? 'none'));
        $deposit = array_key_exists('deposit_cents', $data) ? $data['deposit_cents'] : $existing?->deposit_cents;
        $price = array_key_exists('price_cents', $data) ? $data['price_cents'] : $existing?->price_cents;

        if ($mode === ReservationPaymentMode::Deposit && (int) $deposit <= 0) {
            throw ValidationException::withMessages(['deposit_cents' => __('reservations.deposit_required')]);
        }

        if ($mode === ReservationPaymentMode::Full && (int) $price <= 0) {
            throw ValidationException::withMessages(['price_cents' => __('reservations.price_required')]);
        }

        return $data;
    }
}

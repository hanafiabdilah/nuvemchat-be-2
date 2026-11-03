<?php

namespace App\Services\Reservations;

use App\Enums\Reservation\ReservationStatus;
use App\Models\ReservableService;
use App\Models\Reservation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The schedule as numbers: for every service and every day of a range, when it
 * opens, how many bookings it holds and how full it is.
 *
 * What the schedule's overviews are drawn from — the week grid (services ×
 * days) and the row headers of the day timeline. A count per cell rather than
 * the bookings themselves, because a busy restaurant's week is a thousand rows
 * nobody needs to download to see that Saturday is full.
 *
 * "Full" is place-minutes: each booking takes its duration times its weight
 * (one place, or its party when the service counts people), against the
 * capacity times the minutes the service is open that day. Over 100% happens —
 * the team can write down a booking the schedule had no room for — and is
 * reported as it is.
 */
final class ReservationCalendar
{
    /** Past this a range is an export, not a page of the schedule. */
    public const MAX_DAYS = 42;

    public function __construct(private ReservationAvailability $availability) {}

    /**
     * @param  Collection<int, ReservableService>  $services
     * @return list<array{id: int, days: array<string, array<string, mixed>>}>
     */
    public function build(Collection $services, CarbonImmutable $from, CarbonImmutable $to, string $timezone): array
    {
        $days = [];
        for ($day = $from; $day->lessThanOrEqualTo($to) && count($days) < self::MAX_DAYS; $day = $day->addDay()) {
            $days[] = $day;
        }

        $bookings = Reservation::query()
            ->whereIn('reservable_service_id', $services->pluck('id'))
            ->where('starts_at', '>=', $from->utc())
            ->where('starts_at', '<', $to->addDay()->utc())
            ->holdingCapacity()
            ->get(['id', 'reservable_service_id', 'status', 'starts_at', 'ends_at', 'party_size'])
            ->groupBy(fn (Reservation $r) => $r->reservable_service_id.'|'.$r->starts_at->setTimezone($timezone)->format('Y-m-d'));

        $result = [];

        foreach ($services as $service) {
            $perDay = [];

            foreach ($days as $day) {
                $date = $day->format('Y-m-d');
                $ranges = ReservationSchedule::rangesOn($service, $day);
                $openMinutes = array_sum(array_map(fn ($range) => $range[1] - $range[0], $ranges));
                $rows = $bookings->get($service->id.'|'.$date, collect());

                $used = $rows->sum(fn (Reservation $r) => max(0, (int) round(($r->ends_at->getTimestamp() - $r->starts_at->getTimestamp()) / 60))
                    * $this->availability->weight($service, (int) $r->party_size));
                $capacity = $openMinutes * max(1, (int) $service->capacity);

                $perDay[$date] = [
                    'ranges' => array_map(fn ($range) => [$range[0], $range[1]], $ranges),
                    'note' => ReservationSchedule::overrideNote($service, $date),
                    'bookings' => $rows->count(),
                    'people' => (int) $rows->sum('party_size'),
                    'pending' => $rows->where('status', ReservationStatus::Pending)->count(),
                    'awaiting_payment' => $rows->where('status', ReservationStatus::AwaitingPayment)->count(),
                    'full' => ReservationSchedule::isFullOn($service, $date),
                    'used_minutes' => (int) $used,
                    'capacity_minutes' => $capacity,
                    'utilization' => $capacity > 0 ? round($used / $capacity, 3) : null,
                ];
            }

            $result[] = ['id' => $service->id, 'days' => $perDay];
        }

        return $result;
    }
}

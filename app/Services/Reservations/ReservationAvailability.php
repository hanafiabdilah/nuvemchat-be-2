<?php

namespace App\Services\Reservations;

use App\Models\ReservableService;
use App\Models\Reservation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Which times of a service are free.
 *
 * A slot is a start time on the service's grid — every `interval` minutes
 * from the opening of a range, as long as the whole booking fits before the
 * close. It is free when, at every moment of it, what is already booked plus
 * this booking stays within the capacity. "At every moment" is the point of
 * the sweep in peakLoad(): two bookings that touch the slot but not each other
 * (09:00–10:00 and 10:00–11:00, against a slot at 09:30) take one place at a
 * time, not two.
 *
 * Every booking counts, whoever wrote it — the AI's and the ones the team
 * typed in for a phone call are the same rows (Reservation::holdingCapacity).
 *
 * The booking rules (minimum notice, how far ahead) are the customer's rules:
 * the AI is held to them, the team is not — `$rules = false` — because the
 * person at the desk taking a walk-in for in ten minutes is not breaking any.
 *
 * Reasons fall in two kinds. Everything here is certain from the calendar
 * alone and refuses a request on the spot — except a party larger than the
 * service takes, which the team may still fit (ReservationService sends that
 * one to them: ReviewReason::PartyTooLarge).
 */
final class ReservationAvailability
{
    public const REASON_INACTIVE = 'inactive';

    public const REASON_CLOSED = 'closed';

    public const REASON_NOT_A_SLOT = 'not_a_slot';

    public const REASON_PAST = 'in_the_past';

    public const REASON_TOO_SOON = 'too_soon';

    public const REASON_TOO_FAR = 'too_far';

    public const REASON_FULL = 'full';

    /** A special date marked "fully booked" — the business said so, not the count. */
    public const REASON_DAY_FULL = 'day_full';

    public const REASON_PARTY_TOO_LARGE = 'party_too_large';

    /**
     * Every slot of a local date, free or not.
     *
     * @return array{
     *     date: string,
     *     open: bool,
     *     reason: ?string,
     *     note: ?string,
     *     slots: list<array{time: string, starts_at: CarbonImmutable, ends_at: CarbonImmutable, remaining: int, available: bool, reason: ?string}>
     * }
     */
    public function day(ReservableService $service, string $date, int $partySize = 1, ?int $ignoreReservationId = null, bool $rules = true): array
    {
        $timezone = $this->timezone($service);
        $localDate = ReservationSchedule::parseDate($date, $timezone);
        $result = ['date' => $date, 'open' => false, 'reason' => null, 'note' => ReservationSchedule::overrideNote($service, $date), 'slots' => []];

        if ($localDate === null) {
            return array_merge($result, ['reason' => self::REASON_NOT_A_SLOT]);
        }

        if ($rules && ! $service->active) {
            return array_merge($result, ['reason' => self::REASON_INACTIVE]);
        }

        if ($partySize > $service->maxPartySize()) {
            return array_merge($result, ['reason' => self::REASON_PARTY_TOO_LARGE]);
        }

        if ($rules && ReservationSchedule::isFullOn($service, $localDate->format('Y-m-d'))) {
            return array_merge($result, ['reason' => self::REASON_DAY_FULL]);
        }

        $ranges = ReservationSchedule::rangesOn($service, $localDate);

        if ($ranges === []) {
            return array_merge($result, ['reason' => self::REASON_CLOSED]);
        }

        $starts = $this->startsWithin($service, $ranges);
        $duration = (int) $service->duration_minutes;

        // Open, but every range is shorter than one booking: nothing to offer.
        if ($starts === []) {
            return array_merge($result, ['reason' => self::REASON_CLOSED]);
        }

        $windowStart = $localDate->addMinutes($starts[0] ?? 0);
        $windowEnd = $localDate->addMinutes((end($starts) ?: 0) + $duration);
        $booked = $this->bookedBetween($service, $windowStart->utc(), $windowEnd->utc(), $ignoreReservationId);

        $slots = [];

        foreach ($starts as $minute) {
            $start = $localDate->addMinutes($minute);
            $end = $start->addMinutes($duration);
            $remaining = max(0, (int) $service->capacity - $this->peakLoad($service, $booked, $start, $end));
            $reason = $this->ruleProblem($service, $start, $rules)
                ?? ($remaining < $this->weight($service, $partySize) ? self::REASON_FULL : null);

            $slots[] = [
                'time' => ReservationSchedule::formatMinutes($minute),
                'starts_at' => $start,
                'ends_at' => $end,
                'remaining' => $remaining,
                'available' => $reason === null,
                'reason' => $reason,
            ];
        }

        $result['open'] = true;
        $result['slots'] = $slots;

        if (! collect($slots)->contains('available', true)) {
            $result['reason'] = collect($slots)->pluck('reason')->filter()->unique()->count() === 1
                ? $slots[0]['reason']
                : self::REASON_FULL;
        }

        return $result;
    }

    /**
     * Whether one booking fits at one time. Null when it does; the reason
     * otherwise.
     *
     * `$onGrid = false` accepts any start inside the opening hours, not only
     * the offered ones: the team may write down 19:15 for a call that said so.
     */
    public function problem(
        ReservableService $service,
        CarbonImmutable $localStart,
        int $partySize,
        ?int $ignoreReservationId = null,
        bool $rules = true,
        bool $onGrid = true,
        bool $checkParty = true,
    ): ?string {
        if ($rules && ! $service->active) {
            return self::REASON_INACTIVE;
        }

        if ($checkParty && ($partySize < 1 || $partySize > $service->maxPartySize())) {
            return self::REASON_PARTY_TOO_LARGE;
        }

        $localStart = $localStart->setTimezone($this->timezone($service));
        $duration = (int) $service->duration_minutes;

        $opening = $this->openingDayOf($service, $localStart);

        if ($rules && ReservationSchedule::isFullOn($service, ($opening[0] ?? $localStart->startOfDay())->format('Y-m-d'))) {
            return self::REASON_DAY_FULL;
        }

        if (! $this->fitsTheHours($service, $localStart, $duration, $onGrid)) {
            return ReservationSchedule::rangesOn($service, $localStart->startOfDay()) === []
                && $this->openingDayOf($service, $localStart) === null
                ? self::REASON_CLOSED
                : self::REASON_NOT_A_SLOT;
        }

        if ($problem = $this->ruleProblem($service, $localStart, $rules)) {
            return $problem;
        }

        $end = $localStart->addMinutes($duration);
        $booked = $this->bookedBetween($service, $localStart->utc(), $end->utc(), $ignoreReservationId);

        $remaining = (int) $service->capacity - $this->peakLoad($service, $booked, $localStart, $end);

        return $remaining < $this->weight($service, $partySize) ? self::REASON_FULL : null;
    }

    /**
     * The next dates, from (and including) `$fromDate`, that have at least one
     * free slot for this party — what the AI offers when the day asked for is
     * full. Bounded by the booking window, and by `$scanDays` so a service open
     * once a month does not cost a year of queries.
     *
     * @return list<string>
     */
    public function nextDates(ReservableService $service, string $fromDate, int $partySize, int $limit = 3, int $scanDays = 31): array
    {
        $timezone = $this->timezone($service);
        $date = ReservationSchedule::parseDate($fromDate, $timezone);

        if ($date === null) {
            return [];
        }

        $last = CarbonImmutable::now($timezone)->startOfDay()->addDays(max(0, (int) $service->max_days_ahead));
        $found = [];

        for ($i = 0; $i < $scanDays && count($found) < $limit && $date->lessThanOrEqualTo($last); $i++, $date = $date->addDay()) {
            $day = $this->day($service, $date->format('Y-m-d'), $partySize);

            if (collect($day['slots'])->contains('available', true)) {
                $found[] = $date->format('Y-m-d');
            }
        }

        return $found;
    }

    public function timezone(ReservableService $service): string
    {
        return $service->tenant?->displayTimezone() ?? config('app.timezone', 'UTC');
    }

    /**
     * A local date's opening hours, for the AI to read out when it has no
     * free time to offer that day.
     *
     * @return list<array{open: string, close: string}>
     */
    public function hoursOn(ReservableService $service, string $date): array
    {
        $localDate = ReservationSchedule::parseDate($date, $this->timezone($service));

        if ($localDate === null) {
            return [];
        }

        return array_map(fn (array $range) => [
            'open' => ReservationSchedule::formatMinutes($range[0]),
            'close' => ReservationSchedule::formatMinutes($range[1]),
        ], ReservationSchedule::rangesOn($service, $localDate));
    }

    /** Whether the reason is one a request cannot be filed with at all. */
    public static function refuses(?string $reason): bool
    {
        return $reason !== null && $reason !== self::REASON_PARTY_TOO_LARGE;
    }

    /** How many places one booking of this party takes. */
    public function weight(ReservableService $service, int $partySize): int
    {
        return $service->countsPeople() ? max(1, $partySize) : 1;
    }

    /**
     * Start minutes of every slot across a day's ranges.
     *
     * @param  list<array{0: int, 1: int}>  $ranges
     * @return list<int>
     */
    private function startsWithin(ReservableService $service, array $ranges): array
    {
        $duration = (int) $service->duration_minutes;
        $interval = $service->interval();
        $starts = [];

        foreach ($ranges as [$open, $close]) {
            for ($minute = $open; $minute + $duration <= $close; $minute += $interval) {
                $starts[$minute] = $minute;
            }
        }

        ksort($starts);

        return array_values($starts);
    }

    /** Does a booking at this start sit inside the hours (and on the grid)? */
    private function fitsTheHours(ReservableService $service, CarbonImmutable $localStart, int $duration, bool $onGrid): bool
    {
        $day = $this->openingDayOf($service, $localStart);

        if ($day === null) {
            return false;
        }

        [, $minute, $open, $close] = $day;

        if ($minute + $duration > $close) {
            return false;
        }

        return ! $onGrid || ($minute - $open) % $service->interval() === 0;
    }

    /**
     * The range a start falls in, read from the day it opened — the same
     * date, or the evening before for a range that runs past midnight.
     *
     * @return array{0: CarbonImmutable, 1: int, 2: int, 3: int}|null local date, start minute, open, close
     */
    private function openingDayOf(ReservableService $service, CarbonImmutable $localStart): ?array
    {
        foreach ([0, 1] as $daysBack) {
            $localDate = $localStart->startOfDay()->subDays($daysBack);
            $minute = (int) round(($localStart->getTimestamp() - $localDate->getTimestamp()) / 60);

            foreach (ReservationSchedule::rangesOn($service, $localDate) as [$open, $close]) {
                if ($minute >= $open && $minute < $close) {
                    return [$localDate, $minute, $open, $close];
                }
            }
        }

        return null;
    }

    private function ruleProblem(ReservableService $service, CarbonImmutable $localStart, bool $rules): ?string
    {
        $now = CarbonImmutable::now();

        if ($localStart->lessThan($now)) {
            return self::REASON_PAST;
        }

        if (! $rules) {
            return null;
        }

        if ($localStart->lessThan($now->addMinutes(max(0, (int) $service->min_notice_minutes)))) {
            return self::REASON_TOO_SOON;
        }

        $timezone = $this->timezone($service);
        $lastDay = $now->setTimezone($timezone)->startOfDay()->addDays(max(0, (int) $service->max_days_ahead));

        if ($localStart->setTimezone($timezone)->startOfDay()->greaterThan($lastDay)) {
            return self::REASON_TOO_FAR;
        }

        return null;
    }

    /** @return Collection<int, Reservation> */
    private function bookedBetween(ReservableService $service, CarbonImmutable $start, CarbonImmutable $end, ?int $ignoreReservationId): Collection
    {
        return Reservation::query()
            ->where('reservable_service_id', $service->id)
            ->holdingCapacity()
            ->overlapping($start, $end)
            ->when($ignoreReservationId, fn ($q, $id) => $q->whereKeyNot($id))
            ->get(['id', 'starts_at', 'ends_at', 'party_size']);
    }

    /**
     * The most places taken at any one moment of [start, end).
     *
     * @param  Collection<int, Reservation>  $booked
     */
    private function peakLoad(ReservableService $service, Collection $booked, CarbonImmutable $start, CarbonImmutable $end): int
    {
        $events = [];
        $from = $start->getTimestamp();
        $to = $end->getTimestamp();

        foreach ($booked as $reservation) {
            $bookedFrom = max($from, $reservation->starts_at->getTimestamp());
            $bookedTo = min($to, $reservation->ends_at->getTimestamp());

            if ($bookedFrom >= $bookedTo) {
                continue;
            }

            $weight = $this->weight($service, (int) $reservation->party_size);
            $events[] = [$bookedFrom, $weight];
            $events[] = [$bookedTo, -$weight];
        }

        // An end and a start at the same second do not overlap: ends first.
        usort($events, fn ($a, $b) => $a[0] <=> $b[0] ?: $a[1] <=> $b[1]);

        $load = 0;
        $peak = 0;

        foreach ($events as [, $delta]) {
            $load += $delta;
            $peak = max($peak, $load);
        }

        return $peak;
    }
}

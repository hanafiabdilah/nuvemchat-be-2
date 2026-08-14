<?php

namespace App\Services\Statistics;

use App\Enums\Conversation\Type;
use App\Support\SqlDialect;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * One request's worth of "which rows are we talking about": the period, the
 * period before it (so every headline number can carry a trend), the viewer's
 * timezone, and the filter set.
 *
 * Every statistics query starts from conversations() or messages() here rather
 * than building its own joins — the tenant scope and the filters are the two
 * things that must never be forgotten, and centralising them is the only way
 * to be sure of that.
 */
class StatsScope
{
    public readonly Carbon $from;

    public readonly Carbon $to;

    public readonly Carbon $previousFrom;

    public readonly Carbon $previousTo;

    /** Seconds to add to a UTC timestamp to get the viewer's local clock. */
    public readonly int $offsetSeconds;

    public readonly string $timezone;

    /** @var array<int, string> */
    public readonly array $channels;

    /** @var array<int, int> */
    public readonly array $connectionIds;

    /** @var array<int, int> */
    public readonly array $agentIds;

    /** @var array<int, int> */
    public readonly array $tagIds;

    public readonly bool $includeGroups;

    public function __construct(
        public readonly int $tenantId,
        Carbon $from,
        Carbon $to,
        string $timezone,
        array $channels = [],
        array $connectionIds = [],
        array $agentIds = [],
        array $tagIds = [],
        bool $includeGroups = false,
    ) {
        $this->from = $from;
        $this->to = $to;
        $this->timezone = $timezone;
        $this->offsetSeconds = $from->copy()->setTimezone($timezone)->utcOffset() * 60;

        // The period immediately before, of the same length, so "vs. previous"
        // compares like with like (a 7-day week against the 7 days before it).
        $length = $from->diffInSeconds($to);
        $this->previousTo = $from->copy()->subSecond();
        $this->previousFrom = $this->previousTo->copy()->subSeconds($length);

        $this->channels = array_values($channels);
        $this->connectionIds = array_values($connectionIds);
        $this->agentIds = array_values($agentIds);
        $this->tagIds = array_values($tagIds);
        $this->includeGroups = $includeGroups;
    }

    public static function fromRequest(Request $request, int $tenantId): self
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'timezone' => ['nullable', 'string', 'timezone'],
            'channels' => ['nullable', 'array'],
            'channels.*' => ['string'],
            'connection_ids' => ['nullable', 'array'],
            'connection_ids.*' => ['integer'],
            'agent_ids' => ['nullable', 'array'],
            'agent_ids.*' => ['integer'],
            'tag_ids' => ['nullable', 'array'],
            'tag_ids.*' => ['integer'],
            'include_groups' => ['nullable', 'boolean'],
        ]);

        $timezone = $validated['timezone'] ?? config('app.timezone', 'UTC');

        // The dates arrive as calendar days in the viewer's zone; the database
        // stores UTC. Parsing in the viewer's zone first is what keeps "today"
        // from starting three hours late in São Paulo.
        $from = isset($validated['from'])
            ? Carbon::parse($validated['from'], $timezone)->startOfDay()
            : Carbon::now($timezone)->subDays(29)->startOfDay();

        $to = isset($validated['to'])
            ? Carbon::parse($validated['to'], $timezone)->endOfDay()
            : Carbon::now($timezone)->endOfDay();

        if ($from->greaterThan($to)) {
            [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
        }

        return new self(
            tenantId: $tenantId,
            from: $from->utc(),
            to: $to->utc(),
            timezone: $timezone,
            channels: $validated['channels'] ?? [],
            connectionIds: $validated['connection_ids'] ?? [],
            agentIds: $validated['agent_ids'] ?? [],
            tagIds: $validated['tag_ids'] ?? [],
            includeGroups: (bool) ($validated['include_groups'] ?? false),
        );
    }

    /**
     * Conversations belonging to this tenant, narrowed by the active filters.
     * Pass a range to restrict on `conversations.created_at`; omit it for
     * "right now" snapshots, which are about state rather than a period.
     */
    public function conversations(?Carbon $from = null, ?Carbon $to = null): Builder
    {
        $query = DB::table('conversations')
            ->join('connections', 'connections.id', '=', 'conversations.connection_id')
            ->where('connections.tenant_id', $this->tenantId);

        $this->applyFilters($query);

        if ($from) {
            $query->whereBetween('conversations.created_at', [$from, $to ?? $this->to]);
        }

        return $query;
    }

    /**
     * Messages, scoped through their conversation. Ranges apply to
     * `messages.created_at` — when the row landed in our database, which is the
     * only clock we control (`sent_at` can be back-dated by a history import).
     */
    public function messages(?Carbon $from = null, ?Carbon $to = null): Builder
    {
        $query = DB::table('messages')
            ->join('conversations', 'conversations.id', '=', 'messages.conversation_id')
            ->join('connections', 'connections.id', '=', 'conversations.connection_id')
            ->where('connections.tenant_id', $this->tenantId);

        $this->applyFilters($query);

        if ($from) {
            $query->whereBetween('messages.created_at', [$from, $to ?? $this->to]);
        }

        return $query;
    }

    private function applyFilters(Builder $query): void
    {
        if ($this->channels) {
            $query->whereIn('connections.channel', $this->channels);
        }

        if ($this->connectionIds) {
            $query->whereIn('conversations.connection_id', $this->connectionIds);
        }

        // Filtering by agent means "threads this agent owns", not "messages
        // this agent typed" — the whole page is about conversations.
        if ($this->agentIds) {
            $query->whereIn('conversations.user_id', $this->agentIds);
        }

        if ($this->tagIds) {
            $query->whereIn('conversations.id', function ($sub) {
                $sub->select('conversation_id')
                    ->from('conversation_tags')
                    ->whereIn('tag_id', $this->tagIds);
            });
        }

        // Groups are excluded by default: nobody is assigned to them, flows
        // never run in them, and counting them drags every service metric down
        // with conversations no agent was ever expected to answer.
        if (! $this->includeGroups) {
            $query->where('conversations.type', Type::Private->value);
        }
    }

    /** Calendar days in the period, as 'YYYY-MM-DD' in the viewer's zone. */
    public function days(): array
    {
        $days = [];
        $cursor = $this->from->copy()->setTimezone($this->timezone)->startOfDay();
        $last = $this->to->copy()->setTimezone($this->timezone)->startOfDay();

        // A very long custom range is capped so the payload stays a chart and
        // not a spreadsheet.
        $guard = 0;
        while ($cursor->lessThanOrEqualTo($last) && $guard++ < 400) {
            $days[] = $cursor->format('Y-m-d');
            $cursor->addDay();
        }

        return $days;
    }

    public function hour(string $column): string
    {
        return SqlDialect::hour($column, $this->offsetSeconds);
    }

    public function date(string $column): string
    {
        return SqlDialect::date($column, $this->offsetSeconds);
    }

    public function dayOfWeek(string $column): string
    {
        return SqlDialect::dayOfWeek($column, $this->offsetSeconds);
    }

    /**
     * Median of a list, computed in PHP. Deliberately not AVG in SQL: one
     * conversation answered three days late drags a mean far more than it
     * drags anyone's real experience.
     *
     * @param  array<int, int|float>  $values
     */
    public static function median(array $values): ?int
    {
        if (! $values) {
            return null;
        }

        sort($values);
        $count = count($values);
        $middle = intdiv($count, 2);

        return (int) round($count % 2
            ? $values[$middle]
            : ($values[$middle - 1] + $values[$middle]) / 2);
    }

    /**
     * The value at a percentile (0–100) of a list, same reasoning as median().
     *
     * @param  array<int, int|float>  $values
     */
    public static function percentile(array $values, float $percentile): ?int
    {
        if (! $values) {
            return null;
        }

        sort($values);
        $index = (int) ceil(($percentile / 100) * count($values)) - 1;

        return (int) round($values[max(0, min($index, count($values) - 1))]);
    }
}

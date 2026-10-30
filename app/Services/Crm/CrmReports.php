<?php

namespace App\Services\Crm;

use App\Enums\Crm\ActivityType;
use App\Enums\Crm\LeadStatus;
use App\Models\CrmActivity;
use App\Models\CrmPipeline;
use App\Models\CrmTask;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\User;
use App\Support\SqlDialect;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The CRM's numbers: the Overview page and the five reports (sales by person,
 * lead sources, the pipeline stage by stage, the forecast, logged activity).
 *
 * Every figure is counted over the leads and deals the reader may see.
 * Amounts stay in minor units (the
 * workspace currency), days are the workspace's calendar days.
 *
 * "Weighted" is value × the stage's probability: what the open pipeline is
 * likely worth. "Win rate" is won ÷ (won + lost) among deals closed in the
 * period — deals still open are not losses.
 */
final class CrmReports
{
    /** @param  array{from: Carbon, to: Carbon, timezone: string}  $period */
    public function __construct(
        private readonly User $viewer,
        private readonly array $period,
        private readonly array $filters = [],
    ) {}

    public function overview(): array
    {
        [$from, $to] = [$this->period['from'], $this->period['to']];
        $length = $from->diffInSeconds($to);
        $previous = [$from->copy()->subSeconds($length), $from->copy()];

        $won = fn (array $range) => $this->deals()->where('status', 'won')->whereBetween('won_at', $range);
        $lost = fn (array $range) => $this->deals()->where('status', 'lost')->whereBetween('lost_at', $range);

        $wonNow = (clone $won([$from, $to]));
        $wonCount = (clone $wonNow)->count();
        $lostCount = (clone $lost([$from, $to]))->count();
        $leadsCreated = $this->leads()->whereBetween('crm_leads.created_at', [$from, $to]);
        $leadsCount = (clone $leadsCreated)->count();
        $open = $this->openPipeline();
        $tz = $this->period['timezone'];
        $monthStart = Carbon::now($tz)->startOfMonth()->toDateString();
        $monthEnd = Carbon::now($tz)->endOfMonth()->toDateString();
        $forecast = $this->weighted($this->deals()->where('crm_deals.status', 'open')->whereBetween('expected_close_date', [$monthStart, $monthEnd]));

        return [
            'kpis' => [
                'leads_new' => ['value' => $leadsCount, 'previous' => $this->leads()->whereBetween('crm_leads.created_at', $previous)->count()],
                'leads_open' => $this->leads()->whereIn('status', $this->openLeadStatuses())->count(),
                'conversion_rate' => $leadsCount > 0 ? round((clone $leadsCreated)->where('status', LeadStatus::Converted->value)->count() / $leadsCount * 100, 1) : null,
                'deals_new' => ['value' => $this->deals()->whereBetween('crm_deals.created_at', [$from, $to])->count(), 'previous' => $this->deals()->whereBetween('crm_deals.created_at', $previous)->count()],
                'open_count' => $open['count'],
                'open_value_cents' => $open['value'],
                'weighted_value_cents' => $open['weighted'],
                'won_count' => $wonCount,
                'won_value_cents' => ['value' => (int) (clone $wonNow)->sum('value_cents'), 'previous' => (int) $won($previous)->sum('value_cents')],
                'lost_count' => $lostCount,
                'win_rate' => $wonCount + $lostCount > 0 ? round($wonCount / ($wonCount + $lostCount) * 100, 1) : null,
                'avg_cycle_days' => $this->averageCycleDays((clone $wonNow)),
                'forecast_month' => $forecast,
            ],
            'my_tasks' => $this->myTasks(),
            'funnel' => $this->funnel(),
            'leads_by_source' => $this->leadsBySource($from, $to),
            'won_by_month' => $this->wonByMonth(6),
            'top_owners' => array_slice($this->salesByOwner(), 0, 5),
            'recent_won' => (clone $wonNow)->with('customer:id,name')->orderByDesc('won_at')->limit(5)->get()
                ->map(fn (Deal $deal) => [
                    'id' => $deal->id,
                    'title' => $deal->title,
                    'value_cents' => (int) $deal->value_cents,
                    'customer' => $deal->customer?->name,
                    'won_at' => $deal->won_at?->toIso8601String(),
                ])->values(),
        ];
    }

    public function sales(): array
    {
        $rows = $this->salesByOwner();
        $sum = fn (string $key) => array_sum(array_column($rows, $key));
        $won = $sum('won_count');
        $lost = $sum('lost_count');

        return [
            'rows' => $rows,
            'totals' => [
                'open_count' => $sum('open_count'),
                'open_value_cents' => $sum('open_value_cents'),
                'won_count' => $won,
                'won_value_cents' => $sum('won_value_cents'),
                'lost_count' => $lost,
                'win_rate' => $won + $lost > 0 ? round($won / ($won + $lost) * 100, 1) : null,
                'avg_cycle_days' => $this->averageCycleDays($this->deals()->where('status', 'won')->whereBetween('won_at', [$this->period['from'], $this->period['to']])),
            ],
            'won_by_month' => $this->wonByMonth(12, $this->period['from']),
        ];
    }

    public function sources(): array
    {
        [$from, $to] = [$this->period['from'], $this->period['to']];

        $rows = $this->leads()
            ->whereBetween('crm_leads.created_at', [$from, $to])
            ->selectRaw("COALESCE(source, '') as source")
            ->selectRaw('COUNT(*) as leads')
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as converted', [LeadStatus::Converted->value])
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as disqualified', [LeadStatus::Disqualified->value])
            ->groupBy(DB::raw("COALESCE(source, '')"))
            ->get();

        $wonBySource = $this->deals()
            ->join('crm_leads as origin', 'origin.id', '=', 'crm_deals.lead_id')
            ->whereBetween('origin.created_at', [$from, $to])
            ->where('crm_deals.status', 'won')
            ->selectRaw("COALESCE(origin.source, '') as source, SUM(crm_deals.value_cents) as won_value, COUNT(*) as won")
            ->groupBy(DB::raw("COALESCE(origin.source, '')"))
            ->get()
            ->keyBy('source');

        return [
            'rows' => $rows->map(function ($row) use ($wonBySource) {
                $leads = (int) $row->leads;
                $won = $wonBySource->get($row->source);

                return [
                    'source' => $row->source !== '' ? $row->source : null,
                    'leads' => $leads,
                    'converted' => (int) $row->converted,
                    'disqualified' => (int) $row->disqualified,
                    'open' => $leads - (int) $row->converted - (int) $row->disqualified,
                    'conversion_rate' => $leads > 0 ? round((int) $row->converted / $leads * 100, 1) : null,
                    'won_deals' => (int) ($won->won ?? 0),
                    'won_value_cents' => (int) ($won->won_value ?? 0),
                ];
            })->sortByDesc('leads')->values(),
        ];
    }

    public function pipeline(): array
    {
        [$from, $to] = [$this->period['from'], $this->period['to']];
        $pipelines = CrmPipelines::forTenant($this->viewer->tenant_id);
        $pipeline = isset($this->filters['pipeline_id'])
            ? $pipelines->firstWhere('id', (int) $this->filters['pipeline_id'])
            : ($pipelines->firstWhere('is_default', true) ?? $pipelines->first());

        if (! $pipeline) {
            return ['pipeline' => null, 'stages' => [], 'totals' => []];
        }

        $visible = $this->deals()->select('crm_deals.id');
        $now = now();

        $stages = $pipeline->stages->map(function ($stage) use ($pipeline, $from, $to, $visible, $now) {
            $inStage = $this->deals()->where('stage_id', $stage->id);
            $count = (clone $inStage)->count();
            $value = (int) (clone $inStage)->sum('value_cents');
            $ages = (clone $inStage)->whereNotNull('stage_entered_at')->pluck('stage_entered_at');

            // Entered in the period: moved into the stage, or created in it.
            $moved = CrmActivity::query()
                ->where('tenant_id', $this->viewer->tenant_id)
                ->where('subject_type', 'deal')
                ->whereIn('subject_id', clone $visible)
                ->where('type', CrmTimeline::STAGE_CHANGED)
                ->where('data->to_stage_id', $stage->id)
                ->whereBetween('occurred_at', [$from, $to])
                ->count();
            $created = $this->deals()->where('pipeline_id', $pipeline->id)
                ->whereBetween('crm_deals.created_at', [$from, $to])
                ->whereIn('crm_deals.id', CrmActivity::query()->where('subject_type', 'deal')->where('type', CrmTimeline::CREATED)->where('data->stage_id', $stage->id)->select('subject_id'))
                ->count();

            return [
                'id' => $stage->id,
                'name' => $stage->name,
                'kind' => $stage->kind->value,
                'probability' => (int) $stage->probability,
                'count' => $count,
                'value_cents' => $value,
                'weighted_cents' => (int) round($value * $stage->probability / 100),
                'avg_age_days' => $ages->isEmpty() ? null : round($ages->avg(fn ($at) => Carbon::parse($at)->diffInHours($now)) / 24, 1),
                'entered' => $moved + $created,
            ];
        })->values();

        $won = $this->deals()->where('pipeline_id', $pipeline->id)->where('status', 'won')->whereBetween('won_at', [$from, $to]);
        $lost = $this->deals()->where('pipeline_id', $pipeline->id)->where('status', 'lost')->whereBetween('lost_at', [$from, $to]);
        $wonCount = (clone $won)->count();
        $lostCount = (clone $lost)->count();

        return [
            'pipeline' => ['id' => $pipeline->id, 'name' => $pipeline->name],
            'pipelines' => $pipelines->map(fn (CrmPipeline $p) => ['id' => $p->id, 'name' => $p->name])->values(),
            'stages' => $stages,
            'totals' => [
                'won_count' => $wonCount,
                'won_value_cents' => (int) (clone $won)->sum('value_cents'),
                'lost_count' => $lostCount,
                'win_rate' => $wonCount + $lostCount > 0 ? round($wonCount / ($wonCount + $lostCount) * 100, 1) : null,
                'avg_cycle_days' => $this->averageCycleDays(clone $won),
                'lost_reasons' => (clone $lost)->whereNotNull('lost_reason')
                    ->selectRaw('lost_reason, COUNT(*) as total')->groupBy('lost_reason')->orderByDesc('total')->limit(8)->get()
                    ->map(fn ($row) => ['reason' => $row->lost_reason, 'count' => (int) $row->total])->values(),
            ],
        ];
    }

    /** The next months by expected close date, and what was actually won before. */
    public function forecast(int $months = 6): array
    {
        $tz = $this->period['timezone'];
        $today = Carbon::now($tz)->startOfDay();
        $open = $this->deals()->where('crm_deals.status', 'open')
            ->when($this->filters['pipeline_id'] ?? null, fn (Builder $q, $id) => $q->where('crm_deals.pipeline_id', $id));

        $ahead = [];

        for ($i = 0; $i < $months; $i++) {
            $start = $today->copy()->startOfMonth()->addMonthsNoOverflow($i);
            $range = [$i === 0 ? $today->toDateString() : $start->toDateString(), $start->copy()->endOfMonth()->toDateString()];
            $ahead[] = ['month' => $start->format('Y-m'), ...$this->weighted((clone $open)->whereBetween('expected_close_date', $range))];
        }

        return [
            'months' => $ahead,
            'overdue' => $this->weighted((clone $open)->where('expected_close_date', '<', $today->toDateString())),
            'no_date' => $this->weighted((clone $open)->whereNull('expected_close_date')),
            'won_by_month' => $this->wonByMonth($months),
        ];
    }

    public function activities(): array
    {
        [$from, $to] = [$this->period['from'], $this->period['to']];
        $offset = Carbon::now($this->period['timezone'])->getOffset();
        $types = ActivityType::values();

        $logged = CrmActivity::query()->visibleTo($this->viewer)
            ->whereIn('crm_activities.type', $types)
            ->whereBetween('occurred_at', [$from, $to]);

        $byUser = (clone $logged)->whereNotNull('user_id')
            ->selectRaw('user_id, type, COUNT(*) as total')->groupBy('user_id', 'type')->get();

        $completed = CrmTask::query()->visibleTo($this->viewer)
            ->whereBetween('completed_at', [$from, $to])->whereNotNull('completed_by')
            ->selectRaw('completed_by as user_id, COUNT(*) as total')->groupBy('completed_by')->pluck('total', 'user_id');

        $users = User::query()->whereIn('id', $byUser->pluck('user_id')->merge($completed->keys())->unique())->get(['id', 'name'])->keyBy('id');

        $rows = $users->map(function (User $user) use ($byUser, $completed, $types) {
            $mine = $byUser->where('user_id', $user->id);
            $counts = collect($types)->mapWithKeys(fn ($type) => [$type => (int) ($mine->firstWhere('type', $type)->total ?? 0)])->all();

            return ['user' => ['id' => $user->id, 'name' => $user->name], ...$counts, 'tasks_completed' => (int) ($completed[$user->id] ?? 0), 'total' => array_sum($counts)];
        })->sortByDesc('total')->values();

        $day = SqlDialect::date('occurred_at', $offset);

        return [
            'rows' => $rows,
            'by_day' => (clone $logged)->selectRaw("{$day} as day, COUNT(*) as total")->groupBy(DB::raw($day))->orderBy('day')->get()
                ->map(fn ($row) => ['day' => $row->day, 'count' => (int) $row->total])->values(),
            'by_type' => collect($types)->mapWithKeys(fn ($type) => [$type => (clone $logged)->where('crm_activities.type', $type)->count()]),
        ];
    }

    // ------------------------------------------------------------------

    private function leads(): Builder
    {
        return Lead::query()->visibleTo($this->viewer)
            ->when($this->filters['owner'] ?? null, fn (Builder $q, $owner) => $this->owner($q, 'crm_leads.owner_user_id', $owner));
    }

    private function deals(): Builder
    {
        return Deal::query()->visibleTo($this->viewer)
            ->when($this->filters['owner'] ?? null, fn (Builder $q, $owner) => $this->owner($q, 'crm_deals.owner_user_id', $owner))
            ->when($this->filters['pipeline_id'] ?? null, fn (Builder $q, $pipeline) => $q->where('crm_deals.pipeline_id', $pipeline));
    }

    private function owner(Builder $q, string $column, string $owner): void
    {
        match ($owner) {
            'none' => $q->whereNull($column),
            'me' => $q->where($column, $this->viewer->id),
            default => $q->where($column, (int) $owner),
        };
    }

    /** @return list<string> */
    private function openLeadStatuses(): array
    {
        return array_values(array_filter(LeadStatus::values(), fn ($status) => LeadStatus::from($status)->isOpen()));
    }

    /** @return array{count: int, value: int, weighted: int} */
    private function openPipeline(): array
    {
        $row = $this->deals()->where('crm_deals.status', 'open')
            ->join('crm_pipeline_stages as stage', 'stage.id', '=', 'crm_deals.stage_id')
            ->selectRaw('COUNT(*) as total, COALESCE(SUM(crm_deals.value_cents), 0) as value, COALESCE(SUM(crm_deals.value_cents * stage.probability), 0) as weighted')
            ->first();

        return ['count' => (int) $row->total, 'value' => (int) $row->value, 'weighted' => (int) round($row->weighted / 100)];
    }

    /** @return array{count: int, value_cents: int, weighted_cents: int} */
    private function weighted(Builder $query): array
    {
        $row = (clone $query)
            ->join('crm_pipeline_stages as stage', 'stage.id', '=', 'crm_deals.stage_id')
            ->selectRaw('COUNT(*) as total, COALESCE(SUM(crm_deals.value_cents), 0) as value, COALESCE(SUM(crm_deals.value_cents * stage.probability), 0) as weighted')
            ->first();

        return ['count' => (int) $row->total, 'value_cents' => (int) $row->value, 'weighted_cents' => (int) round($row->weighted / 100)];
    }

    private function averageCycleDays(Builder $won): ?float
    {
        $pairs = (clone $won)->get(['crm_deals.created_at', 'crm_deals.won_at']);

        if ($pairs->isEmpty()) {
            return null;
        }

        return round($pairs->avg(fn (Deal $deal) => $deal->created_at->diffInHours($deal->won_at) / 24), 1);
    }

    /** @return list<array<string, mixed>> */
    private function myTasks(): array
    {
        $tz = $this->period['timezone'];
        $mine = CrmTask::query()->visibleTo($this->viewer)->where('assignee_user_id', $this->viewer->id)->whereNull('completed_at');

        return [
            'open' => (clone $mine)->count(),
            'overdue' => (clone $mine)->where('due_at', '<', now())->count(),
            'today' => (clone $mine)->whereBetween('due_at', [Carbon::now($tz)->startOfDay()->utc(), Carbon::now($tz)->endOfDay()->utc()])->count(),
            'next' => (clone $mine)->with(['subject', 'customer:id,name'])->orderByRaw('due_at IS NULL')->orderBy('due_at')->limit(5)->get()
                ->map(fn (CrmTask $task) => [
                    'id' => $task->id,
                    'title' => $task->title,
                    'due_at' => $task->due_at?->toIso8601String(),
                    'overdue' => $task->due_at !== null && $task->due_at->isPast(),
                    'subject_type' => $task->subject_type,
                    'subject_id' => $task->subject_id,
                    'subject_name' => $task->subject ? ($task->subject->title ?? $task->subject->name ?? null) : null,
                ])->values()->all(),
        ];
    }

    /** @return list<array<string, mixed>> the default pipeline's open stages, now */
    private function funnel(): array
    {
        $pipeline = CrmPipelines::forTenant($this->viewer->tenant_id)->firstWhere('is_default', true);

        if (! $pipeline) {
            return [];
        }

        $counts = $this->deals()->where('crm_deals.pipeline_id', $pipeline->id)->where('crm_deals.status', 'open')
            ->selectRaw('stage_id, COUNT(*) as total, COALESCE(SUM(value_cents), 0) as value')
            ->groupBy('stage_id')->get()->keyBy('stage_id');

        return $pipeline->stages->where('kind.value', 'open')->map(fn ($stage) => [
            'id' => $stage->id,
            'name' => $stage->name,
            'count' => (int) ($counts[$stage->id]->total ?? 0),
            'value_cents' => (int) ($counts[$stage->id]->value ?? 0),
        ])->values()->all();
    }

    /** @return list<array{source: ?string, count: int}> */
    private function leadsBySource(Carbon $from, Carbon $to): array
    {
        return $this->leads()->whereBetween('crm_leads.created_at', [$from, $to])
            ->selectRaw("COALESCE(source, '') as source, COUNT(*) as total")
            ->groupBy(DB::raw("COALESCE(source, '')"))->orderByDesc('total')->limit(8)->get()
            ->map(fn ($row) => ['source' => $row->source !== '' ? $row->source : null, 'count' => (int) $row->total])->values()->all();
    }

    /** @return list<array{month: string, count: int, value_cents: int}> the last N months, oldest first */
    private function wonByMonth(int $months, ?Carbon $from = null): array
    {
        $tz = $this->period['timezone'];
        $offset = Carbon::now($tz)->getOffset();
        $start = $from
            ? $from->copy()->setTimezone($tz)->startOfMonth()
            : Carbon::now($tz)->startOfMonth()->subMonthsNoOverflow($months - 1);
        $end = $from ? $this->period['to']->copy()->setTimezone($tz) : Carbon::now($tz);
        $month = SqlDialect::month('won_at', $offset);

        $rows = $this->deals()->where('status', 'won')->whereBetween('won_at', [$start->copy()->utc(), $end->copy()->utc()])
            ->selectRaw("{$month} as month, COUNT(*) as total, COALESCE(SUM(value_cents), 0) as value")
            ->groupBy(DB::raw($month))->get()->keyBy('month');

        $series = [];

        for ($cursor = $start->copy(); $cursor->lte($end) && count($series) < 24; $cursor->addMonthNoOverflow()) {
            $key = $cursor->format('Y-m');
            $series[] = ['month' => $key, 'count' => (int) ($rows[$key]->total ?? 0), 'value_cents' => (int) ($rows[$key]->value ?? 0)];
        }

        return $series;
    }

    /** @return list<array<string, mixed>> one row per person in charge, most won first */
    private function salesByOwner(): array
    {
        [$from, $to] = [$this->period['from'], $this->period['to']];

        $open = $this->deals()->where('status', 'open')->selectRaw('owner_user_id, COUNT(*) as total, COALESCE(SUM(value_cents), 0) as value')->groupBy('owner_user_id')->get()->keyBy('owner_user_id');
        $won = $this->deals()->where('status', 'won')->whereBetween('won_at', [$from, $to])->selectRaw('owner_user_id, COUNT(*) as total, COALESCE(SUM(value_cents), 0) as value')->groupBy('owner_user_id')->get()->keyBy('owner_user_id');
        $lost = $this->deals()->where('status', 'lost')->whereBetween('lost_at', [$from, $to])->selectRaw('owner_user_id, COUNT(*) as total')->groupBy('owner_user_id')->pluck('total', 'owner_user_id');
        $cycles = $this->deals()->where('status', 'won')->whereBetween('won_at', [$from, $to])->get(['owner_user_id', 'created_at', 'won_at'])
            ->groupBy(fn (Deal $deal) => (string) ($deal->owner_user_id ?? ''))
            ->map(fn (Collection $deals) => round($deals->avg(fn (Deal $deal) => $deal->created_at->diffInHours($deal->won_at) / 24), 1));

        $ids = collect([$open->keys(), $won->keys(), $lost->keys()])->flatten()->unique()->values();
        $users = User::query()->whereIn('id', $ids->filter())->get(['id', 'name', 'avatar_path'])->keyBy('id');

        return $ids->map(function ($id) use ($open, $won, $lost, $cycles, $users) {
            $wonCount = (int) ($won[$id]->total ?? 0);
            $lostCount = (int) ($lost[$id] ?? 0);
            $user = $id ? $users->get($id) : null;

            return [
                'owner' => $user ? ['id' => $user->id, 'name' => $user->name, 'avatar' => $user->avatar_url] : null,
                'open_count' => (int) ($open[$id]->total ?? 0),
                'open_value_cents' => (int) ($open[$id]->value ?? 0),
                'won_count' => $wonCount,
                'won_value_cents' => (int) ($won[$id]->value ?? 0),
                'lost_count' => $lostCount,
                'win_rate' => $wonCount + $lostCount > 0 ? round($wonCount / ($wonCount + $lostCount) * 100, 1) : null,
                'avg_cycle_days' => $cycles->get((string) ($id ?? '')),
            ];
        })->sortByDesc('won_value_cents')->values()->all();
    }
}

<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiHubRun;
use App\Models\Tenant;
use App\Support\AdminPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Back Office: what the platform's AI actually costs, and who is spending it.
 *
 * `ai_hub_runs` has recorded tokens, provider cost and latency per run since
 * the AI Hub shipped, and nothing has ever read it. This is the largest
 * variable cost in the product and the only one that can climb without anyone
 * doing anything — a single tenant with a chatty flow on an expensive model
 * shows up nowhere until the provider invoice arrives.
 *
 * Cost is stored in USD as reported by the hub. Runs that report no cost (older
 * rows, hub versions that omitted it) are counted but contribute zero, so the
 * response carries `costed_runs` alongside `runs`: a spend figure covering a
 * fraction of the runs is worse than no figure if the reader can't see it.
 */
class AdminAiUsageController extends Controller
{
    private const TOP_N = 15;

    public function index(Request $request)
    {
        $period = AdminPeriod::fromRequest($request, 30);
        $offset = (int) $request->integer('tz_offset', 0);
        $tenantId = $request->integer('tenant_id') ?: null;

        $scoped = fn () => AiHubRun::query()
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId));

        $inPeriod = fn () => $scoped()->whereBetween('created_at', [$period->from, $period->to]);

        $totals = $this->totals($inPeriod());
        $previous = $this->totals(
            $scoped()->whereBetween('created_at', [$period->previous()->from, $period->previous()->to])
        );

        return response()->json([
            'data' => [
                'period' => [
                    'from' => $period->from->toIso8601String(),
                    'to' => $period->to->toIso8601String(),
                    'days' => $period->days,
                    'granularity' => $period->bucketsByDay() ? 'day' : 'month',
                ],
                'totals' => $totals + [
                    'cost_usd_delta_pct' => $this->deltaPct($previous['cost_usd'], $totals['cost_usd']),
                    'runs_delta_pct' => $this->deltaPct($previous['runs'], $totals['runs']),
                ],
                'previous' => $previous,
                'series' => $this->series($inPeriod(), $period, $offset),
                'by_tenant' => $tenantId ? [] : $this->byTenant($inPeriod()),
                'by_model' => $this->byModel($inPeriod()),
                'errors' => $this->recentErrors($scoped(), $period),
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function totals(Builder $query): array
    {
        $row = (clone $query)->selectRaw(
            'COUNT(*) as runs,
             SUM(total_tokens) as tokens,
             SUM(input_tokens) as input_tokens,
             SUM(output_tokens) as output_tokens,
             SUM(cached_input_tokens) as cached_tokens,
             SUM(CASE WHEN cost_usd IS NOT NULL THEN 1 ELSE 0 END) as costed_runs,
             SUM(COALESCE(cost_usd, 0)) as cost_usd,
             SUM(CASE WHEN handoff_triggered = 1 THEN 1 ELSE 0 END) as handoffs,
             SUM(CASE WHEN error IS NOT NULL THEN 1 ELSE 0 END) as failed,
             AVG(latency_ms) as avg_latency_ms'
        )->first();

        $runs = (int) ($row->runs ?? 0);

        return [
            'runs' => $runs,
            'costed_runs' => (int) ($row->costed_runs ?? 0),
            'tokens' => (int) ($row->tokens ?? 0),
            'input_tokens' => (int) ($row->input_tokens ?? 0),
            'output_tokens' => (int) ($row->output_tokens ?? 0),
            'cached_tokens' => (int) ($row->cached_tokens ?? 0),
            'cost_usd' => round((float) ($row->cost_usd ?? 0), 6),
            'handoffs' => (int) ($row->handoffs ?? 0),
            'failed' => (int) ($row->failed ?? 0),
            'avg_latency_ms' => $row->avg_latency_ms === null ? null : (int) round((float) $row->avg_latency_ms),
            // Share of runs the AI could not finish on its own. High is not
            // necessarily bad (some flows hand off by design) but a jump is.
            'handoff_rate' => $runs > 0 ? round(((int) $row->handoffs / $runs) * 100, 1) : 0.0,
        ];
    }

    private function series(Builder $query, AdminPeriod $period, int $offset): array
    {
        $expr = $period->bucketExpr('created_at', $offset);

        $rows = (clone $query)
            ->selectRaw("$expr as bucket, COUNT(*) as runs, SUM(COALESCE(cost_usd, 0)) as cost_usd, SUM(total_tokens) as tokens")
            ->groupBy('bucket')
            ->get()
            ->keyBy('bucket');

        return array_map(fn (string $bucket) => [
            'period' => $bucket,
            'runs' => (int) ($rows[$bucket]->runs ?? 0),
            'cost_usd' => round((float) ($rows[$bucket]->cost_usd ?? 0), 6),
            'tokens' => (int) ($rows[$bucket]->tokens ?? 0),
        ], $period->buckets($offset));
    }

    /** Biggest spenders. The whole point of the page. */
    private function byTenant(Builder $query): array
    {
        $rows = (clone $query)
            ->selectRaw('tenant_id, COUNT(*) as runs, SUM(COALESCE(cost_usd, 0)) as cost_usd, SUM(total_tokens) as tokens')
            ->groupBy('tenant_id')
            ->orderByDesc('cost_usd')
            ->orderByDesc('runs')
            ->limit(self::TOP_N)
            ->get();

        $tenants = Tenant::with('user:id,name,email')
            ->whereIn('id', $rows->pluck('tenant_id'))
            ->get()
            ->keyBy('id');

        return $rows->map(function ($r) use ($tenants) {
            $tenant = $tenants[$r->tenant_id] ?? null;

            return [
                'tenant_id' => (int) $r->tenant_id,
                'name' => $tenant?->user?->name ?? "Tenant #{$r->tenant_id}",
                'email' => $tenant?->user?->email,
                'runs' => (int) $r->runs,
                'cost_usd' => round((float) $r->cost_usd, 6),
                'tokens' => (int) $r->tokens,
            ];
        })->values()->all();
    }

    private function byModel(Builder $query): array
    {
        return (clone $query)
            ->selectRaw('provider, model, COUNT(*) as runs, SUM(COALESCE(cost_usd, 0)) as cost_usd, SUM(total_tokens) as tokens, AVG(latency_ms) as avg_latency_ms')
            ->groupBy('provider', 'model')
            ->orderByDesc('cost_usd')
            ->orderByDesc('runs')
            ->limit(self::TOP_N)
            ->get()
            ->map(fn ($r) => [
                'provider' => $r->provider ?? '—',
                'model' => $r->model ?? '—',
                'runs' => (int) $r->runs,
                'cost_usd' => round((float) $r->cost_usd, 6),
                'tokens' => (int) $r->tokens,
                'avg_latency_ms' => $r->avg_latency_ms === null ? null : (int) round((float) $r->avg_latency_ms),
            ])->values()->all();
    }

    /**
     * Failed runs, newest first. A run that errored still means a customer
     * waited on a reply that never came, so these are worth surfacing next to
     * the money rather than only in the log file.
     */
    private function recentErrors(Builder $query, AdminPeriod $period): array
    {
        return (clone $query)
            ->whereBetween('created_at', [$period->from, $period->to])
            ->whereNotNull('error')
            ->with('tenant.user:id,name')
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->map(fn (AiHubRun $run) => [
                'id' => $run->id,
                'tenant_id' => $run->tenant_id,
                'tenant' => $run->tenant?->user?->name ?? "Tenant #{$run->tenant_id}",
                'provider' => $run->provider,
                'model' => $run->model,
                'status' => $run->status,
                // The hub's error shape varies; show the message if there is
                // one, otherwise the raw payload trimmed to something readable.
                'error' => is_array($run->error)
                    ? ($run->error['message'] ?? json_encode($run->error))
                    : (string) $run->error,
                'created_at' => $run->created_at?->toIso8601String(),
            ])->values()->all();
    }

    private function deltaPct(float|int $before, float|int $after): float
    {
        if ((float) $before === 0.0) {
            return $after > 0 ? 100.0 : 0.0;
        }

        return round((($after - $before) / $before) * 100, 1);
    }
}

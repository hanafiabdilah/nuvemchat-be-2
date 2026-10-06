<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Statistics\AgentStats;
use App\Services\Statistics\AutomationStats;
use App\Services\Statistics\HealthStats;
use App\Services\Statistics\OverviewStats;
use App\Services\Statistics\SalesStats;
use App\Services\Statistics\ServiceStats;
use App\Services\Statistics\StatsScope;
use App\Services\Statistics\TopicStats;
use App\Services\Statistics\VolumeStats;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Tenant analytics, one endpoint per section.
 *
 * Split rather than served as one document because the sections cost very
 * different amounts to compute and the client renders them in tabs: opening
 * the page should not pay for the AI cost breakdown nobody is looking at. Each
 * one takes the same filter set (see StatsScope) so they always describe the
 * same slice of data.
 */
class StatisticsController extends Controller
{
    public function overview(Request $request)
    {
        $scope = $this->scope($request);

        return $this->respond($scope, (new OverviewStats($scope))->build());
    }

    /**
     * Revenue, average ticket, front offer against upsell, sales per ad and the
     * progress towards the workspace's revenue goal.
     */
    public function sales(Request $request)
    {
        $scope = $this->scope($request);

        return $this->respond($scope, (new SalesStats($scope, Auth::user()->tenant))->build());
    }

    /** Set, change or clear (amount 0) the revenue goal. */
    public function updateSalesGoal(Request $request)
    {
        $data = $request->validate([
            'amount_cents' => ['required', 'integer', 'min:0', 'max:99999999999'],
            'period' => ['nullable', 'string', 'in:'.implode(',', SalesStats::GOAL_PERIODS)],
            'timezone' => ['nullable', 'string', 'timezone'],
        ]);

        $tenant = Auth::user()->tenant;
        $tenant->forceFill([
            'sales_goal' => SalesStats::normalizeGoal(['amount_cents' => $data['amount_cents'], 'period' => $data['period'] ?? 'month']),
        ])->save();

        return response()->json([
            'data' => ['goal' => SalesStats::goal($tenant, $data['timezone'] ?? config('app.timezone', 'UTC'))],
        ]);
    }

    public function volume(Request $request)
    {
        $scope = $this->scope($request);

        return $this->respond($scope, (new VolumeStats($scope))->build());
    }

    public function service(Request $request)
    {
        $scope = $this->scope($request);

        $slaMinutes = (int) $request->input('sla_minutes', 10);
        $slaMinutes = max(1, min($slaMinutes, 1440));

        return $this->respond($scope, (new ServiceStats($scope, $slaMinutes))->build());
    }

    public function agents(Request $request)
    {
        $scope = $this->scope($request);

        return $this->respond($scope, (new AgentStats($scope))->build());
    }

    public function topics(Request $request)
    {
        $scope = $this->scope($request);

        return $this->respond($scope, (new TopicStats($scope))->build());
    }

    public function automation(Request $request)
    {
        $scope = $this->scope($request);

        return $this->respond($scope, (new AutomationStats($scope))->build());
    }

    public function health(Request $request)
    {
        $scope = $this->scope($request);

        return $this->respond($scope, (new HealthStats($scope))->build());
    }

    /**
     * The filter bar's own options: which channels, connections, tags and
     * agents this tenant actually has. Served here so the page can build its
     * filters in one call instead of borrowing four unrelated endpoints, each
     * behind a permission the viewer may not hold.
     */
    public function filters(Request $request)
    {
        $tenantId = Auth::user()->tenant_id;

        $connections = DB::table('connections')
            ->where('tenant_id', $tenantId)
            ->orderBy('name')
            ->get(['id', 'name', 'channel', 'color', 'status']);

        return response()->json([
            'data' => [
                'connections' => $connections->map(fn ($row) => [
                    'id' => (int) $row->id,
                    'name' => $row->name,
                    'channel' => $row->channel,
                    'color' => $row->color,
                    'status' => $row->status,
                ])->all(),
                'channels' => $connections->pluck('channel')->unique()->values()->all(),
                'tags' => DB::table('tags')
                    ->where('tenant_id', $tenantId)
                    ->orderBy('name')
                    ->get(['id', 'name', 'color'])
                    ->map(fn ($row) => [
                        'id' => (int) $row->id,
                        'name' => $row->name,
                        'color' => $row->color,
                    ])->all(),
                'agents' => DB::table('users')
                    ->where('tenant_id', $tenantId)
                    ->orderBy('name')
                    ->get(['id', 'name'])
                    ->map(fn ($row) => [
                        'id' => (int) $row->id,
                        'name' => $row->name,
                    ])->all(),
            ],
        ]);
    }

    private function scope(Request $request): StatsScope
    {
        return StatsScope::fromRequest($request, Auth::user()->tenant_id);
    }

    /**
     * Every section echoes back the exact window it measured — including the
     * comparison window — so the client never has to re-derive it and can
     * label the trend honestly.
     */
    private function respond(StatsScope $scope, array $data)
    {
        return response()->json([
            'data' => [
                'range' => [
                    'from' => $scope->from->toIso8601String(),
                    'to' => $scope->to->toIso8601String(),
                    'previous_from' => $scope->previousFrom->toIso8601String(),
                    'previous_to' => $scope->previousTo->toIso8601String(),
                    'timezone' => $scope->timezone,
                    'scope' => $scope->scope,
                ],
                ...$data,
            ],
        ]);
    }
}

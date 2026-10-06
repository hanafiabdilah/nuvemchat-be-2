<?php

namespace App\Services\Statistics;

use App\Models\Tenant;
use Illuminate\Database\Query\Builder;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * What the workspace sold in the period, and where it came from.
 *
 * Reads the `sales` ledger (App\Services\Sales\SalesLedger) and the ads
 * conversations started from. One currency only — the workspace's own: a sum
 * across currencies is not a number, and showing one is worse than leaving the
 * strangers out.
 *
 * Ad spend is deliberately not here. It lives in the ad account, not in this
 * platform, so there is no ROAS on this page until that account is connected.
 */
final class SalesStats
{
    public const GOAL_PERIODS = ['week', 'month', 'total'];

    private string $currency;

    public function __construct(
        private readonly StatsScope $scope,
        private readonly Tenant $tenant,
    ) {
        $this->currency = $tenant->currency();
    }

    public function build(): array
    {
        $current = $this->totals($this->scope->from, $this->scope->to);
        $referrals = $this->referrals($this->scope->from, $this->scope->to);

        return [
            'currency' => $this->currency,
            'totals' => $current,
            'previous' => $this->totals($this->scope->previousFrom, $this->scope->previousTo),
            'by_kind' => $this->byKind(),
            'by_offer' => $this->byOffer(),
            'daily' => $this->daily(),
            'ads' => [
                'leads' => (int) $referrals->sum('leads'),
                'leads_previous' => (int) $this->referrals($this->scope->previousFrom, $this->scope->previousTo)->sum('leads'),
                'rows' => $this->byAd($referrals),
            ],
            'goal' => self::goal($this->tenant, $this->scope->timezone),
        ];
    }

    private function sales(Carbon $from, Carbon $to): Builder
    {
        return DB::table('sales')
            ->where('sales.tenant_id', $this->scope->tenantId)
            ->where('sales.currency', $this->currency)
            ->whereBetween('sales.sold_at', [$from, $to])
            ->whereIn('sales.connection_id', $this->scope->connections()->select('connections.id'));
    }

    private function totals(Carbon $from, Carbon $to): array
    {
        $row = $this->sales($from, $to)
            ->selectRaw('COALESCE(SUM(amount_cents), 0) as revenue, COUNT(*) as sales, COUNT(DISTINCT contact_id) as buyers')
            ->first();

        $sales = (int) $row->sales;

        return [
            'revenue_cents' => (int) $row->revenue,
            'sales' => $sales,
            'buyers' => (int) $row->buyers,
            'avg_ticket_cents' => $sales > 0 ? (int) round($row->revenue / $sales) : 0,
        ];
    }

    private function byKind(): array
    {
        $rows = $this->sales($this->scope->from, $this->scope->to)
            ->groupBy('kind')
            ->selectRaw('kind, SUM(amount_cents) as revenue, COUNT(*) as sales')
            ->get()
            ->keyBy(fn ($row) => $row->kind ?? 'unclassified');

        // Always the three, in this order: a bar that disappears when it is
        // zero reads as a category that does not exist.
        return collect(['front', 'upsell', 'unclassified'])->map(fn (string $kind) => [
            'kind' => $kind,
            'revenue_cents' => (int) ($rows[$kind]->revenue ?? 0),
            'sales' => (int) ($rows[$kind]->sales ?? 0),
        ])->all();
    }

    private function byOffer(): array
    {
        return $this->sales($this->scope->from, $this->scope->to)
            ->whereNotNull('offer')
            ->groupBy('offer', 'kind')
            ->selectRaw('offer, kind, SUM(amount_cents) as revenue, COUNT(*) as sales')
            ->orderByDesc('revenue')
            ->limit(15)
            ->get()
            ->map(fn ($row) => [
                'offer' => $row->offer,
                'kind' => $row->kind,
                'revenue_cents' => (int) $row->revenue,
                'sales' => (int) $row->sales,
            ])->all();
    }

    private function daily(): array
    {
        $day = $this->scope->date('sales.sold_at');

        $rows = $this->sales($this->scope->from, $this->scope->to)
            ->groupBy(DB::raw($day))
            ->selectRaw("{$day} as day, SUM(amount_cents) as revenue, COUNT(*) as sales")
            ->get()
            ->keyBy('day');

        return collect($this->scope->days())->map(fn (string $date) => [
            'day' => $date,
            'revenue_cents' => (int) ($rows[$date]->revenue ?? 0),
            'sales' => (int) ($rows[$date]->sales ?? 0),
        ])->all();
    }

    /** Conversations that started from an ad in the period, per ad. */
    private function referrals(Carbon $from, Carbon $to)
    {
        return DB::table('ad_referrals')
            ->where('ad_referrals.tenant_id', $this->scope->tenantId)
            ->whereBetween('ad_referrals.referred_at', [$from, $to])
            ->whereIn('ad_referrals.connection_id', $this->scope->connections()->select('connections.id'))
            ->groupBy('ad_id')
            ->selectRaw('ad_id, COUNT(*) as leads, MAX(title) as title, MAX(source_url) as source_url')
            ->get();
    }

    /**
     * Leads and sales side by side, per ad.
     *
     * The two are counted over the same period but are not the same people:
     * leads are conversations that *began* in it, sales are payments
     * *confirmed* in it. On a long enough range they converge; on one day they
     * need not.
     */
    private function byAd($referrals): array
    {
        $sales = $this->sales($this->scope->from, $this->scope->to)
            ->whereNotNull('ad_id')
            ->groupBy('ad_id')
            ->selectRaw('ad_id, SUM(amount_cents) as revenue, COUNT(*) as sales')
            ->get()
            ->keyBy('ad_id');

        $leads = $referrals->whereNotNull('ad_id')->keyBy('ad_id');

        return $leads->keys()->merge($sales->keys())->unique()->map(function ($adId) use ($leads, $sales) {
            $leadCount = (int) ($leads[$adId]->leads ?? 0);
            $saleCount = (int) ($sales[$adId]->sales ?? 0);

            return [
                'ad_id' => (string) $adId,
                'title' => $leads[$adId]->title ?? null,
                'source_url' => $leads[$adId]->source_url ?? null,
                'leads' => $leadCount,
                'sales' => $saleCount,
                'revenue_cents' => (int) ($sales[$adId]->revenue ?? 0),
                'conversion_pct' => $leadCount > 0 ? round($saleCount / $leadCount * 100, 1) : null,
            ];
        })->sortByDesc(fn (array $row) => [$row['revenue_cents'], $row['leads']])->values()->take(50)->all();
    }

    /**
     * The revenue target and how far along it is.
     *
     * Measured over the goal's own window — this week, this month, or since
     * the beginning — not over whatever range the page is filtered to: a goal
     * for the month does not shrink because somebody is looking at yesterday.
     */
    public static function goal(Tenant $tenant, string $timezone): ?array
    {
        $goal = self::normalizeGoal($tenant->sales_goal);

        if ($goal === null) {
            return null;
        }

        $now = Carbon::now($timezone);
        [$from, $to] = match ($goal['period']) {
            'week' => [$now->copy()->startOfWeek(), $now->copy()->endOfWeek()],
            'month' => [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()],
            default => [null, null],
        };

        $revenue = (int) DB::table('sales')
            ->where('tenant_id', $tenant->id)
            ->where('currency', $tenant->currency())
            ->when($from, fn ($query) => $query->whereBetween('sold_at', [$from->copy()->utc(), $to->copy()->utc()]))
            ->sum('amount_cents');

        return $goal + [
            'from' => $from?->toDateString(),
            'to' => $to?->toDateString(),
            'revenue_cents' => $revenue,
            'pct' => round($revenue / $goal['amount_cents'] * 100, 1),
        ];
    }

    /** @return array{amount_cents: int, period: string}|null */
    public static function normalizeGoal(mixed $raw): ?array
    {
        $amount = (int) (is_array($raw) ? ($raw['amount_cents'] ?? 0) : 0);

        if ($amount <= 0) {
            return null;
        }

        $period = $raw['period'] ?? 'month';

        return [
            'amount_cents' => $amount,
            'period' => in_array($period, self::GOAL_PERIODS, true) ? $period : 'month',
        ];
    }
}

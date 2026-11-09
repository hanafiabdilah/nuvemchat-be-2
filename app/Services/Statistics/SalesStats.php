<?php

namespace App\Services\Statistics;

use App\Models\Tenant;
use App\Services\MetaAds\AdSpend;
use App\Services\MetaAds\MetaAdsService;
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
 * Ad spend comes from the workspace's connected Meta ad accounts
 * (App\Services\MetaAds). Without one, `ads.spend` is null and the page says
 * where to connect it: the cost of an ad lives in the ad account, and there is
 * no ROAS without it.
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
        $spend = $this->spend($this->scope->from, $this->scope->to);
        $leads = (int) $referrals->sum('leads');

        return [
            'currency' => $this->currency,
            'totals' => $current,
            'previous' => $this->totals($this->scope->previousFrom, $this->scope->previousTo),
            'by_kind' => $this->byKind(),
            'by_offer' => $this->byOffer(),
            'daily' => $this->daily(),
            'ads' => [
                'leads' => $leads,
                'leads_previous' => (int) $this->referrals($this->scope->previousFrom, $this->scope->previousTo)->sum('leads'),
                'spend' => $this->spendSummary($spend, $leads),
                'rows' => $this->byAd($referrals, $spend),
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
    private function byAd($referrals, ?array $spend): array
    {
        $sales = $this->sales($this->scope->from, $this->scope->to)
            ->whereNotNull('ad_id')
            ->groupBy('ad_id')
            ->selectRaw('ad_id, SUM(amount_cents) as revenue, COUNT(*) as sales')
            ->get()
            ->keyBy('ad_id');

        $leads = $referrals->whereNotNull('ad_id')->keyBy('ad_id');

        $costs = $spend['by_ad'] ?? [];

        // With an ad account connected, an ad that cost money and brought
        // nothing is exactly the row somebody needs to see — so ads with spend
        // only are listed too, and the most expensive come first.
        $ids = $leads->keys()->merge($sales->keys())->merge(array_keys($costs))->map(fn ($id) => (string) $id)->unique();

        $rows = $ids->map(function (string $adId) use ($leads, $sales, $costs, $spend) {
            $leadCount = (int) ($leads[$adId]->leads ?? 0);
            $saleCount = (int) ($sales[$adId]->sales ?? 0);
            $revenue = (int) ($sales[$adId]->revenue ?? 0);
            $cost = $spend === null ? null : (int) ($costs[$adId]['spend_cents'] ?? 0);

            return [
                'ad_id' => $adId,
                // Meta's own name for the ad when it is known: the referral
                // title is the card the customer clicked, often shared by
                // several ads.
                'title' => $costs[$adId]['name'] ?? $leads[$adId]->title ?? null,
                'campaign' => $costs[$adId]['campaign'] ?? null,
                'source_url' => $leads[$adId]->source_url ?? null,
                'leads' => $leadCount,
                'sales' => $saleCount,
                'revenue_cents' => $revenue,
                'conversion_pct' => $leadCount > 0 ? round($saleCount / $leadCount * 100, 1) : null,
                'spend_cents' => $cost,
                'cost_per_lead_cents' => $cost !== null && $leadCount > 0 ? (int) round($cost / $leadCount) : null,
                'roas' => $cost !== null ? AdSpend::roas($revenue, $cost) : null,
            ];
        });

        $rows = $spend === null
            ? $rows->sortByDesc(fn (array $row) => [$row['revenue_cents'], $row['leads']])
            : $rows->sortByDesc(fn (array $row) => [$row['spend_cents'], $row['revenue_cents'], $row['leads']]);

        return $rows->values()->take(50)->all();
    }

    /**
     * What the connected ad accounts spent in the period (null when none is
     * connected, or the plan does not include Meta Ads).
     */
    private function spend(Carbon $from, Carbon $to): ?array
    {
        if (! app(MetaAdsService::class)->inPlan($this->tenant)) {
            return null;
        }

        $spend = AdSpend::between(
            $this->tenant,
            $from->copy()->setTimezone($this->scope->timezone)->toDateString(),
            $to->copy()->setTimezone($this->scope->timezone)->toDateString(),
            $this->currency,
        );

        return $spend['connected'] ? $spend : null;
    }

    /**
     * Spend against what the ads brought in.
     *
     * Revenue here is only what is attributed to an ad (`sales.ad_id`), the
     * same money the per-ad table adds up — ROAS against all revenue would
     * credit the ads with sales that came from somewhere else.
     *
     * `filtered`: the connection and channel filters narrow leads and sales,
     * but an ad's cost belongs to the ad account, not to a connection — so
     * with a filter on, spend is still the whole account's, and the page says so.
     */
    private function spendSummary(?array $spend, int $leads): ?array
    {
        if ($spend === null) {
            return null;
        }

        $attributed = $this->sales($this->scope->from, $this->scope->to)
            ->whereNotNull('ad_id')
            ->selectRaw('COALESCE(SUM(amount_cents), 0) as revenue, COUNT(*) as sales')
            ->first();

        $revenue = (int) $attributed->revenue;
        $sales = (int) $attributed->sales;
        $total = $spend['total_cents'];
        $previous = $this->spend($this->scope->previousFrom, $this->scope->previousTo);

        return [
            'total_cents' => $total,
            'previous_cents' => $previous['total_cents'] ?? 0,
            'revenue_cents' => $revenue,
            'sales' => $sales,
            'cost_per_lead_cents' => $leads > 0 ? (int) round($total / $leads) : null,
            'cost_per_sale_cents' => $sales > 0 ? (int) round($total / $sales) : null,
            'roas' => AdSpend::roas($revenue, $total),
            'unconverted_currencies' => $spend['unconverted'],
            'synced_at' => $spend['synced_at']?->toIso8601String(),
            'filtered' => $this->scope->connectionIds !== [] || $this->scope->channels !== [],
        ];
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

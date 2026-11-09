<?php

namespace App\Services\MetaAds;

use App\Models\MetaAdAccount;
use App\Models\MetaAdInsight;
use App\Models\Tenant;
use App\Services\Money\ExchangeRates;
use Illuminate\Support\Carbon;

/**
 * What a workspace's ads cost over a range of days, per ad, in the
 * workspace's own currency.
 *
 * Read by Statistics → Sales and by the ads summary on WhatsApp, so both say
 * the same number. Dates are calendar days as Meta reports them (the ad
 * account's timezone); an account in another currency is converted with the
 * platform's exchange rates, and one with no rate is left out and named
 * rather than added as if it were the same money.
 */
final class AdSpend
{
    /**
     * @return array{
     *     connected: bool,
     *     total_cents: int,
     *     by_ad: array<string, array{spend_cents: int, name: ?string, campaign: ?string}>,
     *     unconverted: list<string>,
     *     synced_at: ?Carbon,
     * }
     */
    public static function between(Tenant $tenant, string $fromDate, string $toDate, string $currency): array
    {
        $accounts = MetaAdAccount::where('tenant_id', $tenant->id)->where('enabled', true)->get(['id', 'last_synced_at']);

        if ($accounts->isEmpty()) {
            return ['connected' => false, 'total_cents' => 0, 'by_ad' => [], 'unconverted' => [], 'synced_at' => null];
        }

        $rows = MetaAdInsight::query()
            ->whereIn('meta_ad_account_id', $accounts->pluck('id'))
            ->whereBetween('date', [$fromDate, $toDate])
            ->groupBy('ad_id', 'currency')
            ->selectRaw('ad_id, currency, SUM(spend_cents) as spend, MAX(ad_name) as ad_name, MAX(campaign_name) as campaign_name')
            ->get();

        $byAd = [];
        $unconverted = [];
        $total = 0;

        foreach ($rows as $row) {
            $spend = strtoupper($row->currency) === strtoupper($currency)
                ? (int) $row->spend
                : ExchangeRates::convert((int) $row->spend, strtoupper($row->currency), strtoupper($currency));

            if ($spend === null) {
                $unconverted[] = strtoupper($row->currency);

                continue;
            }

            $byAd[$row->ad_id] ??= ['spend_cents' => 0, 'name' => $row->ad_name, 'campaign' => $row->campaign_name];
            $byAd[$row->ad_id]['spend_cents'] += $spend;
            $total += $spend;
        }

        return [
            'connected' => true,
            'total_cents' => $total,
            'by_ad' => $byAd,
            'unconverted' => array_values(array_unique($unconverted)),
            'synced_at' => $accounts->max('last_synced_at'),
        ];
    }

    /** "Returned this many times what it cost", to two decimals; null with no spend. */
    public static function roas(int $revenueCents, int $spendCents): ?float
    {
        return $spendCents > 0 ? round($revenueCents / $spendCents, 2) : null;
    }
}

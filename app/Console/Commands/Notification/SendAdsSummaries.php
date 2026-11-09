<?php

namespace App\Console\Commands\Notification;

use App\Enums\Notification\NotificationType;
use App\Models\Connection;
use App\Models\MetaAdAccount;
use App\Models\User;
use App\Services\MetaAds\AdSpend;
use App\Services\MetaAds\MetaAdsService;
use App\Services\Notification\WhatsappAlerts;
use App\Support\Heartbeat;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The operation summary people opt into for their WhatsApp: what the ads cost,
 * how many leads and sales they brought, and the ROAS — every morning for the
 * day before, or on Monday mornings for the week before.
 *
 * Only for workspaces with a Meta ad account connected, and only for people
 * who may see the Statistics pages: ad spend and revenue are the workspace's
 * money. Leads and sales count on the connections the person can reach, the
 * same rule as the leads summary; spend is the ad account's, whole.
 */
class SendAdsSummaries extends Command
{
    protected $signature = 'alerts:ads-summary';

    protected $description = 'Send the daily and weekly ads summaries (spend, leads, sales, ROAS) people asked for on WhatsApp';

    public function handle(MetaAdsService $metaAds): int
    {
        Heartbeat::ping('alerts:ads-summary');

        if (! MetaAdsService::enabled()) {
            return self::SUCCESS;
        }

        $tenants = MetaAdAccount::where('enabled', true)->distinct()->pluck('tenant_id')->all();

        if ($tenants === []) {
            return self::SUCCESS;
        }

        $sent = 0;

        User::whereIn('tenant_id', $tenants)
            ->whereNotNull('whatsapp_verified_at')
            ->whereNotNull('whatsapp_number')
            ->whereNotNull('notification_preferences')
            ->with('tenant.market')
            ->get()
            ->filter(fn (User $user) => $user->tenant
                && WhatsappAlerts::wants($user, WhatsappAlerts::ADS_SUMMARY)
                && $user->can('statistics.tenant.view')
                && $metaAds->inPlan($user->tenant))
            ->each(function (User $user) use (&$sent) {
                if ($this->summarise($user)) {
                    $sent++;
                }
            });

        $this->info("Sent {$sent} ads summary(ies).");

        return self::SUCCESS;
    }

    private function summarise(User $user): bool
    {
        $mode = $user->notificationSettings()['whatsapp_alerts'][WhatsappAlerts::ADS_SUMMARY];
        $timezone = $user->tenant->displayTimezone();
        $now = now()->timezone($timezone);

        if ($now->hour !== WhatsappAlerts::DAILY_SUMMARY_HOUR) {
            return false;
        }

        if ($mode === WhatsappAlerts::SUMMARY_WEEKLY) {
            if (! $now->isMonday()) {
                return false;
            }

            $to = $now->copy()->startOfDay();
            $from = $to->copy()->subWeek();
        } else {
            $to = $now->copy()->startOfDay();
            $from = $to->copy()->subDay();
        }

        // Once per person and period, however often the scheduler fires.
        if (! Cache::add("alert:ads-summary:{$user->id}:{$mode}:{$from->timestamp}", true, now()->addDays(8))) {
            return false;
        }

        $currency = $user->tenant->currency();
        $lastDay = $to->copy()->subDay();
        $spend = AdSpend::between($user->tenant, $from->toDateString(), $lastDay->toDateString(), $currency);

        $connectionIds = Connection::where('tenant_id', $user->tenant_id)
            ->get()
            ->filter(fn (Connection $connection) => $user->canAccessConnection($connection))
            ->modelKeys();

        $leads = $connectionIds === [] ? 0 : DB::table('ad_referrals')
            ->where('tenant_id', $user->tenant_id)
            ->whereIn('connection_id', $connectionIds)
            ->where('referred_at', '>=', $from->copy()->utc())
            ->where('referred_at', '<', $to->copy()->utc())
            ->count();

        $sales = DB::table('sales')
            ->where('tenant_id', $user->tenant_id)
            ->whereIn('connection_id', $connectionIds ?: [0])
            ->where('currency', $currency)
            ->whereNotNull('ad_id')
            ->where('sold_at', '>=', $from->copy()->utc())
            ->where('sold_at', '<', $to->copy()->utc())
            ->selectRaw('COUNT(*) as total, COALESCE(SUM(amount_cents), 0) as amount')
            ->first();

        $salesCount = (int) ($sales->total ?? 0);
        $revenue = (int) ($sales->amount ?? 0);
        $total = $spend['total_cents'];

        // No ad ran and nothing came from one: not news, just noise.
        if ($total === 0 && $leads === 0 && $salesCount === 0) {
            return false;
        }

        $locale = $user->localeCode();
        $roas = AdSpend::roas($revenue, $total);

        return WhatsappAlerts::send(NotificationType::AlertAdsSummary, $user, [
            'period' => $mode === WhatsappAlerts::SUMMARY_WEEKLY
                ? __('alerts.period_week', [
                    'from' => $from->copy()->locale($locale)->isoFormat('D MMM'),
                    'to' => $lastDay->copy()->locale($locale)->isoFormat('D MMM'),
                ], $locale)
                : __('alerts.period_day', ['date' => $from->copy()->locale($locale)->isoFormat('D MMM')], $locale),
            'spend' => Money::format($total, $currency),
            'leads' => (string) $leads,
            'sales' => (string) $salesCount,
            'revenue' => Money::format($revenue, $currency),
            'roas' => $this->ratio($roas, $locale),
            'details' => $this->details($spend, $leads, $salesCount, $currency, $locale, $from, $lastDay, $user),
        ]);
    }

    /** "2,35x" — or "2.35x" for English readers; a dash when nothing was spent. */
    private function ratio(?float $roas, string $locale): string
    {
        if ($roas === null) {
            return '—';
        }

        return str_starts_with($locale, 'en')
            ? number_format($roas, 2, '.', ',').'x'
            : number_format($roas, 2, ',', '.').'x';
    }

    /** Cost per lead and per sale, the ad that cost most, and any account left out. */
    private function details(array $spend, int $leads, int $sales, string $currency, string $locale, CarbonInterface $from, CarbonInterface $to, User $user): string
    {
        $lines = [];
        $total = $spend['total_cents'];

        if ($total > 0 && $leads > 0) {
            $lines[] = __('alerts.details_cost_per_lead', ['amount' => Money::format((int) round($total / $leads), $currency)], $locale);
        }

        if ($total > 0 && $sales > 0) {
            $lines[] = __('alerts.details_cost_per_sale', ['amount' => Money::format((int) round($total / $sales), $currency)], $locale);
        }

        $top = collect($spend['by_ad'])->sortByDesc('spend_cents')->keys()->first();

        if ($top !== null && $spend['by_ad'][$top]['spend_cents'] > 0) {
            $revenue = (int) DB::table('sales')
                ->where('tenant_id', $user->tenant_id)
                ->where('currency', $currency)
                ->where('ad_id', (string) $top)
                ->whereBetween('sold_at', [$from->copy()->startOfDay()->utc(), $to->copy()->endOfDay()->utc()])
                ->sum('amount_cents');
            $roas = AdSpend::roas($revenue, $spend['by_ad'][$top]['spend_cents']);

            $lines[] = __('alerts.details_top_ad', [
                'ad' => $spend['by_ad'][$top]['name'] ?: (string) $top,
                'spend' => Money::format($spend['by_ad'][$top]['spend_cents'], $currency),
                'roas' => $this->ratio($roas, $locale),
            ], $locale);
        }

        if ($spend['unconverted'] !== []) {
            $lines[] = __('alerts.details_unconverted', ['currencies' => implode(', ', $spend['unconverted'])], $locale);
        }

        return $lines === [] ? '' : "\n\n".implode("\n", $lines);
    }
}

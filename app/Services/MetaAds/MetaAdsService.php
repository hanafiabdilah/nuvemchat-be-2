<?php

namespace App\Services\MetaAds;

use App\Jobs\SyncMetaAdAccount;
use App\Models\MetaAdAccount;
use App\Models\MetaAdInsight;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Billing\SubscriptionGate;
use App\Services\Connection\Meta\FacebookConfig;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * A workspace's Meta ad accounts: connecting them through Pingly's Meta app,
 * and keeping each ad's daily cost in `meta_ad_insights`.
 *
 * Why it exists: the platform already knows which ad a conversation started
 * from (ad_referrals) and what that conversation sold (sales). The one number
 * it could not know is what the ad cost — that lives in the ad account. With
 * it, the Sales tab can show ad spend, cost per lead and ROAS, and the
 * operation summary on WhatsApp can report them.
 *
 * Connecting is a Facebook Login for Business popup with its own
 * configuration (FacebookConfig::adsConfigId, `ads_read`), landing on the
 * same callback route as Messenger — one redirect URI whitelisted in the Meta
 * app, told apart by `state.channel`. The state carries a one-time nonce, not
 * the workspace id: a forged state must not be able to attach somebody's ad
 * account to another workspace.
 */
class MetaAdsService
{
    public const STATE_CHANNEL = 'meta_ads';

    /** A first connection brings this much history, so the page has something to show. */
    public const BACKFILL_DAYS = 90;

    /** The scheduled sync re-reads the last days: Meta keeps adjusting recent costs. */
    public const SYNC_DAYS = 3;

    /** Insights are asked for in windows this wide, so a busy account never hits Meta's "too much data". */
    private const WINDOW_DAYS = 7;

    private const NONCE_TTL_MINUTES = 20;

    public function __construct(
        private MetaAdsClient $client,
        private SubscriptionGate $gate,
    ) {}

    /** The platform switch (config/meta_ads.php, META_ADS_ENABLED). Off = "coming soon". */
    public static function enabled(): bool
    {
        return (bool) config('meta_ads.enabled', true);
    }

    /** Whether the platform has what connecting needs: the switch on, the Meta app and its ads login configuration. */
    public static function available(): bool
    {
        return self::enabled()
            && filled(FacebookConfig::appId())
            && filled(FacebookConfig::appSecret())
            && filled(FacebookConfig::adsConfigId());
    }

    /**
     * Whether this workspace may use it: the platform switch is on and the
     * plan includes it (always, with billing enforcement off).
     */
    public function inPlan(Tenant $tenant): bool
    {
        if (! self::enabled()) {
            return false;
        }

        return ! config('services.billing.enforce') || $this->gate->feature($tenant, 'meta_ads');
    }

    /** The popup URL. Valid for one login, for this workspace and this person. */
    public function oauthUrl(Tenant $tenant, User $user): string
    {
        $nonce = Str::random(40);

        Cache::put($this->nonceKey($nonce), [
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
        ], now()->addMinutes(self::NONCE_TTL_MINUTES));

        $state = base64_encode(json_encode(['channel' => self::STATE_CHANNEL, 'nonce' => $nonce]));

        // auth_type=rerequest so the asset picker shows again on a second
        // login — the way to add an ad account left out the first time.
        return 'https://www.facebook.com/'.FacebookConfig::graphVersion().'/dialog/oauth?'.http_build_query([
            'client_id' => FacebookConfig::appId(),
            'redirect_uri' => FacebookConfig::redirectUri(),
            'response_type' => 'code',
            'auth_type' => 'rerequest',
            'config_id' => FacebookConfig::adsConfigId(),
            'state' => $state,
        ]);
    }

    /**
     * The popup came back. Store the ad accounts it shared and start reading
     * their history.
     *
     * @return list<MetaAdAccount> the accounts connected by this login
     *
     * @throws MetaAdsException with a message the popup can show
     */
    public function completeLogin(?string $nonce, ?string $code): array
    {
        if (! self::enabled()) {
            throw new MetaAdsException('Connecting Meta ad accounts is not available yet.');
        }

        $context = $nonce ? Cache::pull($this->nonceKey($nonce)) : null;

        if (! is_array($context) || ! $code) {
            throw new MetaAdsException('This connection link expired. Close this window and click Connect again.');
        }

        $tenant = Tenant::find($context['tenant_id']);

        if (! $tenant) {
            throw new MetaAdsException('This connection link expired. Close this window and click Connect again.');
        }

        $token = $this->client->exchangeCode($code);
        $fbUserId = $this->client->me($token);
        $shared = $this->client->adAccounts($token);

        if ($shared === []) {
            throw new MetaAdsException('No ad account was shared. Connect again and select at least one ad account in the Facebook window.');
        }

        $accounts = DB::transaction(function () use ($shared, $tenant, $token, $fbUserId, $context) {
            return array_map(function (array $row) use ($tenant, $token, $fbUserId, $context) {
                $account = MetaAdAccount::firstOrNew(['tenant_id' => $tenant->id, 'account_id' => $row['account_id']]);

                $account->fill([
                    'name' => $row['name'],
                    'currency' => $row['currency'],
                    'timezone' => $row['timezone'],
                    'access_token' => $token,
                    'fb_user_id' => $fbUserId,
                    'connected_by_user_id' => $context['user_id'],
                    'status' => MetaAdAccount::STATUS_ACTIVE,
                    'last_error' => null,
                ]);

                // A reconnect keeps what somebody switched off.
                if (! $account->exists) {
                    $account->enabled = true;
                }

                $account->save();

                return $account;
            }, $shared);
        });

        foreach ($accounts as $account) {
            if ($account->enabled) {
                SyncMetaAdAccount::dispatch($account->id, self::BACKFILL_DAYS);
            }
        }

        Log::info('MetaAdsService: ad accounts connected', [
            'tenant_id' => $tenant->id,
            'accounts' => array_map(fn (MetaAdAccount $account) => $account->account_id, $accounts),
        ]);

        return $accounts;
    }

    /**
     * Re-read the last `$days` days of an account's costs and replace what is
     * stored for them.
     *
     * Replace, not merge: Meta reports only ads that spent, so an ad whose
     * cost for a day was corrected to zero would otherwise keep its old row.
     *
     * @return int rows stored
     */
    public function sync(MetaAdAccount $account, int $days): int
    {
        $timezone = $this->timezoneOf($account);
        $until = Carbon::now($timezone)->startOfDay();
        $since = $until->copy()->subDays(max(1, $days) - 1);

        try {
            $rows = [];

            for ($from = $since->copy(); $from->lte($until); $from->addDays(self::WINDOW_DAYS)) {
                $to = $from->copy()->addDays(self::WINDOW_DAYS - 1)->min($until);

                foreach ($this->client->dailyAdInsights($account->account_id, $account->access_token, $from->toDateString(), $to->toDateString()) as $row) {
                    // Keyed by ad and day: one row each, even if two windows
                    // or two pages ever hand back the same one.
                    if ($normalized = $this->row($account, $row)) {
                        $rows[$normalized['ad_id'].'|'.$normalized['date']] = $normalized;
                    }
                }
            }
        } catch (MetaAdsException $e) {
            $account->forceFill([
                'status' => $e->needsLogin ? MetaAdAccount::STATUS_REAUTH : MetaAdAccount::STATUS_ERROR,
                'last_error' => Str::limit($e->getMessage(), 480),
            ])->save();

            throw $e;
        }

        DB::transaction(function () use ($account, $since, $until, $rows) {
            MetaAdInsight::where('meta_ad_account_id', $account->id)
                ->whereBetween('date', [$since->toDateString(), $until->toDateString()])
                ->delete();

            foreach (array_chunk(array_values($rows), 500) as $chunk) {
                MetaAdInsight::insert($chunk);
            }

            $account->forceFill([
                'status' => MetaAdAccount::STATUS_ACTIVE,
                'last_error' => null,
                'last_synced_at' => now(),
            ])->save();
        });

        return count($rows);
    }

    /** Forget the workspace's ad accounts and every cost read from them. */
    public function disconnect(Tenant $tenant): int
    {
        // Insights go with their account (foreign key cascade).
        return MetaAdAccount::where('tenant_id', $tenant->id)->delete();
    }

    /** @param  array<string, mixed>  $row */
    private function row(MetaAdAccount $account, array $row): ?array
    {
        $adId = (string) ($row['ad_id'] ?? '');
        $date = $row['date_start'] ?? null;

        if ($adId === '' || ! is_string($date)) {
            return null;
        }

        $currency = strtoupper((string) ($row['account_currency'] ?? $account->currency ?? ''));

        if ($currency === '') {
            return null;
        }

        $now = now();

        return [
            'tenant_id' => $account->tenant_id,
            'meta_ad_account_id' => $account->id,
            'date' => $date,
            'ad_id' => $adId,
            'ad_name' => isset($row['ad_name']) ? Str::limit((string) $row['ad_name'], 250, '') : null,
            'campaign_id' => isset($row['campaign_id']) ? (string) $row['campaign_id'] : null,
            'campaign_name' => isset($row['campaign_name']) ? Str::limit((string) $row['campaign_name'], 250, '') : null,
            // Meta writes the amount in major units ("12.34"); every amount on
            // this platform is in hundredths, whatever the currency.
            'spend_cents' => (int) round(((float) ($row['spend'] ?? 0)) * 100),
            'currency' => $currency,
            'impressions' => (int) ($row['impressions'] ?? 0),
            'clicks' => (int) ($row['clicks'] ?? 0),
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    private function timezoneOf(MetaAdAccount $account): string
    {
        $timezone = $account->timezone;

        return $timezone && in_array($timezone, timezone_identifiers_list(), true) ? $timezone : 'UTC';
    }

    private function nonceKey(string $nonce): string
    {
        return 'meta-ads:oauth:'.hash('sha256', $nonce);
    }
}

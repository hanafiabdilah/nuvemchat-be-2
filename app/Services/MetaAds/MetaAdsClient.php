<?php

namespace App\Services\MetaAds;

use App\Services\Connection\Meta\FacebookConfig;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The few Graph API calls Meta Ads metrics need: the login exchange, the ad
 * accounts the person shared, and the cost of each ad per day.
 */
class MetaAdsClient
{
    /** Bound on following `paging.next`: a runaway loop must not hold a worker. */
    private const MAX_PAGES = 60;

    /** Graph codes that mean the token itself is no longer accepted. */
    private const LOGIN_CODES = [102, 190, 200, 10];

    private function base(): string
    {
        return 'https://graph.facebook.com/'.FacebookConfig::graphVersion();
    }

    /** The code from the login popup, for the token it stands for. */
    public function exchangeCode(string $code): string
    {
        $response = Http::asForm()->timeout(20)->post($this->base().'/oauth/access_token', [
            'client_id' => FacebookConfig::appId(),
            'client_secret' => FacebookConfig::appSecret(),
            'redirect_uri' => FacebookConfig::redirectUri(),
            'code' => $code,
        ]);

        $token = $this->decode($response, 'exchange the login code')['access_token'] ?? null;

        if (! is_string($token) || $token === '') {
            throw new MetaAdsException('Meta did not return an access token.', fromMeta: true);
        }

        return $token;
    }

    /** The id of whoever the token belongs to (with a system user token, the system user). */
    public function me(string $token): ?string
    {
        $response = Http::timeout(20)->get($this->base().'/me', ['fields' => 'id', 'access_token' => $token]);

        return $response->successful() ? ($response->json('id') ?: null) : null;
    }

    /**
     * The ad accounts this token reads — the ones picked in the login.
     *
     * @return list<array{account_id: string, name: ?string, currency: ?string, timezone: ?string}>
     */
    public function adAccounts(string $token): array
    {
        $accounts = [];

        foreach ($this->pages('/me/adaccounts', [
            'fields' => 'account_id,name,currency,timezone_name',
            'limit' => 100,
        ], $token, 'list the ad accounts') as $row) {
            $id = (string) ($row['account_id'] ?? preg_replace('/^act_/', '', (string) ($row['id'] ?? '')));

            if ($id === '') {
                continue;
            }

            $accounts[] = [
                'account_id' => $id,
                'name' => $row['name'] ?? null,
                'currency' => isset($row['currency']) ? strtoupper((string) $row['currency']) : null,
                'timezone' => $row['timezone_name'] ?? null,
            ];
        }

        return $accounts;
    }

    /**
     * What each ad cost on each day between two dates (inclusive, in the ad
     * account's own timezone — that is how Meta reports them).
     *
     * @return list<array<string, mixed>>
     */
    public function dailyAdInsights(string $accountId, string $token, string $since, string $until): array
    {
        return $this->pages("/act_{$accountId}/insights", [
            'level' => 'ad',
            'time_increment' => 1,
            'time_range' => json_encode(['since' => $since, 'until' => $until]),
            'fields' => 'ad_id,ad_name,campaign_id,campaign_name,spend,impressions,clicks,account_currency',
            'limit' => 500,
        ], $token, 'read the ad costs');
    }

    /**
     * Every row of a paged Graph list.
     *
     * @param  array<string, mixed>  $query
     * @return list<array<string, mixed>>
     */
    private function pages(string $path, array $query, string $token, string $action): array
    {
        $rows = [];
        $response = Http::timeout(30)->get($this->base().$path, $query + ['access_token' => $token]);

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $data = $this->decode($response, $action);
            array_push($rows, ...array_values(array_filter($data['data'] ?? [], 'is_array')));

            $next = $data['paging']['next'] ?? null;

            if (! is_string($next) || $next === '') {
                return $rows;
            }

            // `next` already carries the token and every parameter.
            $response = Http::timeout(30)->get($next);
        }

        Log::warning('MetaAdsClient: stopped following pages', ['path' => $path, 'pages' => self::MAX_PAGES]);

        return $rows;
    }

    /** @return array<string, mixed> */
    private function decode(Response $response, string $action): array
    {
        if ($response->successful()) {
            return $response->json() ?? [];
        }

        $error = $response->json('error') ?? [];
        $code = isset($error['code']) ? (int) $error['code'] : null;
        $subcode = isset($error['error_subcode']) ? (int) $error['error_subcode'] : null;

        Log::warning('MetaAdsClient: Meta refused a call', [
            'action' => $action,
            'status' => $response->status(),
            'code' => $code,
            'subcode' => $subcode,
            'message' => $error['message'] ?? $response->body(),
        ]);

        $needsLogin = in_array($code, self::LOGIN_CODES, true) || in_array($subcode, [458, 459, 460, 463, 464, 467], true);

        throw new MetaAdsException(
            "Meta refused to {$action}: ".($error['message'] ?? 'HTTP '.$response->status()),
            $needsLogin,
            $code,
            fromMeta: true,
        );
    }
}

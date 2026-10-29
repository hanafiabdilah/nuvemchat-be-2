<?php

namespace App\Services\TikTok;

use App\Exceptions\TikTokApiException;
use App\Models\Setting;
use App\Services\Connection\TikTok\TikTokAuthClient;
use App\Services\Connection\TikTok\TikTokConfig;
use App\Services\Media\MediaStorage;
use Illuminate\Support\Facades\Http;

/**
 * Proving to TikTok that the address our media is published from is ours.
 *
 * TikTok publishes from a URL it downloads, and since Nov 2023 it refuses any
 * URL outside a "URL property" the developer app has verified. Of the two kinds
 * (a whole domain by DNS TXT record, or a URL prefix by a file served under
 * it) the prefix is the one this platform can complete on its own: the
 * signature file is written to the very disk the media is published from, so
 * verifying is one button in the Back Office instead of a DNS change.
 *
 * The prefix is derived, never typed: it is wherever MediaStorage publishes
 * `tiktok/` today. If the media disk moves, the stored prefix stops matching,
 * the Back Office says so, and the same button verifies the new one.
 *
 * These calls are app-level (app id + secret, since Oct 2025), not per account.
 */
class TikTokUrlProperty
{
    public const DIRECTORY = 'tiktok';

    /** TikTok's own type number for a URL prefix (1 = domain). */
    private const TYPE_URL_PREFIX = 2;

    /** Where TikTok media is published today, as the prefix TikTok will check. */
    public static function currentPrefix(): string
    {
        $probe = MediaStorage::publishedUrl(self::DIRECTORY.'/probe');

        return substr($probe, 0, (int) strrpos($probe, '/') + 1);
    }

    /**
     * Whether a published media URL may be handed to TikTok — under the prefix
     * TikTok has verified.
     */
    public static function allows(string $url): bool
    {
        $prefix = TikTokConfig::verifiedPrefix();

        return $prefix !== null && str_starts_with($url, $prefix);
    }

    /** @return array{prefix: string, stored: ?array, verified: bool, matches: bool} */
    public function status(): array
    {
        $prefix = self::currentPrefix();
        $stored = TikTokConfig::urlProperty();

        return [
            'prefix' => $prefix,
            'stored' => $stored,
            'verified' => ($stored['status'] ?? null) === 1,
            // Verified, but for an address media is no longer published from.
            'matches' => ($stored['url'] ?? null) === $prefix,
        ];
    }

    /**
     * Add the prefix, serve its signature file, and ask TikTok to check it.
     *
     * @return array{prefix: string, stored: ?array, verified: bool, matches: bool}
     */
    public function verify(): array
    {
        $prefix = self::currentPrefix();

        $info = $this->added($prefix) ?? ($this->call('/business/property/add/', [
            'url_property_meta' => ['property_type' => self::TYPE_URL_PREFIX, 'url' => $prefix],
        ])['url_property_info'] ?? []);

        $fileName = (string) ($info['file_name'] ?? '');
        $signature = (string) ($info['signature'] ?? '');

        if ($fileName === '' || $signature === '' || str_contains($fileName, '/')) {
            throw new TikTokApiException('TikTok did not return a verification file for this address.');
        }

        MediaStorage::published()->put(self::DIRECTORY.'/'.$fileName, $signature, [
            'visibility' => 'public',
            'mimetype' => 'text/plain',
        ]);

        $result = $this->call('/business/property/verify/', [
            'url_property_meta' => ['property_type' => self::TYPE_URL_PREFIX, 'url' => $prefix],
        ])['url_property_info'] ?? [];

        $status = (int) ($result['property_status'] ?? 2);

        Setting::set(TikTokConfig::KEY_URL_PROPERTY, json_encode([
            'url' => $prefix,
            'status' => $status,
            'file_name' => $fileName,
            'verified_at' => $status === 1 ? now()->toIso8601String() : null,
            'checked_at' => now()->toIso8601String(),
        ]));

        return $this->status();
    }

    /** The prefix's entry if it was added before (adding twice is refused). */
    private function added(string $prefix): ?array
    {
        $list = $this->call('/business/property/list/', [], 'get')['url_property_info_list'] ?? [];

        foreach ($list as $info) {
            if (($info['url'] ?? null) === $prefix) {
                return $info;
            }
        }

        return null;
    }

    private function call(string $path, array $params, string $method = 'post'): array
    {
        $params = [
            'app_id' => TikTokConfig::appId(),
            'secret' => TikTokConfig::appSecret(),
        ] + $params;

        $request = Http::acceptJson()->timeout(20);
        $response = $method === 'get'
            ? $request->get(TikTokAuthClient::API_BASE.$path, $params)
            : $request->asJson()->post(TikTokAuthClient::API_BASE.$path, $params);

        $json = $response->json() ?? [];

        if (! $response->successful() || (int) ($json['code'] ?? -1) !== 0) {
            throw TikTokApiException::fromResponse($json, $response->status());
        }

        return $json['data'] ?? [];
    }
}

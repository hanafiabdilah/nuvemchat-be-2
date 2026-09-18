<?php

use App\Models\Setting;
use App\Services\Notification\NotificationConfig;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Retires the "W-API (Directly)" notification provider.
 *
 * Two jobs, and the first is the one that matters: a platform still pointed at
 * `wapi` would resolve nothing after deploy — NotificationProviderFactory throws
 * on an unknown key, inside the send job, which is where the registration OTP
 * lives. Falling back to the default at least fails the way a missing
 * configuration fails: logged as "provider not configured", visible on the
 * Notifications tab, and fixable without a deploy.
 *
 * ⚠️ `pingly` is the default but not necessarily configured on this install, so
 * after deploying check Back Office → Integrations → Notifications: that
 * provider needs an API key AND the connection id it sends through.
 *
 * Second job: the W-API credentials are an encrypted secret for a transport that
 * no longer exists. Nothing reads them, so they are only a secret left lying
 * around.
 *
 * Goes through the Setting model rather than the query builder on purpose —
 * values are encrypted and cached forever, so a raw UPDATE would leave every
 * worker reading `wapi` out of the cache, which is the exact failure this
 * migration exists to prevent.
 */
return new class extends Migration
{
    private const REMOVED_KEYS = [
        'notifications.wapi.base_url',
        'notifications.wapi.instance_id',
        'notifications.wapi.token',
    ];

    public function up(): void
    {
        if (Setting::get(NotificationConfig::KEY_PROVIDER) === 'wapi') {
            Log::warning('Notification provider was W-API; falling back to the platform default', [
                'from' => 'wapi',
                'to' => NotificationConfig::DEFAULT_PROVIDER,
            ]);

            Setting::set(NotificationConfig::KEY_PROVIDER, NotificationConfig::DEFAULT_PROVIDER);
        }

        foreach (self::REMOVED_KEYS as $key) {
            Setting::query()->where('key', $key)->delete();
            Cache::forget("setting:{$key}");
        }
    }

    public function down(): void
    {
        // Irreversible: the provider and its credentials are gone.
    }
};

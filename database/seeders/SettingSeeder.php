<?php

namespace Database\Seeders;

use App\Models\Setting;
use App\Services\Connection\Proxy\ProxyhubConfig;
use Illuminate\Database\Seeder;

class SettingSeeder extends Seeder
{
    /**
     * Seed default platform settings. ProxyHub credentials are DB-only; the
     * base URL gets a sensible default, and any existing PROXYHUB_INTEGRATOR_TOKEN
     * in .env is migrated into the DB once so a working setup isn't lost.
     */
    public function run(): void
    {
        if (Setting::get(ProxyhubConfig::KEY_BASE_URL) === null) {
            Setting::set(ProxyhubConfig::KEY_BASE_URL, ProxyhubConfig::DEFAULT_BASE_URL);
        }

        // One-time migration of the legacy env token (if present and not yet set).
        $envToken = env('PROXYHUB_INTEGRATOR_TOKEN');
        if (! empty($envToken) && Setting::get(ProxyhubConfig::KEY_INTEGRATOR_TOKEN) === null) {
            Setting::set(ProxyhubConfig::KEY_INTEGRATOR_TOKEN, $envToken);
        }

        $this->command->info('Platform settings seeded.');
    }
}

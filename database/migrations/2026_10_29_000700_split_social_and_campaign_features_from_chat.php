<?php

use App\Enums\Billing\Feature;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Instagram, TikTok and YouTube management and campaigns used to come with
 * `chat`. They are plan features of their own now — and a plan feature that is
 * not listed reads as off, so without this every workspace using them today
 * would lose them on deploy.
 *
 * Granted wherever `chat` is on, in the three places entitlements are read
 * from: the plan (what the next subscription copies), the subscription's
 * snapshot (what the gate reads today), and a support override that grants
 * `chat` (whose meaning was "the inbox and everything with it"). Nothing is
 * taken away from anyone; selling them separately starts with the next plan
 * edited in Back Office → Plans.
 *
 * A key that is already present is left alone, so running this again — or on
 * a plan someone already edited — changes nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->grant('plans', 'features');
        $this->grant('subscriptions', 'features_snapshot');
        $this->grantOverrides();

        // Entitlements are cached for a minute; clear so the change is visible
        // on the next page load rather than on the next TTL boundary.
        DB::table('tenants')->pluck('id')->each(
            fn ($tenantId) => Cache::forget("billing:entitlements:tenant:{$tenantId}")
        );
    }

    public function down(): void
    {
        $this->revoke('plans', 'features');
        $this->revoke('subscriptions', 'features_snapshot');
    }

    /** @return list<string> */
    private function keys(): array
    {
        return array_map(fn (Feature $feature) => $feature->value, Feature::splitFromChat());
    }

    /** @param array<string, mixed> $features */
    private function withSplitFeatures(array $features): ?array
    {
        if (! ($features[Feature::Chat->value] ?? false)) {
            return null;
        }

        $changed = false;

        foreach ($this->keys() as $key) {
            if (! array_key_exists($key, $features)) {
                $features[$key] = true;
                $changed = true;
            }
        }

        return $changed ? $features : null;
    }

    private function grant(string $table, string $column): void
    {
        DB::table($table)->orderBy('id')->chunk(200, function ($rows) use ($table, $column) {
            foreach ($rows as $row) {
                $features = json_decode($row->{$column} ?? '', true);

                if (! is_array($features) || ($updated = $this->withSplitFeatures($features)) === null) {
                    continue;
                }

                DB::table($table)->where('id', $row->id)->update([$column => json_encode($updated)]);
            }
        });
    }

    private function grantOverrides(): void
    {
        DB::table('tenants')->whereNotNull('entitlement_overrides')->orderBy('id')->chunk(200, function ($rows) {
            foreach ($rows as $row) {
                $overrides = json_decode($row->entitlement_overrides ?? '', true);

                if (! is_array($overrides) || ! is_array($overrides['features'] ?? null)) {
                    continue;
                }

                if (($updated = $this->withSplitFeatures($overrides['features'])) === null) {
                    continue;
                }

                $overrides['features'] = $updated;

                DB::table('tenants')->where('id', $row->id)->update(['entitlement_overrides' => json_encode($overrides)]);
            }
        });
    }

    private function revoke(string $table, string $column): void
    {
        DB::table($table)->orderBy('id')->chunk(200, function ($rows) use ($table, $column) {
            foreach ($rows as $row) {
                $features = json_decode($row->{$column} ?? '', true);

                if (! is_array($features)) {
                    continue;
                }

                $remaining = array_diff_key($features, array_flip($this->keys()));

                if (count($remaining) !== count($features)) {
                    DB::table($table)->where('id', $row->id)->update([$column => json_encode($remaining)]);
                }
            }
        });
    }
};

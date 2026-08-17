<?php

use App\Enums\Billing\Feature;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Turn the funnel on where the flow builder is already on.
     *
     * Without this the feature ships invisible. `hasFeature()` in the SPA reads
     * the plan snapshot and — unlike isBillingBlocked() — does not consult the
     * BILLING_ENFORCE switch, so a workspace whose plan does not list `crm`
     * hides the menu entry even on a deployment where the API would happily
     * serve it. Adding the flag is the only thing that makes the page reachable.
     *
     * `flow` is used as the tier marker because it is the existing feature with
     * the same shape: something a team doing real work on the platform gets,
     * rather than a starter add-on. Comp grants get it too — they are internal,
     * and they are what most existing workspaces are running on.
     *
     * ⚠️ This is a pricing decision as much as a technical one. If the funnel
     * belongs on a different tier, change it in Back Office → Plans; nothing
     * here is load-bearing beyond the first deploy.
     */
    public function up(): void
    {
        $this->addFeature('plans', 'features');
        $this->addFeature('subscriptions', 'features_snapshot');

        // Entitlements are cached for a minute; clear so the change is visible
        // on the next page load rather than on the next TTL boundary.
        DB::table('tenants')->pluck('id')->each(function ($tenantId) {
            \Illuminate\Support\Facades\Cache::forget("billing:entitlements:tenant:{$tenantId}");
        });
    }

    public function down(): void
    {
        $this->removeFeature('plans', 'features');
        $this->removeFeature('subscriptions', 'features_snapshot');
    }

    private function addFeature(string $table, string $column): void
    {
        DB::table($table)->orderBy('id')->chunk(200, function ($rows) use ($table, $column) {
            foreach ($rows as $row) {
                $features = json_decode($row->{$column} ?? '', true);

                if (! is_array($features)) {
                    continue;
                }

                // Only where the flow builder is already granted, plus the
                // unlimited comp grants handed out at the billing rollout.
                $isComp = $table === 'subscriptions' && ($row->status ?? null) === 'manual';

                if (! ($isComp || ($features['flow'] ?? false))) {
                    continue;
                }

                if (array_key_exists(Feature::Crm->value, $features)) {
                    continue;
                }

                $features[Feature::Crm->value] = true;

                DB::table($table)->where('id', $row->id)->update([$column => json_encode($features)]);
            }
        });
    }

    private function removeFeature(string $table, string $column): void
    {
        DB::table($table)->orderBy('id')->chunk(200, function ($rows) use ($table, $column) {
            foreach ($rows as $row) {
                $features = json_decode($row->{$column} ?? '', true);

                if (! is_array($features) || ! array_key_exists(Feature::Crm->value, $features)) {
                    continue;
                }

                unset($features[Feature::Crm->value]);

                DB::table($table)->where('id', $row->id)->update([$column => json_encode($features)]);
            }
        });
    }
};

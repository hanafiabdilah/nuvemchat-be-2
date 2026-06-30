<?php

namespace Database\Seeders;

use App\Enums\Billing\PaymentMethod;
use App\Enums\Billing\SubscriptionStatus;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    /**
     * Default platform plans. Prices are integer cents, BRL.
     */
    public const PLANS = [
        [
            'name' => 'Starter',
            'slug' => 'starter',
            'description' => 'Para começar — 1 conexão e 2 atendentes.',
            'price_cents' => 4900,
            'billing_cycle' => 'monthly',
            'trial_days' => 7,
            'quotas' => ['max_connections' => 1, 'max_agents' => 2, 'max_ai_runs' => 200],
            'features' => ['flow' => false, 'ai_agent_hub' => false, 'statistics' => true],
            'sort_order' => 1,
        ],
        [
            'name' => 'Pro',
            'slug' => 'pro',
            'description' => 'Para equipes — fluxos e mais canais.',
            'price_cents' => 14900,
            'billing_cycle' => 'monthly',
            'trial_days' => 7,
            'quotas' => ['max_connections' => 5, 'max_agents' => 10, 'max_ai_runs' => 2000],
            'features' => ['flow' => true, 'ai_agent_hub' => false, 'statistics' => true],
            'sort_order' => 2,
        ],
        [
            'name' => 'Business',
            'slug' => 'business',
            'description' => 'Tudo liberado, incluindo o AI Agent Hub.',
            'price_cents' => 29900,
            'billing_cycle' => 'monthly',
            'trial_days' => 0,
            'quotas' => ['max_connections' => 20, 'max_agents' => 50, 'max_ai_runs' => 10000],
            'features' => ['flow' => true, 'ai_agent_hub' => true, 'statistics' => true],
            'sort_order' => 3,
        ],
    ];

    public function run(): void
    {
        foreach (self::PLANS as $data) {
            Plan::updateOrCreate(['slug' => $data['slug']], $data);
        }

        // Backfill: every existing tenant without a subscription gets an
        // unlimited comp grant so the enforcement rollout never locks anyone out.
        $business = Plan::where('slug', 'business')->first();

        Tenant::whereNull('current_subscription_id')->each(function (Tenant $tenant) use ($business) {
            $subscription = Subscription::create([
                'tenant_id' => $tenant->id,
                'plan_id' => $business?->id,
                'status' => SubscriptionStatus::Manual,
                'payment_method' => PaymentMethod::Manual,
                'billing_cycle' => $business?->billing_cycle?->value,
                'price_cents' => 0,
                'quantity' => 1,
                'quotas_snapshot' => $business?->quotas,
                'features_snapshot' => $business?->features,
                'current_period_start' => now(),
                'current_period_end' => null, // unlimited
                'manual_note' => 'Auto comp grant on billing rollout.',
            ]);

            $tenant->forceFill(['current_subscription_id' => $subscription->id])->save();
        });

        $this->command->info('Plans seeded and existing tenants backfilled.');
    }
}

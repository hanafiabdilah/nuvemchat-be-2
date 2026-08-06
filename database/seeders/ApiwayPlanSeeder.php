<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

/**
 * API-Way-only plan (mode 3): no chat/inbox features, just N included
 * instances. Extra instances beyond the included quota are unit purchases at
 * the ProxyBR catalog price. Price here is the Pingly plan price — BO-editable.
 */
class ApiwayPlanSeeder extends Seeder
{
    public function run(): void
    {
        Plan::updateOrCreate(
            ['slug' => 'api-way'],
            [
                'name' => 'API Way',
                'slug' => 'api-way',
                'description' => 'Plano exclusivo API Way — 1 instância inclusa, sem funcionalidades de inbox.',
                'price_cents' => 4990,
                'currency' => 'BRL',
                'billing_cycle' => 'monthly',
                'trial_days' => 0,
                'quotas' => ['included_instances' => 1, 'max_connections' => 1],
                'features' => ['whatsapp_api' => true],
                'is_active' => true,
                'is_public' => true,
                'sort_order' => 50,
                'mp_card_enabled' => true,
                'mp_pix_enabled' => true,
            ],
        );
    }
}

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
                // One WhatsApp number and nothing else: the plan has no inbox, so
                // a slot for Instagram or e-mail would sell a connection with
                // nowhere to read it. 0, not absent — absent reads as unlimited.
                'quotas' => ['included_instances' => 1, 'max_whatsapp_connections' => 1, 'max_other_connections' => 0],
                'features' => ['whatsapp_api' => true],
                'is_active' => true,
                'is_public' => true,
                'sort_order' => 50,
                'card_enabled' => true,
                'pix_enabled' => true,
            ],
        );
    }
}

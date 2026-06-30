<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

class WhatsappApiPlanSeeder extends Seeder
{
    public function run(): void
    {
        Plan::updateOrCreate(
            ['slug' => 'whatsapp-api'],
            [
                'name' => 'WhatsApp API',
                'slug' => 'whatsapp-api',
                'description' => 'Instâncias de WhatsApp API — R$ 39,90 por instância/mês.',
                'price_cents' => 3990,
                'currency' => 'BRL',
                'billing_cycle' => 'monthly',
                'trial_days' => 0,
                'quotas' => ['max_instances' => 1],
                'features' => ['whatsapp_api' => true],
                'is_active' => true,
                'is_public' => true,
                'sort_order' => 50,
                'mp_card_enabled' => true,
                'mp_pix_enabled' => true,
                'quantity_enabled' => true,
            ],
        );
    }
}

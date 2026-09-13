<?php

namespace Database\Seeders\ManualDemo;

use App\Models\Connection;
use App\Models\Plan;
use App\Models\QuickMessage;
use App\Models\Setting;
use App\Models\Subscription;
use App\Models\Tag;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\ManualDemoSeeder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

trait Workspace
{
    protected function seedWorkspace(): void
    {
        $this->tunePlans();

        $owner = $this->make(User::class, [
            'name' => 'Marina Alves',
            'email' => ManualDemoSeeder::OWNER_EMAIL,
            'password' => ManualDemoSeeder::PASSWORD,
            'email_verified_at' => $this->now->copy()->subDays(120),
            'ui_preferences' => ['theme' => 'classic', 'appearance' => 'light'],
            'last_seen_at' => $this->now,
            'created_at' => $this->now->copy()->subDays(120),
        ]);

        $this->tenant = $this->make(Tenant::class, [
            'user_id' => $owner->id,
            'billing_name' => 'Aurora Moda Esportiva LTDA',
            // Upper case, as BillingController validates and stores it — the
            // form compares against 'CPF'/'CNPJ' and mis-reads anything else.
            'billing_document_type' => 'CNPJ',
            'billing_document_number' => '11222333000181',
            'lead_settings' => [
                'auto_create' => true,
                'auto_close_enabled' => true,
                'auto_close_days' => 30,
                'auto_close_engaged' => false,
            ],
            'audio_dictionary' => $this->vocabulary(),
            'created_at' => $this->now->copy()->subDays(120),
        ]);

        $owner->forceFill(['tenant_id' => $this->tenant->id])->save();
        $owner->assignRole('owner');
        $this->users['marina'] = $owner;
        $this->avatar($owner, 'avatar-marina.png');

        $this->seedRoles();
        $this->seedTeam();
        $this->seedSubscription();
        $this->seedConnections();
        $this->seedAccess();
        $this->seedTags();
        $this->seedQuickMessages();

        Setting::set('ai_agent_hub.tenant_token', 'manual-demo-token');
        Setting::set('flow_assistant.enabled', '1');
    }

    private function tunePlans(): void
    {
        $all = [
            'chat' => true, 'whatsapp_api' => true, 'crm' => true, 'flow' => true,
            'flow_assistant' => true, 'ai_agent_hub' => true, 'statistics' => true,
        ];

        Plan::where('slug', 'business')->first()?->forceFill([
            'features' => $all,
            'quotas' => [
                'max_connections' => 15, 'max_agents' => 8, 'max_ai_runs' => 10000,
                'included_instances' => 2, 'included_trained_agents' => 3, 'gallery_storage_gb' => 2,
            ],
        ])->save();

        Plan::where('slug', 'pro')->first()?->forceFill([
            'quotas' => ['max_connections' => 5, 'max_agents' => 10, 'max_ai_runs' => 2000, 'gallery_storage_gb' => 1],
        ])->save();

        // A fourth card priced in centavos trips a display rounding quirk; the
        // manual's plan grid shows the three whole-real plans.
        Plan::where('slug', 'api-way')->update(['is_public' => false]);
    }

    private function avatar(User $user, string $file): void
    {
        if ($path = $this->media($file, 'avatars/manual-demo')) {
            $user->forceFill(['avatar_path' => $path])->saveQuietly();
        }
    }

    private function seedRoles(): void
    {
        $roles = [
            'Supervisor' => [
                'agents.view', 'agents.update-avatar', 'agents.sync-connections', 'statistics.tenant.view',
                'statistics.agents.view', 'leads.view', 'leads.create', 'leads.update', 'contacts.update',
                'tags.create', 'tags.update', 'tags.delete', 'broadcasts.view', 'flows.view', 'gallery.view',
            ],
            'Atendente' => ['contacts.update', 'templates.send', 'templates.view', 'gallery.view'],
            'Vendas' => ['leads.view', 'leads.create', 'leads.update', 'contacts.update'],
            'Financeiro' => ['billing.view', 'integrations.view'],
            'Estagiário' => [],
        ];

        // The demo workspace's own roles (roles.tenant_id): names are only
        // unique inside a workspace, so they are created and assigned as models
        // rather than looked up by name.
        foreach ($roles as $name => $permissions) {
            $role = Role::query()->firstOrCreate([
                'name' => $name,
                'guard_name' => 'web',
                'tenant_id' => $this->tenant->id,
            ]);

            $role->syncPermissions($permissions);
            $this->demoRoles[$name] = $role;
        }
    }

    /** @var array<string, Role> The demo workspace's roles, by name. */
    private array $demoRoles = [];

    private function seedTeam(): void
    {
        $team = [
            'rafael' => ['Rafael Souza', 'rafael@lojaaurora.example', ['Supervisor'], 'avatar-rafael.png', 0],
            'juliana' => ['Juliana Pereira', 'juliana@lojaaurora.example', ['Atendente'], 'avatar-juliana.png', 1],
            'carlos' => ['Carlos Mendes', 'carlos@lojaaurora.example', ['Vendas'], 'avatar-carlos.png', 130],
            'beatriz' => ['Beatriz Lima', 'beatriz@lojaaurora.example', ['Atendente'], 'avatar-beatriz.png', null],
            'fabio' => ['Fábio Melo', 'fabio@lojaaurora.example', [], null, 2900],
            'gabriela' => ['Gabriela Pinto', 'gabriela@lojaaurora.example', ['Atendente', 'Vendas'], null, 3],
        ];

        foreach ($team as $key => [$name, $email, $roles, $photo, $seenMinutesAgo]) {
            $user = $this->make(User::class, [
                'name' => $name,
                'email' => $email,
                'password' => ManualDemoSeeder::PASSWORD,
                'tenant_id' => $this->tenant->id,
                'email_verified_at' => $this->now->copy()->subDays(90),
                'last_seen_at' => $seenMinutesAgo === null ? null : $this->ago($seenMinutesAgo),
                'created_at' => $this->now->copy()->subDays(90),
            ]);

            foreach ($roles as $role) {
                $user->assignRole($this->demoRoles[$role]);
            }

            if ($photo) {
                $this->avatar($user, $photo);
            }

            $this->users[$key] = $user;
        }

        // Exceptions on top of a role: shows as "2 permissões adicionais".
        $this->users['carlos']->givePermissionTo(['broadcasts.view', 'statistics.tenant.view']);
    }

    private function seedSubscription(): void
    {
        $plan = Plan::where('slug', 'business')->firstOrFail();

        $subscription = $this->make(Subscription::class, [
            'tenant_id' => $this->tenant->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'payment_method' => 'card',
            'billing_cycle' => 'monthly',
            'price_cents' => $plan->price_cents,
            'quotas_snapshot' => $plan->quotas,
            'features_snapshot' => $plan->features,
            'current_period_start' => $this->now->copy()->subDays(16)->startOfDay(),
            'current_period_end' => $this->now->copy()->addDays(14)->startOfDay(),
            'payment_instrument_id' => 'inst_manual_demo_card',
            'payment_customer_id' => 'cus_manual_demo',
            'created_at' => $this->now->copy()->subDays(106),
        ]);

        $this->tenant->forceFill(['current_subscription_id' => $subscription->id])->save();
    }

    private function seedConnections(): void
    {
        $waba = '102938475611111';

        $defs = [
            'wa' => ['whatsapp_official', 'WhatsApp Vendas', '#22c55e', 'active', [
                'phone_number_id' => '109876543210001', 'business_account_id' => $waba,
                'display_phone_number' => '+55 11 4000-1234', 'verified_name' => 'Loja Aurora',
                'quality_rating' => 'GREEN', 'platform_type' => 'CLOUD_API', 'access_token' => 'manual-demo',
            ], 110],
            'wa_coex' => ['whatsapp_official', 'WhatsApp Suporte', '#0ea5e9', 'active', [
                'phone_number_id' => '109876543210002', 'business_account_id' => $waba,
                'display_phone_number' => '+55 11 4000-5678', 'verified_name' => 'Loja Aurora Suporte',
                'quality_rating' => 'GREEN', 'is_coexistence' => true, 'access_token' => 'manual-demo',
                'smb_data_sync' => ['history' => ['status' => 'receiving', 'progress' => 42]],
            ], 60],
            'apiway' => ['whatsapp_proxyhub', 'WhatsApp API Way', '#16a34a', 'active', [
                'instance_id' => 'b7c1f2e4-4a1d-4e7b-9f3a-2c8d0a5e1f90', 'phone_number' => '5511999990000',
                'import_history' => false,
            ], 100],
            'apiway_old' => ['whatsapp_proxyhub', 'WhatsApp Filial Centro', '#65a30d', 'inactive', [
                'import_history' => true,
                'released_instance' => [
                    'instance_id' => '5e0a9c11-8d7e-4f22-a1b0-3c4d5e6f7a8b', 'apiway_instance_id' => null,
                    'phone_number' => '5511988887777', 'reason' => 'subscription_expired',
                    'released_at' => $this->now->copy()->subDays(6)->toIso8601String(),
                ],
            ], 95],
            'ig' => ['instagram', 'Instagram @aurora.esportes', '#e1306c', 'active', [
                'username' => 'aurora.esportes', 'id' => '17841400000000001',
            ], 105],
            'messenger' => ['messenger', 'Messenger Loja Aurora', '#1877f2', 'pending', [
                'pending_pages' => [
                    ['id' => '101112131415161', 'name' => 'Loja Aurora'],
                    ['id' => '171819202122232', 'name' => 'Aurora Outlet'],
                ],
            ], 3],
            'tg' => ['telegram', 'Telegram Aurora', '#0088cc', 'active', [
                'username' => 'aurora_suporte_bot', 'id' => '7000000001',
            ], 90],
            'discord' => ['discord', 'Discord Comunidade', '#5865f2', 'inactive', [], 20],
            'widget' => ['live_chat_widget', 'Chat do site', '#6366f1', 'active', [
                'app_id' => '3f6c2a8e-1b4d-4c7e-9a2f-6d8e0b1c4a57', 'template_type' => 'global',
            ], 100],
            'tiktok' => ['tiktok', 'TikTok Loja Aurora', '#111827', 'active', [
                'display_name' => 'Loja Aurora', 'username' => 'lojaaurora', 'business_id' => 'tt_demo_0001',
            ], 40],
            'email' => ['email', 'Contato', '#f59e0b', 'active', [
                'email' => 'contato@lojaaurora.example', 'password' => Crypt::encryptString('manual-demo'),
                'imap_host' => 'imap.lojaaurora.example', 'imap_port' => 993, 'imap_encryption' => 'ssl',
                'smtp_host' => 'smtp.lojaaurora.example', 'smtp_port' => 465, 'smtp_encryption' => 'ssl',
            ], 115],
            'email_fin' => ['email', 'Financeiro', '#10b981', 'active', [
                'email' => 'financeiro@lojaaurora.example', 'password' => Crypt::encryptString('manual-demo'),
                'imap_host' => 'imap.lojaaurora.example', 'imap_port' => 993, 'imap_encryption' => 'ssl',
                'smtp_host' => 'smtp.lojaaurora.example', 'smtp_port' => 587, 'smtp_encryption' => 'tls',
            ], 70],
        ];

        foreach ($defs as $key => [$channel, $name, $color, $status, $credentials, $ageDays]) {
            $this->conn[$key] = $this->make(Connection::class, [
                'tenant_id' => $this->tenant->id,
                'channel' => $channel,
                'name' => $name,
                'color' => $color,
                'status' => $status,
                'credentials' => $credentials ?: null,
                'api_key' => in_array($key, ['wa', 'apiway', 'tg', 'ig'], true) ? 'pk_' . Str::random(40) : null,
                'created_at' => $this->now->copy()->subDays($ageDays),
                'updated_at' => $this->now->copy()->subDays(1),
            ]);
        }

        $this->conn['wa']->forceFill([
            'accept_message' => 'Olá, {{contact.name}}! Aqui é {{agent_name}}, da Loja Aurora. Vou continuar o seu atendimento 😊',
            'closing_message' => 'Obrigada pelo contato! Se precisar de algo, é só chamar por aqui. 💙',
            'return_to_last_agent' => true,
            'return_to_last_agent_minutes' => 15,
            'service_hours' => [
                'enabled' => true,
                'timezone' => 'America/Sao_Paulo',
                'days' => [
                    'mon' => [['open' => '09:00', 'close' => '18:00']],
                    'tue' => [['open' => '09:00', 'close' => '18:00']],
                    'wed' => [['open' => '09:00', 'close' => '18:00']],
                    'thu' => [['open' => '09:00', 'close' => '18:00']],
                    'fri' => [['open' => '09:00', 'close' => '18:00']],
                    'sat' => [['open' => '09:00', 'close' => '13:00']],
                    'sun' => [],
                ],
                'away_message' => 'Estamos fora do horário de atendimento agora 🌙. Respondemos assim que abrirmos!',
            ],
        ])->save();

        $this->conn['apiway']->forceFill([
            'accept_message' => 'Oi, {{contact.name}}! Sou {{agent_name}} e vou te ajudar a partir de agora.',
        ])->save();

        $this->conn['email']->forceFill([
            'sync_status' => 'idle', 'last_synced_at' => $this->ago(2), 'sync_window_days' => 90, 'backfill_done' => true,
        ])->save();

        $this->conn['email_fin']->forceFill([
            'sync_status' => 'idle', 'last_synced_at' => $this->ago(4), 'sync_window_days' => 30, 'backfill_done' => true,
        ])->save();
    }

    private function seedAccess(): void
    {
        $grant = [
            'rafael' => ['wa', 'wa_coex', 'apiway', 'ig', 'tg', 'widget', 'tiktok', 'email'],
            'juliana' => ['wa', 'apiway', 'ig', 'tg', 'widget', 'email'],
            'carlos' => ['wa', 'apiway'],
            'gabriela' => ['wa', 'ig'],
            'fabio' => ['tg'],
        ];

        foreach ($grant as $user => $keys) {
            foreach ($keys as $key) {
                DB::table('connection_user')->insert([
                    'connection_id' => $this->conn[$key]->id,
                    'user_id' => $this->users[$user]->id,
                    'created_at' => $this->now,
                    'updated_at' => $this->now,
                ]);
            }
        }
    }

    private function seedTags(): void
    {
        $tags = [
            'vip' => ['VIP', '#a855f7'],
            'orcamento' => ['Orçamento', '#3b82f6'],
            'suporte' => ['Suporte técnico', '#14b8a6'],
            'reclamacao' => ['Reclamação', '#ef4444'],
            'recorrente' => ['Recorrente', '#22c55e'],
            'atacado' => ['Atacado', '#f97316'],
            'urgente' => ['Urgente', '#ec4899'],
            'pagamento' => ['Aguardando pagamento', '#eab308'],
            'inativo' => ['Inativo', '#6b7280'],
        ];

        foreach ($tags as $key => [$name, $color]) {
            $this->tags[$key] = $this->make(Tag::class, [
                'tenant_id' => $this->tenant->id,
                'name' => $name,
                'color' => $color,
                'created_at' => $this->now->copy()->subDays(100),
            ]);
        }
    }

    private function seedQuickMessages(): void
    {
        $workspace = [
            'ola' => "Olá, {{contact.name}}! Aqui é {{agent_name}}, da Loja Aurora. Como posso ajudar? 😊",
            'horario' => "Nosso horário de atendimento:\nSegunda a sexta: 9h às 18h\nSábado: 9h às 13h\nDomingos e feriados: fechado",
            'pix' => "Para pagar com Pix:\n1. Copie a chave: pix@lojaaurora.example\n2. Informe o valor do pedido\n3. Envie o comprovante aqui na conversa ✅",
            'entrega' => 'O prazo de entrega para capitais é de 2 a 4 dias úteis após a confirmação do pagamento. Você recebe o código de rastreio por aqui.',
            'obrigado' => 'Obrigada pela preferência, {{contact.name}}! Qualquer dúvida, estamos por aqui. 💙',
            'endereco' => 'Nossa loja física fica na Av. Paulista, 1000 — Bela Vista, São Paulo/SP. Aberta de segunda a sábado.',
            'troca' => 'Trocas em até 7 dias corridos após o recebimento, com o produto sem uso e na embalagem original. Posso abrir a solicitação para você?',
        ];

        foreach ($workspace as $shortcut => $message) {
            $this->make(QuickMessage::class, [
                'tenant_id' => $this->tenant->id, 'user_id' => null,
                'shortcut' => $shortcut, 'message' => $message,
                'created_at' => $this->now->copy()->subDays(60),
            ]);
        }

        $personal = [
            ['marina', 'retorno', 'Oi, {{contact.name}}! Estou verificando e retorno em poucos minutos, tudo bem?'],
            ['marina', 'ausente', 'No momento estou em reunião, mas já volto para te responder. Obrigada pela paciência!'],
            ['juliana', 'promo', 'Hoje tem 10% OFF no Pix com o cupom AURORA10! 🎉'],
        ];

        foreach ($personal as [$user, $shortcut, $message]) {
            $this->make(QuickMessage::class, [
                'tenant_id' => $this->tenant->id, 'user_id' => $this->users[$user]->id,
                'shortcut' => $shortcut, 'message' => $message,
                'created_at' => $this->now->copy()->subDays(30),
            ]);
        }
    }

    /** @return array<int, array{term: string, aliases: array<int, string>}> */
    private function vocabulary(): array
    {
        return [
            ['term' => 'Aurora Run', 'aliases' => ['aurora ran', 'aurora rum']],
            ['term' => 'Pingly', 'aliases' => ['pingli', 'ping li']],
            ['term' => 'Pix', 'aliases' => ['piques', 'pics']],
            ['term' => 'CNPJ', 'aliases' => ['cê ene pê jota']],
            ['term' => 'NF-e', 'aliases' => ['nfe', 'nota fiscal eletrônica']],
            ['term' => 'Dry-fit', 'aliases' => ['draifit', 'dry fit']],
            ['term' => 'Corta-vento', 'aliases' => ['corta vento']],
            ['term' => 'iFood', 'aliases' => ['ai food']],
            ['term' => 'Shopee', 'aliases' => ['xopi']],
            ['term' => 'Mercado Livre', 'aliases' => []],
            ['term' => 'WhatsApp Business', 'aliases' => []],
            ['term' => 'AURORA10', 'aliases' => ['aurora dez', 'aurora 10']],
        ];
    }
}

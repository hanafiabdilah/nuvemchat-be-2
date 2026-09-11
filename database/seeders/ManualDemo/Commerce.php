<?php

namespace Database\Seeders\ManualDemo;

use App\Models\ApiwayInstance;
use App\Models\ApiwaySubscription;
use App\Models\Broadcast;
use App\Models\Conversation;
use App\Models\CreditTransaction;
use App\Models\CreditWallet;
use App\Models\FlowPayment;
use App\Models\GalleryAsset;
use App\Models\GalleryStorageRental;
use App\Models\InstagramPost;
use App\Models\InstagramPostItem;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\Tag;
use App\Models\VirtualNumber;
use App\Models\VirtualNumberMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Leads, campaigns, money and the paid add-ons (gallery, numbers, instances). */
trait Commerce
{
    protected function seedCommerce(): void
    {
        $this->seedLeads();
        $this->seedBroadcasts();
        $this->seedBilling();
        $this->seedGallery();
        $this->seedNumbers();
        $this->seedApiway();
        $this->seedFlowPayments();
        $this->seedInstagramPosts();
    }

    private function seedLeads(): void
    {
        $p = $this->people;
        $pool = $this->pool;
        $u = $this->users;

        $rows = [
            // contact, stage, title, value, temperature, owner, days-in-stage, source connection
            [$p['aline'], 'Negociação', 'Pedido atacado — 40 pares', 12900, 'hot', 'carlos', 1, 'apiway'],
            [$p['bia'], 'Proposta', 'Revenda Santos — kit inicial', 3400, 'warm', 'marina', 12, 'ig'],
            [$p['mariana'], 'Negociação', 'Aurora Run 42 coral', 299.9, 'hot', 'marina', 0, 'apiway'],
            [$p['joao'], 'Qualificação', 'Tênis de corrida', null, 'warm', 'carlos', 1, 'wa'],
            [$p['pedro'], 'Novo contato', 'Interesse via WhatsApp', null, 'cold', null, 0, 'wa'],
            [$p['thiago'], 'Proposta', 'Pedido Curitiba', 459, 'warm', 'juliana', 2, 'tg'],
            [$p['larissa'], 'Novo contato', 'Boné preto', 79.9, 'cold', null, 1, 'tiktok'],
            [$p['gustavo'], 'Qualificação', 'Troca + nova compra', 249.9, 'cold', 'gabriela', 3, 'ig'],
            [$pool['wa'][0], 'Cliente', 'Kit presente corporativo', 4470, 'hot', 'carlos', 6, 'wa'],
            [$pool['wa'][1], 'Novo contato', 'Orçamento camisetas dry-fit', null, 'cold', null, 2, 'wa'],
            [$pool['wa'][2], 'Qualificação', 'Uniformes assessoria', 5200, 'warm', 'carlos', 5, 'wa'],
            [$pool['wa'][3], 'Novo contato', 'Mochila Urbana', 189.9, 'warm', 'juliana', 0, 'wa'],
            [$pool['apiway'][0], 'Cliente', 'Revenda mensal', 2350, 'hot', 'marina', 10, 'apiway'],
            [$pool['ig'][0], 'Novo contato', 'Parceria influenciador', null, 'cold', null, 4, 'ig'],
            [$pool['ig'][2], 'Proposta', 'Jaqueta corta-vento x3', 989.7, 'warm', 'gabriela', 4, 'ig'],
            [$pool['tg'][1], 'Perdido', 'Relógio Pulse', 459, 'cold', 'rafael', 7, 'tg', 'Achou mais barato'],
            [$pool['wa'][5], 'Perdido', 'Orçamento tênis', 299.9, 'cold', null, 3, 'wa', 'Sem resposta'],
            [$pool['wa'][6], 'Novo contato', 'Garrafa térmica x10', 899, 'cold', null, 40, 'wa'],
        ];

        foreach ($rows as $row) {
            [$contact, $stageName, $title, $value, $temperature, $owner, $days, $source] = $row;
            $lostReason = $row[8] ?? null;
            $stage = $this->pipeline->stages->firstWhere('name', $stageName);
            $kind = $stage->kind->value ?? (string) $stage->kind;
            $changed = $this->now->copy()->subDays($days)->subHours(mt_rand(1, 8));
            $created = $changed->copy()->subDays(mt_rand(1, 9));

            $lead = $this->make(Lead::class, [
                'tenant_id' => $this->tenant->id, 'contact_id' => $contact->id, 'pipeline_id' => $this->pipeline->id,
                'stage_id' => $stage->id, 'owner_id' => $owner ? $u[$owner]->id : null,
                'source_connection_id' => $this->conn[$source]->id, 'title' => $title, 'value' => $value, 'currency' => 'BRL',
                'status' => $kind, 'source' => 'inbound', 'temperature' => $temperature,
                'temperature_score' => ['hot' => 82, 'warm' => 55, 'cold' => 18][$temperature],
                'last_inbound_at' => $this->now->copy()->subHours(mt_rand(1, 60)), 'stage_changed_at' => $changed,
                'lost_reason' => $lostReason, 'closed_at' => $kind === 'open' ? null : $changed,
                'created_at' => $created, 'updated_at' => $changed,
            ]);

            $path = ['Novo contato', 'Qualificação', 'Proposta', 'Negociação'];
            $target = array_search($stageName, $path, true);
            $steps = $target === false ? ['Novo contato', $stageName] : array_slice($path, 0, $target + 1);
            $previous = null;
            foreach ($steps as $n => $name) {
                $s = $this->pipeline->stages->firstWhere('name', $name);
                DB::table('lead_stage_events')->insert([
                    'lead_id' => $lead->id, 'tenant_id' => $this->tenant->id, 'from_stage_id' => $previous,
                    'to_stage_id' => $s->id, 'to_stage_name' => $name,
                    'user_id' => $n === 0 || ($lostReason === 'Sem resposta' && $name === 'Perdido') ? null : ($owner ? $u[$owner]->id : null),
                    'created_at' => $n === count($steps) - 1 ? $changed : $created->copy()->addHours($n * 5),
                ]);
                $previous = $s->id;
            }

            Conversation::where('contact_id', $contact->id)->whereIn('status', ['active', 'pending', 'ai_handling'])->update(['lead_id' => $lead->id]);
        }
    }

    private function seedBroadcasts(): void
    {
        $marina = $this->users['marina'];
        $defs = [
            ['Promoção de setembro', 'running', 'apiway', 'text', ['body' => 'Oi {{contact.first_name}}! 🎉 Semana Aurora: 20% OFF em tênis até domingo. Responda QUERO para ver os modelos.'], 50, [28, 3, 4], 0, true],
            ['Black Friday — aviso antecipado', 'completed', 'wa', 'template', ['template' => ['name' => 'promo_setembro', 'language' => 'pt_BR', 'category' => 'MARKETING'], 'components' => [['type' => 'body', 'parameters' => [['type' => 'text', 'text' => '{{contact.first_name}}'], ['type' => 'text', 'text' => 'novembro']]]]], 120, [110, 6, 4], 9, true],
            ['Lembrete de renovação', 'scheduled', 'wa', 'template', ['template' => ['name' => 'lembrete_renovacao', 'language' => 'pt_BR', 'category' => 'UTILITY'], 'components' => []], 60, [0, 0, 0], -1, false],
            ['Pesquisa de satisfação', 'draft', 'email', 'email', ['subject' => 'Como foi a sua compra na Loja Aurora?', 'body' => "Olá, {{contact.first_name}}!\n\nConte para nós como foi a sua experiência. Leva 1 minuto."], 35, [0, 0, 0], 0, false],
            ['Reativação de clientes', 'paused', 'apiway', 'media', ['body' => 'Sentimos sua falta, {{contact.first_name}}! Volte com 15% OFF usando o cupom VOLTA15.', 'media_url' => $this->mediaUrl('post-colecao-primavera.jpg'), 'media_type' => 'image'], 80, [30, 2, 1], 2, true],
            ['Aviso de manutenção do site', 'canceled', 'tg', 'text', ['body' => 'Nosso site passará por manutenção no domingo, das 2h às 6h.'], 40, [12, 0, 28], 15, false],
        ];

        $recipients = array_merge($this->pool['apiway'], $this->pool['wa']);

        foreach ($defs as [$name, $status, $connKey, $type, $payload, $total, [$sent, $failed, $skipped], $daysAgo, $withTag]) {
            $tag = $withTag ? $this->make(Tag::class, ['tenant_id' => $this->tenant->id, 'name' => $name, 'color' => '#7c3aed']) : null;
            $started = $daysAgo >= 0 && ! in_array($status, ['draft', 'scheduled'], true) ? $this->now->copy()->subDays($daysAgo)->subHours(2) : null;

            $broadcast = $this->make(Broadcast::class, [
                'tenant_id' => $this->tenant->id, 'connection_id' => $this->conn[$connKey]->id, 'created_by' => $marina->id,
                'tag_id' => $tag?->id, 'name' => $name, 'status' => $status, 'content_type' => $type, 'payload' => $payload,
                'scheduled_at' => $status === 'scheduled' ? $this->brt(-1, 9, 0) : null,
                'rate_per_minute' => $connKey === 'wa' ? 60 : 12, 'total_recipients' => $total,
                'sent_count' => $sent, 'failed_count' => $failed, 'skipped_count' => $skipped,
                'started_at' => $started, 'finished_at' => in_array($status, ['completed', 'canceled'], true) ? $started?->copy()->addMinutes(95) : null,
                'last_tick_at' => $status === 'running' ? $this->ago(0) : $started,
                'created_at' => ($started ?? $this->now)->copy()->subHours(3), 'updated_at' => $this->now,
            ]);

            $rows = [];
            for ($i = 0; $i < $total; $i++) {
                // Past the contact pool, recipients are addresses typed straight
                // into the campaign — exactly what the wizard allows.
                $typed = $i >= count($recipients);
                $contact = $recipients[$i % count($recipients)];
                $name = $typed
                    ? $this->firstNames[($i * 5) % count($this->firstNames)] . ' ' . $this->lastNames[($i * 3) % count($this->lastNames)]
                    : $contact->name;
                $state = $i < $sent ? 'sent' : ($i < $sent + $failed ? 'failed' : ($i < $sent + $failed + $skipped ? 'skipped' : 'pending'));
                $error = match ($state) {
                    'failed' => ['O número informado não tem WhatsApp.', 'Não foi possível enviar para este destinatário.', 'Dados inválidos para este destinatário.'][$i % 3],
                    'skipped' => $status === 'canceled' ? 'Campaign canceled' : 'Contact opted out of broadcasts',
                    default => null,
                };
                $isEmail = $connKey === 'email';
                $rows[] = [
                    'broadcast_id' => $broadcast->id, 'contact_id' => $isEmail || $typed ? null : $contact->id,
                    'address' => $isEmail
                        ? Str::slug($name, '.') . $i . '@email.example'
                        : ($typed ? sprintf('55119%08d', 70000000 + $broadcast->id * 1000 + $i) : $contact->external_id),
                    'name' => $name, 'status' => $state, 'error' => $error, 'attempts' => $state === 'pending' ? 0 : 1,
                    'sent_at' => $state === 'sent' ? $started?->copy()->addMinutes(intdiv($i, 2)) : null,
                    'created_at' => $broadcast->created_at, 'updated_at' => $this->now,
                ];
            }
            foreach (array_chunk($rows, 100) as $chunk) {
                DB::table('broadcast_recipients')->insert($chunk);
            }
        }
    }

    private function seedBilling(): void
    {
        $sub = $this->tenant->currentSubscription;
        $invoices = [
            [230, 'pending', 'pix', 10000, 'ai_credit_topup', 0], [224, 'paid', 'pix', 10000, 'ai_credit_topup', 5],
            [219, 'refunded', 'pix', 5000, 'ai_credit_topup', 9], [212, 'paid', 'card', 29900, 'subscription', 16],
            [205, 'paid', 'pix', 10000, 'ai_credit_topup', 23], [198, 'paid', 'pix', 20000, 'ai_credit_topup', 29],
            [190, 'paid', 'card', 29900, 'subscription', 47], [176, 'paid', 'card', 29900, 'subscription', 77],
            [160, 'expired', 'pix', 29900, 'subscription', 105], [158, 'paid', 'card', 29900, 'subscription', 107],
        ];

        foreach ($invoices as [$number, $status, $method, $amount, $purpose, $days]) {
            $created = $this->now->copy()->subDays($days)->setTime(12, 5);
            $this->make(Invoice::class, [
                'tenant_id' => $this->tenant->id, 'subscription_id' => $purpose === 'subscription' ? $sub->id : null,
                'status' => $status, 'payment_method' => $method, 'amount_cents' => $amount, 'currency' => 'BRL', 'purpose' => $purpose,
                'period_start' => $purpose === 'subscription' ? $created : null, 'period_end' => $purpose === 'subscription' ? $created->copy()->addMonth() : null,
                'due_date' => $created->toDateString(), 'paid_at' => $status === 'paid' ? $created->copy()->addMinutes(3) : null,
                'payment_id' => 'pay_demo_' . $number, 'order_reference' => 'pingly:demo:' . $number,
                'pix_copy_paste' => $method === 'pix' ? '00020126580014BR.GOV.BCB.PIX0136manual-demo-pix-key5204000053039865802BR5913LOJA AURORA6009SAO PAULO62070503***6304ABCD' : null,
                'pix_expires_at' => $method === 'pix' ? $created->copy()->addDay() : null,
                'created_at' => $created, 'updated_at' => $created,
            ]);
        }

        $this->make(CreditWallet::class, ['tenant_id' => $this->tenant->id, 'balance_cents' => 18240, 'currency' => 'BRL']);

        $ledger = [
            [29, 'topup', 20000, 'Recarga — fatura #6'], [28, 'purchase', -3990, 'Número virtual — WhatsApp (DDD 11)'],
            [27, 'usage', -12, 'OPENAI gpt-4o'], [26, 'purchase', -9700, 'Agente treinado — Assistente Contábil'],
            [25, 'reversal', 9700, 'Devolução — agente treinado não entregue'], [23, 'topup', 10000, 'Recarga — fatura #5'],
            [20, 'purchase', -570, 'Armazenamento da galeria — 3 GB'], [18, 'purchase', -2990, 'Número virtual — iFood (DDD 21)'],
            [15, 'adjustment', 1000, 'Ajuste — cortesia do suporte'], [12, 'usage', -35, 'OPENAI gpt-4o'],
            [9, 'topup', 5000, 'Recarga — fatura #3'], [8, 'refund', -5000, 'Estorno da recarga — fatura #3'],
            [5, 'topup', 10000, 'Recarga — fatura #2'], [3, 'renewal', -6490, 'Renovação API Way — 1 instância(s)'],
            [1, 'purchase', -3490, 'Número virtual — Telegram (DDD 31)'],
        ];
        $balance = 18240 - array_sum(array_column($ledger, 2));

        foreach ($ledger as $i => [$days, $type, $amount, $description]) {
            $balance += $amount;
            $at = $this->now->copy()->subDays($days)->setTime(9 + $i % 8, 10 + $i);
            $this->make(CreditTransaction::class, [
                'tenant_id' => $this->tenant->id, 'type' => $type, 'amount_cents' => $amount, 'balance_after_cents' => $balance,
                'currency' => 'BRL', 'description' => $description, 'reference' => "demo:{$type}:{$i}",
                'usd_brl_rate' => $type === 'usage' ? 5.6 : null, 'markup_pct' => $type === 'usage' ? 40 : null,
                'created_at' => $at, 'updated_at' => $at,
            ]);
        }
    }

    private function seedGallery(): void
    {
        $assets = [
            ['Catálogo Primavera 2026', 'catalogo-primavera-2026.pdf', 'application/pdf', 'document', 45 * 1048576, 'marina', 3],
            ['Tabela de preços — atacado', 'tabela-de-precos-atacado.pdf', 'application/pdf', 'document', 12 * 1048576, 'marina', 1],
            ['Tênis Aurora Run', 'produto-tenis-aurora-run.jpg', 'image/jpeg', 'image', 6 * 1048576, 'juliana', 0],
            ['Relógio Pulse', 'produto-relogio-pulse.jpg', 'image/jpeg', 'image', 5 * 1048576, 'juliana', null],
            ['Mochila Urbana 22L', 'produto-mochila-urbana.jpg', 'image/jpeg', 'image', 4 * 1048576, 'marina', 12],
            ['Jaqueta corta-vento', 'produto-jaqueta.jpg', 'image/jpeg', 'image', 5 * 1048576, 'rafael', null],
            ['Kit presente', 'produto-kit-presente.jpg', 'image/jpeg', 'image', 4 * 1048576, 'marina', 6],
            ['Banner Coleção Primavera', 'post-colecao-primavera.jpg', 'image/jpeg', 'image', 8 * 1048576, 'marina', 2],
            ['Banner frete grátis', 'post-frete-gratis.jpg', 'image/jpeg', 'image', 7 * 1048576, 'marina', 20],
            ['Vídeo lançamento Aurora Run', 'video-aurora-run.webm', 'video/webm', 'video', 880 * 1048576, 'rafael', 4],
            ['Áudio de boas-vindas', 'audio-boas-vindas.m4a', 'audio/mp4', 'audio', 3 * 1048576, 'marina', 1],
            ['Tutorial de sincronização do Pulse', 'video-aurora-run.webm', 'video/webm', 'video', 1240 * 1048576, 'rafael', null],
        ];

        foreach ($assets as $i => [$name, $file, $mime, $type, $size, $uploader, $usedDays]) {
            $uuid = (string) Str::uuid();
            $ext = pathinfo($file, PATHINFO_EXTENSION);
            $path = "gallery/{$this->tenant->id}/{$uuid}.{$ext}";
            if ($this->mediaDir && is_file($this->mediaDir . '/' . $file)) {
                Storage::disk('local')->put($path, file_get_contents($this->mediaDir . '/' . $file));
            }
            $this->make(GalleryAsset::class, [
                'tenant_id' => $this->tenant->id, 'uuid' => $uuid, 'public_filename' => Str::slug($name) . '.' . $ext,
                'uploaded_by_user_id' => $this->users[$uploader]->id, 'name' => $name, 'path' => $path, 'mime_type' => $mime,
                'type' => $type, 'size_bytes' => $size, 'checksum' => hash('sha256', $uuid),
                'last_used_at' => $usedDays === null ? null : $this->now->copy()->subDays($usedDays),
                'meta' => ['original_filename' => $file], 'created_at' => $this->now->copy()->subDays(30 - $i * 2),
            ]);
        }

        $this->make(GalleryStorageRental::class, [
            'tenant_id' => $this->tenant->id, 'gb' => 3, 'price_per_gb_cents' => 190, 'currency' => 'BRL', 'status' => 'active',
            'started_at' => $this->now->copy()->subDays(20), 'renews_at' => $this->now->copy()->addDays(10),
        ]);
    }

    private function seedNumbers(): void
    {
        $rows = [
            ['whatsapp', '11', 'São Paulo', '5511987654321', 'active', 3990, 28, [['WhatsApp', 'Seu código do WhatsApp: 482-913. Não compartilhe.', '482913', 50], ['WhatsApp', 'Seu código do WhatsApp: 105-377', '105377', 12]]],
            ['ifood', '21', 'Rio de Janeiro', '5521998123344', 'active', 2990, 18, [['iFood', 'iFood: seu código de verificação é 7351', '7351', 300]]],
            ['telegram', '31', 'Belo Horizonte', '5531991234567', 'active', 3490, 1, []],
            ['uber', '41', 'Curitiba', null, 'pending', 2990, 0, []],
            ['instagram', '51', 'Porto Alegre', '5551998887766', 'cancelled', 3490, 40, [['Instagram', 'Bem-vindo ao Instagram! Seu link de confirmação foi enviado.', null, 55000]]],
            ['telegram', '21', 'Rio de Janeiro', null, 'failed', 3490, 25, []],
        ];

        foreach ($rows as $i => [$app, $ddd, $region, $msisdn, $status, $price, $days, $messages]) {
            $number = $this->make(VirtualNumber::class, [
                'tenant_id' => $this->tenant->id, 'provider_number_id' => 9100 + $i, 'msisdn' => $msisdn, 'app' => $app, 'ddd' => $ddd,
                'region' => $region, 'status' => $status, 'cost_cents' => (int) round($price * 0.62), 'price_cents' => $price, 'currency' => 'BRL',
                'purchased_at' => $this->now->copy()->subDays($days), 'renews_at' => $status === 'active' ? $this->now->copy()->subDays($days)->addDays(30) : null,
                'cancelled_at' => $status === 'cancelled' ? $this->now->copy()->subDays(10) : null,
                'meta' => $status === 'cancelled' ? ['cancel_reason' => 'no_credit'] : ($status === 'failed' ? ['failure' => ['status' => 422, 'code' => 'cap']] : null),
                'last_message_at' => $messages ? $this->ago(end($messages)[3]) : null,
                'created_at' => $this->now->copy()->subDays($days),
            ]);

            foreach ($messages as $j => [$sender, $body, $code, $minutes]) {
                $this->make(VirtualNumberMessage::class, [
                    'virtual_number_id' => $number->id, 'tenant_id' => $this->tenant->id, 'sender' => $sender, 'body' => $body,
                    'code' => $code, 'received_at' => $this->ago($minutes), 'dedupe_key' => "demo-{$i}-{$j}",
                    'created_at' => $this->ago($minutes),
                ]);
            }
        }
    }

    private function seedApiway(): void
    {
        $subs = [
            'unit' => ['unit', 'active', 1, 4990, $this->now->copy()->addDays(5), 'monthly', 40],
            'included' => ['plan_included', 'active', 1, 0, $this->now->copy()->addDays(14), 'monthly', 60],
            'provisioning' => ['unit', 'provisioning', 1, 4990, $this->now->copy()->addDays(30), 'monthly', 0],
            'cancelled' => ['unit', 'cancelled', 1, 4990, $this->now->copy()->subDays(6), 'monthly', 70],
        ];
        $instances = [];

        foreach ($subs as $key => [$source, $status, $qty, $unit, $expires, $cycle, $days]) {
            $sub = $this->make(ApiwaySubscription::class, [
                'tenant_id' => $this->tenant->id, 'provider_subscription_id' => $status === 'provisioning' ? null : 5500 + count($instances),
                'external_ref' => 'pingly-apw-demo-' . $key, 'source' => $source, 'cycle' => $cycle, 'quantity' => $qty,
                'unit_price_cents' => $unit, 'total_price_cents' => $unit * $qty, 'location_code' => 'br', 'status' => $status,
                'expires_at' => $expires, 'created_at' => $this->now->copy()->subDays($days),
            ]);

            if ($status === 'provisioning') {
                continue;
            }

            $instances[$key] = $this->make(ApiwayInstance::class, [
                'tenant_id' => $this->tenant->id, 'apiway_subscription_id' => $sub->id,
                'provider_instance_id' => $key === 'unit' ? 'b7c1f2e4-4a1d-4e7b-9f3a-2c8d0a5e1f90' : (string) Str::uuid(),
                'token' => 'manual-demo-token-' . $key, 'name' => $key === 'included' ? 'Reserva SP' : null,
                'ip_address' => '191.252.10.' . (20 + count($instances)), 'status' => $status === 'cancelled' ? 'cancelled' : 'active',
                'connection_id' => $key === 'unit' ? $this->conn['apiway']->id : null,
            ]);
        }

        $credentials = $this->conn['apiway']->credentials;
        $credentials['apiway_instance_id'] = $instances['unit']->id;
        $this->conn['apiway']->forceFill(['credentials' => $credentials])->save();
    }

    private function seedFlowPayments(): void
    {
        $flow = $this->flows['pix'];
        $pay = $this->nodes['pix.pay'];
        $rows = [
            [$this->pool['wa'][0], 8990, 'paid', 240, false], [$this->pool['apiway'][1], 14900, 'paid', 1500, false],
            [$this->people['mariana'], 29990, 'pending', 10, false], [$this->pool['wa'][8], 12000, 'expired', 3000, false],
            [$this->pool['wa'][10], 4500, 'paid', 4400, true],
        ];

        foreach ($rows as $i => [$contact, $amount, $status, $minutes, $late]) {
            $at = $this->ago($minutes);
            $this->make(FlowPayment::class, [
                'tenant_id' => $this->tenant->id, 'integration_id' => $this->integrations['openpix']->id, 'provider' => 'openpix',
                'contact_id' => $contact->id, 'flow_id' => $flow->id, 'flow_node_id' => $pay->id,
                'reference' => 'pingly-fp-' . Str::lower((string) Str::ulid()), 'provider_payment_id' => 'opx_' . Str::random(10),
                'method' => 'pix', 'amount_cents' => $amount, 'currency' => 'BRL', 'description' => 'Pedido ' . $contact->name,
                'status' => $status, 'pix_code' => '00020126580014BR.GOV.BCB.PIX0136manual-demo-' . $i . '5204000053039865802BR5913LOJA AURORA6009SAO PAULO6304ABCD',
                'expires_at' => $at->copy()->addHour(), 'paid_at' => $status === 'paid' ? $at->copy()->addMinutes($late ? 75 : 12) : null,
                'settled_at' => $status === 'pending' ? null : $at->copy()->addMinutes(15),
                'meta' => $late ? ['paid_late' => true] : null, 'created_at' => $at, 'updated_at' => $at,
            ]);
        }
    }

    private function seedInstagramPosts(): void
    {
        $posts = [
            ['scheduled', 'image', "Chegou a Coleção Primavera 🌸\nPeças leves para treinar com estilo. Link na bio!", $this->now->copy()->addDay()->setTime(15, 0), ['post-colecao-primavera.jpg']],
            ['draft', 'carousel', 'Monte seu kit de corrida: tênis, garrafa e boné 🏃‍♀️', null, ['produto-tenis-aurora-run.jpg', 'produto-garrafa.jpg', 'produto-bone.jpg']],
        ];

        foreach ($posts as [$status, $type, $caption, $scheduled, $files]) {
            $post = $this->make(InstagramPost::class, [
                'tenant_id' => $this->tenant->id, 'connection_id' => $this->conn['ig']->id, 'created_by' => $this->users['marina']->id,
                'status' => $status, 'media_type' => $type, 'caption' => $caption, 'scheduled_at' => $scheduled,
            ]);
            foreach ($files as $position => $file) {
                $this->make(InstagramPostItem::class, [
                    'instagram_post_id' => $post->id, 'position' => $position, 'media_type' => 'image', 'url' => $this->mediaUrl($file),
                ]);
            }
        }
    }
}

<?php

namespace Database\Seeders\ManualDemo;

use App\Models\AiHubRun;
use App\Models\Contact;
use App\Models\FlowState;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Sixty days of ordinary traffic, so Statistics, Live and the contact book
 * have a real shape: business-hour peaks, fast and slow replies, threads only
 * the bot answered, handoffs, failures and tags. Everything is resolved —
 * the open inbox stays the curated set from Inbox.
 */
trait History
{
    private array $firstNames = ['Ana', 'Bruno', 'Carla', 'Daniel', 'Elaine', 'Felipe', 'Giovana', 'Heitor', 'Isabela', 'Jonas',
        'Karina', 'Leandro', 'Mônica', 'Nicolas', 'Olívia', 'Rodrigo', 'Simone', 'Tiago', 'Úrsula', 'Vinícius', 'Wagner', 'Yasmin',
        'Alice', 'Bernardo', 'Cecília', 'Davi', 'Estela', 'Fabrício', 'Helena', 'Igor', 'Júlia', 'Luan', 'Manuela', 'Otávio', 'Priscila', 'Rafaela'];
    private array $lastNames = ['Silva', 'Santos', 'Oliveira', 'Pereira', 'Rodrigues', 'Almeida', 'Nascimento', 'Lima', 'Araújo',
        'Fernandes', 'Carvalho', 'Gomes', 'Martins', 'Rocha', 'Ribeiro', 'Alves', 'Monteiro', 'Mendes', 'Barros', 'Freitas', 'Cardoso', 'Teixeira'];

    protected function seedHistory(): void
    {
        mt_srand(2026);
        $this->seedPool();

        $mix = ['wa' => 30, 'apiway' => 22, 'ig' => 16, 'tg' => 9, 'widget' => 9, 'email' => 8, 'tiktok' => 3, 'email_fin' => 3];
        $bag = [];
        foreach ($mix as $key => $weight) {
            $bag = array_merge($bag, array_fill(0, $weight, $key));
        }

        $agents = ['marina', 'rafael', 'juliana', 'juliana', 'carlos', 'gabriela'];
        $tagKeys = ['orcamento', 'suporte', 'reclamacao', 'atacado', 'pagamento', 'urgente'];
        $replies = [
            'Oi! Claro, vou verificar para você 😊', 'Temos sim! Quer que eu separe?', 'O prazo é de 2 a 4 dias úteis.',
            'Pode me passar o número do pedido?', 'Já gerei o link de pagamento.', 'Troca solicitada com sucesso!',
        ];
        $asks = [
            'Olá, gostaria de um orçamento', 'Qual o prazo de entrega?', 'Vocês têm esse tênis no 40?', 'Meu pedido atrasou',
            'Aceitam Pix?', 'Quero trocar o tamanho', 'Tem desconto para atacado?', 'Onde fica a loja física?',
        ];

        for ($i = 0; $i < 330; $i++) {
            $key = $bag[mt_rand(0, count($bag) - 1)];
            $connection = $this->conn[$key];
            $contact = $this->pool[$key][mt_rand(0, count($this->pool[$key]) - 1)];
            $day = mt_rand(1, 60);
            $hour = mt_rand(0, 9) < 8 ? mt_rand(9, 19) : mt_rand(0, 23);
            $opened = $this->brt($day, $hour, mt_rand(0, 59));
            $isEmail = str_starts_with($key, 'email');

            $conversation = $this->conversation($connection, $contact, [
                'status' => 'active',
                'external_id' => $isEmail ? Str::slug('hist-' . $i . '-' . $contact->external_id) : $contact->external_id,
                'created_at' => $opened,
            ]);

            $meta = $isEmail ? ['email' => ['subject' => $asks[$i % count($asks)], 'from' => $contact->external_id, 'to' => [$connection->credentials['email']], 'cc' => []]] : null;
            $this->received($conversation, $opened, $asks[mt_rand(0, count($asks) - 1)], false, array_filter(['meta' => $meta]));

            if (mt_rand(1, 100) <= 60) {
                $this->tagConversation($conversation, [$tagKeys[mt_rand(0, count($tagKeys) - 1)]]);
            }

            $roll = mt_rand(1, 100);

            if ($roll <= 10) {
                // Nobody answered before the window closed.
                $closedAt = $opened->copy()->addHours(24);
                if ($key === 'wa' && $closedAt->isPast()) {
                    $this->note($conversation, $closedAt, 'messaging_window_expired', ['hours' => 24], 'Window expired.');
                }
                $this->resolveAt($conversation, $closedAt, null);
                continue;
            }

            $flowKey = in_array($key, ['wa'], true) ? 'inicial' : ($key === 'widget' ? 'suporte' : null);

            if ($flowKey && $roll <= 32) {
                $this->botOnly($conversation, $opened, $flowKey, $i);
                continue;
            }

            if ($flowKey && $roll <= 42) {
                $this->handoff($conversation, $opened, $flowKey);
            }

            $agent = $this->users[$agents[mt_rand(0, count($agents) - 1)]];
            $wait = $isEmail ? mt_rand(1800, 50000) : (mt_rand(1, 100) <= 70 ? mt_rand(20, 420) : mt_rand(600, 5400));
            $replyAt = $opened->copy()->addSeconds($wait);

            if ($replyAt->isFuture()) {
                $replyAt = $this->ago(5);
            }

            $conversation->forceFill(['user_id' => $agent->id])->saveQuietly();
            $failed = $key === 'wa' && mt_rand(1, 100) <= 7;
            $this->sent($conversation, $replyAt, $replies[mt_rand(0, count($replies) - 1)], [
                'sent_by_user_id' => $agent->id,
                'error' => $failed ? 'A janela de 24 horas fechou; use um modelo aprovado.' : null,
                'delivery_at' => $failed || $key === 'tg' ? null : $replyAt->copy()->addSeconds(3),
                'read_at' => $failed || in_array($key, ['tg', 'email', 'email_fin'], true) || mt_rand(1, 10) > 7 ? null : $replyAt->copy()->addMinutes(mt_rand(1, 30)),
            ]);

            $cursor = $replyAt->copy();
            for ($turn = 0, $turns = mt_rand(0, 2); $turn < $turns; $turn++) {
                $cursor->addMinutes(mt_rand(2, 25));
                $this->received($conversation, $cursor->copy(), 'Entendi, obrigado!');
                $cursor->addMinutes(mt_rand(1, 10));
                $this->sent($conversation, $cursor->copy(), 'Disponha! Qualquer coisa estou por aqui.', ['sent_by_user_id' => $agent->id]);
            }

            $resolvedAt = $cursor->copy()->addMinutes(mt_rand(5, 240));
            $this->resolveAt($conversation, $resolvedAt->isFuture() ? $this->ago(1) : $resolvedAt, $agent->id);
        }

        // Somebody replied "PARAR" to a campaign: the only opt-out signal WhatsApp gives.
        $optOut = $this->pool['apiway'][2];
        $conversation = $this->conversation($this->conn['apiway'], $optOut, ['status' => 'active', 'created_at' => $this->brt(4, 11, 0)]);
        $this->received($conversation, $this->brt(4, 11, 0), 'PARAR');
        $this->resolveAt($conversation, $this->brt(4, 12, 0), null);
        $optOut->forceFill(['broadcast_opted_out_at' => $this->brt(4, 11, 0)])->saveQuietly();
        foreach ([$this->pool['wa'][4], $this->pool['wa'][9]] as $contact) {
            $contact->forceFill(['broadcast_opted_out_at' => $this->now->copy()->subDays(mt_rand(5, 30))])->saveQuietly();
        }
    }

    private function seedPool(): void
    {
        $sizes = ['wa' => 22, 'apiway' => 16, 'ig' => 12, 'tg' => 7, 'widget' => 6, 'email' => 8, 'tiktok' => 3, 'email_fin' => 3];
        $photos = ['avatar-cliente-1.png', 'avatar-cliente-2.png', 'avatar-cliente-3.png', 'avatar-cliente-5.png', 'avatar-cliente-6.png', 'avatar-cliente-7.png', 'avatar-cliente-9.png'];
        $n = 0;

        foreach ($sizes as $key => $size) {
            for ($i = 0; $i < $size; $i++, $n++) {
                $first = $this->firstNames[$n % count($this->firstNames)];
                $last = $this->lastNames[($n * 7 + 3) % count($this->lastNames)];
                $name = "{$first} {$last}";
                $slug = Str::slug("{$first}.{$last}", '.');

                [$external, $extra] = match ($key) {
                    'wa', 'apiway' => [sprintf('55119%08d', 81000000 + $n * 137), []],
                    'ig' => [(string) (17841460000000 + $n), ['username' => $slug]],
                    'tg' => [(string) (650000000 + $n), ['username' => str_replace('.', '_', $slug)]],
                    'tiktok' => ['tt_user_' . (8000 + $n), ['username' => str_replace('.', '', $slug)]],
                    'widget' => ['visitor_' . substr(md5((string) $n), 0, 6), []],
                    default => ["{$slug}@email.example", []],
                };

                if ($n % 3 === 0) {
                    $extra['photo'] = $photos[$n % count($photos)];
                }

                $extra['created_at'] = $this->now->copy()->subDays(62 - ($n % 60));
                $this->pool[$key][] = $this->contact($this->conn[$key], $external, $name, $extra);
            }
        }

        // Tags on the people themselves (they follow the customer, not a thread).
        $this->tagContact($this->pool['wa'][0], ['vip', 'recorrente', 'atacado', 'orcamento', 'pagamento', 'urgente']);
        foreach ([1, 3, 5, 7] as $i) {
            $this->tagContact($this->pool['wa'][$i], ['recorrente']);
        }
        foreach ([0, 2] as $i) {
            $this->tagContact($this->pool['apiway'][$i], ['atacado']);
        }
        $this->tagContact($this->pool['ig'][1], ['vip']);
        $this->tagContact($this->pool['tg'][0], ['inativo']);
    }

    private function botOnly($conversation, Carbon $opened, string $flowKey, int $i): void
    {
        $flow = $this->flows[$flowKey];
        $agent = $flowKey === 'suporte' ? $this->ai['suporte'] : $this->ai['sofia'];
        $at = $opened->copy()->addSeconds(mt_rand(3, 15));

        $this->sent($conversation, $at, 'Olá! 👋 Sou a assistente virtual da Loja Aurora.', ['sent_by_flow_id' => $flow->id]);
        $answer = $this->sent($conversation, $at->copy()->addSeconds(20), 'Posso ajudar com prazos, trocas e pagamentos. Me conta o que precisa!', [
            'sent_by_flow_id' => $flow->id, 'sent_by_ai_hub_agent_id' => $agent->id,
        ]);

        $state = mt_rand(1, 10);
        $node = $flowKey === 'suporte' ? $this->nodes['suporte.ai'] : ($state <= 7 ? $this->nodes['inicial.end'] : $this->nodes['inicial.response']);
        $this->make(FlowState::class, [
            'conversation_id' => $conversation->id, 'flow_id' => $flow->id, 'current_node_id' => $node->id,
            'state_data' => [], 'status' => $state <= 7 ? 'completed' : ($state <= 9 ? 'stopped' : 'failed'),
            'completed_at' => $state <= 7 ? $at->copy()->addMinutes(2) : null,
            'created_at' => $opened, 'updated_at' => $at->copy()->addMinutes(2),
        ]);

        $this->aiRun($conversation, $agent, $answer, $at, false);
        $this->resolveAt($conversation, $at->copy()->addMinutes(mt_rand(10, 90)), null);
    }

    private function handoff($conversation, Carbon $opened, string $flowKey): void
    {
        $agent = $flowKey === 'suporte' ? $this->ai['suporte'] : $this->ai['sofia'];
        $at = $opened->copy()->addSeconds(10);
        $answer = $this->sent($conversation, $at, 'Vou chamar uma pessoa do nosso time para te ajudar, só um instante 🙂', [
            'sent_by_flow_id' => $this->flows[$flowKey]->id, 'sent_by_ai_hub_agent_id' => $agent->id,
        ]);
        $reasons = ['ai_requested', 'ai_requested', 'service_hours', 'flow_requested', 'ai_quota_exceeded'];
        $conversation->forceFill(['handoff_at' => $at, 'handoff_reason' => $reasons[mt_rand(0, count($reasons) - 1)]])->saveQuietly();

        $this->make(FlowState::class, [
            'conversation_id' => $conversation->id, 'flow_id' => $this->flows[$flowKey]->id,
            'current_node_id' => $flowKey === 'suporte' ? $this->nodes['suporte.ai']->id : $this->nodes['inicial.ai']->id,
            'state_data' => [], 'status' => 'stopped', 'created_at' => $opened, 'updated_at' => $at,
        ]);

        $this->aiRun($conversation, $agent, $answer, $at, true);
    }

    private function aiRun($conversation, $agent, $message, Carbon $at, bool $handoff): void
    {
        $input = mt_rand(900, 2600);
        $output = mt_rand(60, 320);
        $model = $agent->model ?: 'gpt-4o-mini';
        $failed = mt_rand(1, 100) <= 4;

        $this->make(AiHubRun::class, [
            'tenant_id' => $this->tenant->id, 'ai_hub_agent_id' => $agent->id, 'conversation_id' => $conversation->id,
            'message_id' => $message->id, 'hub_run_id' => 'run_' . Str::lower(Str::random(12)),
            'status' => $failed ? 'FAILED' : 'COMPLETED', 'provider' => str_starts_with($model, 'claude') ? 'ANTHROPIC' : 'OPENAI', 'model' => $model,
            'input_message' => 'Mensagem do cliente', 'output_message' => $failed ? null : $message->body,
            'input_tokens' => $input, 'output_tokens' => $output, 'total_tokens' => $input + $output,
            'cost_usd' => round(($input * 0.15 + $output * 0.6) / 1000000 * ($model === 'gpt-4o' ? 16 : 1), 6), 'cost_currency' => 'USD',
            'latency_ms' => mt_rand(900, 4800), 'handoff_triggered' => $handoff,
            'error' => $failed ? ['message' => 'Tempo de resposta do provedor esgotado'] : null,
            'started_at' => $at->copy()->subSeconds(3), 'completed_at' => $at, 'created_at' => $at, 'updated_at' => $at,
        ]);
    }
}

<?php

namespace Database\Seeders\ManualDemo;

use App\Models\AiHubAgent;
use App\Models\AiHubAgentProfile;
use App\Models\AiHubKnowledge;
use App\Models\AiHubProviderCredential;
use App\Models\AiHubSkill;
use App\Models\AiHubTenant;
use App\Models\AiHubTrainingExample;
use App\Models\AiModelPrice;
use App\Models\AiTokenPoolKey;
use App\Models\Flow;
use App\Models\FlowAssistantMessage;
use App\Models\FlowEdge;
use App\Models\FlowNode;
use App\Models\Integration;
use App\Models\LeadPipeline;
use App\Models\TrainedAgentBlueprint;
use App\Models\TrainedAgentCategory;
use App\Models\TrainedAgentHire;
use App\Services\Lead\PipelineProvisioner;
use Illuminate\Support\Str;

trait Automation
{
    /** @var array<string, Integration> */
    protected array $integrations = [];
    protected ?LeadPipeline $pipeline = null;

    protected function seedAutomation(): void
    {
        $this->pipeline = app(PipelineProvisioner::class)->ensureDefault($this->tenant->id)->load('stages');
        $this->seedIntegrations();
        $this->seedAiHub();
        $this->seedFlows();
        $this->seedTrainedAgents();

        $this->conn['wa']->forceFill(['flow_id' => $this->flows['inicial']->id, 'ai_suggest_agent_id' => $this->ai['sofia']->id])->save();
        $this->conn['apiway']->forceFill(['ai_suggest_agent_id' => $this->ai['sofia']->id])->save();
        $this->conn['widget']->forceFill(['flow_id' => $this->flows['suporte']->id])->save();
        $this->conn['ig']->forceFill(['ai_suggest_agent_id' => $this->ai['leo']->id])->save();
    }

    protected function stage(string $name): ?int
    {
        return $this->pipeline?->stages->firstWhere('name', $name)?->id;
    }

    private function seedIntegrations(): void
    {
        $defs = [
            'openpix' => ['openpix', 'Loja principal', ['app_id' => 'Q2xpZW50X0lkX21hbnVhbF9kZW1v'], [], ['account' => ['environment' => 'production'], 'webhook' => ['webhook_ids' => ['wh_manual_demo']]], null],
            'pixel' => ['meta_pixel', 'Pixel Loja', ['access_token' => 'EAAmanualdemo000000000000000000'], ['pixel_id' => '123456789012345', 'test_event_code' => 'TEST12345'], ['account' => ['environment' => 'test']], null],
            'ga4' => ['google_analytics', 'GA4 Site', ['api_secret' => 'manualDemoSecret99'], ['measurement_id' => 'G-ABC123XYZ9'], ['account' => ['environment' => 'production']], 'Não foi possível enviar o evento ao Google Analytics: o segredo da API foi recusado.'],
        ];

        foreach ($defs as $key => [$provider, $name, $credentials, $settings, $meta, $error]) {
            $this->integrations[$key] = $this->make(Integration::class, [
                'tenant_id' => $this->tenant->id, 'provider' => $provider, 'name' => $name,
                'credentials' => $credentials, 'settings' => $settings ?: null, 'meta' => $meta,
                'enabled' => true, 'webhook_token' => Str::random(40),
                'verified_at' => $this->now->copy()->subDays(20), 'last_used_at' => $this->ago(90),
                'last_error' => $error, 'last_error_at' => $error ? $this->ago(300) : null,
                'created_at' => $this->now->copy()->subDays(20),
            ]);
        }
    }

    private function seedAiHub(): void
    {
        $hubTenant = $this->make(AiHubTenant::class, [
            'tenant_id' => $this->tenant->id, 'external_id' => 'pingly_' . $this->tenant->id,
            'name' => 'Loja Aurora', 'status' => 'ACTIVE', 'created_at' => $this->now->copy()->subDays(80),
        ]);

        $pool = [];
        foreach (['OPENAI' => 'gpt-4o-mini', 'ANTHROPIC' => 'claude-haiku-4-5'] as $provider => $model) {
            $pool[$provider] = $this->make(AiTokenPoolKey::class, [
                'provider' => $provider, 'label' => "{$provider} pool #1", 'api_key' => 'sk-manual-demo-' . Str::random(12),
                'key_preview' => 'sk-...demo', 'default_model' => $model, 'status' => 'active', 'weight' => 1, 'max_tenants' => 50,
            ]);
        }

        foreach ([['OPENAI', 'gpt-4o', 'GPT-4o', 2.5, 10, 40, 1], ['OPENAI', 'gpt-4o-mini', 'GPT-4o mini', 0.15, 0.6, 60, 2],
                  ['ANTHROPIC', 'claude-sonnet-4-5', 'Claude Sonnet 4.5', 3, 15, 40, 3], ['ANTHROPIC', 'claude-haiku-4-5', 'Claude Haiku 4.5', 1, 5, 50, 4]] as [$provider, $model, $label, $in, $out, $markup, $order]) {
            $this->make(AiModelPrice::class, [
                'provider' => $provider, 'model' => $model, 'label' => $label, 'input_usd_per_1m' => $in,
                'output_usd_per_1m' => $out, 'markup_pct' => $markup, 'is_listed' => true, 'sort_order' => $order,
            ]);
        }

        $creds = [
            'openai' => ['OPENAI', 'OpenAI Produção', 'sk-...a1B9', 'gpt-4o-mini', null, ['ownerType' => 'customer']],
            'anthropic' => ['ANTHROPIC', 'Anthropic Claude', 'sk-ant-...X7qZ', 'claude-sonnet-4-5', null, ['ownerType' => 'customer']],
            'eleven' => ['ELEVENLABS', 'ElevenLabs Voz', 'sk_...9f2c', 'eleven_flash_v2_5', null, ['ownerType' => 'customer']],
            'rented' => ['OPENAI', 'OpenAI (plataforma)', 'sk-...plat', null, $pool['OPENAI']->id, ['ownerType' => 'platform']],
        ];

        foreach ($creds as $key => [$provider, $name, $preview, $model, $poolId, $metadata]) {
            $this->creds[$key] = $this->make(AiHubProviderCredential::class, [
                'ai_hub_tenant_id' => $hubTenant->id, 'hub_provider_credential_id' => 'cred_' . Str::lower(Str::random(10)),
                'provider' => $provider, 'name' => $name, 'key_preview' => $preview, 'default_model' => $model,
                'status' => 'ACTIVE', 'metadata' => $metadata, 'ai_token_pool_key_id' => $poolId,
                'created_at' => $this->now->copy()->subDays(70),
            ]);
        }

        $agents = [
            'sofia' => ['Sofia — Atendimento', 'Tira dúvidas gerais, horários, trocas e status de pedidos', 'gpt-4o-mini', 'openai', 0.4,
                'Você é a Sofia, assistente virtual da Loja Aurora, uma loja de moda esportiva. Responda em português do Brasil, com frases curtas e simpáticas. Confirme o número do pedido antes de informar status e ofereça o link de rastreio quando houver.'],
            'leo' => ['Leo — Vendas', 'Qualifica interessados e apresenta produtos e condições de pagamento', 'claude-sonnet-4-5', 'anthropic', 0.7,
                'Você é o Leo, consultor de vendas da Loja Aurora. Descubra o que o cliente procura, sugira no máximo três produtos e informe as condições de pagamento (Pix com 5% de desconto ou cartão em até 6x).'],
            'suporte' => ['Suporte técnico', 'Resolve problemas com relógios, apps e garantias', 'gpt-4o', 'rented', 0.3,
                'Você é o suporte técnico da Loja Aurora. Ajude com sincronização do Relógio Pulse, garantia e trocas por defeito. Se o cliente pedir uma pessoa, transfira.'],
        ];

        foreach ($agents as $key => [$name, $description, $model, $cred, $temperature, $prompt]) {
            $this->ai[$key] = $this->make(AiHubAgent::class, [
                'ai_hub_tenant_id' => $hubTenant->id, 'ai_hub_provider_credential_id' => $this->creds[$cred]->id,
                'hub_agent_id' => 'agt_' . Str::lower(Str::random(10)), 'external_id' => "pingly_{$this->tenant->id}_{$key}",
                'name' => $name, 'description' => $description, 'model' => $model, 'system_prompt' => $prompt,
                'temperature' => $temperature, 'max_tokens' => 800, 'status' => 'ACTIVE',
                'handoff_rules' => ['humanRequested' => true, 'angryCustomer' => true, 'outOfScope' => $key === 'suporte'],
                'created_at' => $this->now->copy()->subDays(60), 'updated_at' => $this->now->copy()->subDays(3),
            ]);
        }

        $sofia = $this->ai['sofia'];
        $this->make(AiHubAgentProfile::class, [
            'ai_hub_agent_id' => $sofia->id, 'language' => 'pt-BR', 'tone' => 'cordial, objetivo e acolhedor',
            'response_style' => 'mensagens curtas, uma pergunta por vez',
            'instructions' => ['Cumprimente pelo nome quando souber', 'Confirme o número do pedido antes de informar status', 'Ofereça o link de rastreio sempre que houver'],
            'limits' => ['Não prometa prazos de entrega fora da tabela', 'Não informe dados de outros clientes', 'Não negocie descontos acima de 10%'],
        ]);

        foreach ([
            ['Política de trocas', 'Trocas em até 7 dias corridos após o recebimento, com o produto sem uso e na embalagem original. A primeira troca tem frete grátis.', ['trocas', 'pós-venda']],
            ['Horário de atendimento', 'Segunda a sexta, das 9h às 18h. Sábado, das 9h às 13h. Fora desse horário a assistente virtual responde e um atendente retorna no próximo dia útil.', ['horários']],
            ['Prazos de entrega por região', 'Capital de SP: mesmo dia para pedidos até 12h. Sudeste: 2 a 4 dias úteis. Demais regiões: 5 a 8 dias úteis.', ['frete', 'entrega']],
            ['Formas de pagamento', 'Pix com 5% de desconto, cartão de crédito em até 6x sem juros e boleto à vista.', ['pix', 'cartão']],
        ] as [$title, $content, $tags]) {
            $this->make(AiHubKnowledge::class, [
                'ai_hub_agent_id' => $sofia->id, 'hub_knowledge_id' => 'kn_' . Str::lower(Str::random(8)),
                'title' => $title, 'content' => $content, 'tags' => $tags,
            ]);
        }

        foreach ([
            ['Consultar status do pedido', 'Quando o cliente perguntar onde está o pedido', ['Peça o número do pedido', 'Confirme o CPF do comprador'], ['category' => 'suporte']],
            ['Qualificar lead comercial', 'Quando o cliente demonstrar interesse em revenda ou atacado', ['Pergunte a cidade', 'Pergunte o volume mensal', 'Ofereça a tabela de atacado'], ['category' => 'vendas']],
            ['Registrar reclamação', 'Quando o cliente relatar um problema com o produto', ['Peça foto do defeito', 'Transfira para um atendente'], ['category' => 'suporte']],
            ['consultar_pedido', 'Use quando o cliente informar o número do pedido', null, ['type' => 'http_request', 'method' => 'GET',
                'endpoint' => 'https://api.lojaaurora.example/v1/pedidos/{{pedido}}', 'authorization' => 'Bearer ••••',
                'headers' => [['key' => 'Accept', 'value' => 'application/json']], 'body' => null,
                'response_example' => "{\n  \"status\": \"em_transito\",\n  \"previsao\": \"12/09\"\n}"]],
            ['abrir_chamado', 'Use para abrir um chamado de troca ou garantia', null, ['type' => 'http_request', 'method' => 'POST',
                'endpoint' => 'https://api.lojaaurora.example/v1/chamados', 'authorization' => 'Bearer ••••',
                'headers' => [['key' => 'Content-Type', 'value' => 'application/json']], 'body' => "{\"cpf\":\"{{cpf}}\",\"motivo\":\"{{motivo}}\"}",
                'response_example' => "{\n  \"protocolo\": \"CH-20931\"\n}"]],
        ] as [$name, $description, $instructions, $metadata]) {
            $this->make(AiHubSkill::class, [
                'ai_hub_agent_id' => $sofia->id, 'hub_skill_id' => 'sk_' . Str::lower(Str::random(8)),
                'name' => $name, 'description' => $description, 'instructions' => $instructions, 'metadata' => $metadata,
            ]);
        }

        foreach ([
            ['style_example', 'Oi, meu pedido não chegou', 'Poxa, sinto muito! Me passa o número do pedido que eu verifico agora 😊', 'Empatia primeiro, depois a pergunta objetiva'],
            ['style_example', 'Vocês têm loja física?', 'Temos sim! Fica na Av. Paulista, 1000, aberta de segunda a sábado. Quer que eu te mande a localização?', null],
            ['style_example', 'Qual o prazo para Campinas?', 'Para Campinas a entrega leva de 2 a 4 dias úteis depois da confirmação do pagamento 🚚', null],
            ['objection_handling', 'Achei caro', 'Entendo! No Pix você tem 5% de desconto, e o Aurora Run tem garantia de 1 ano. Posso te mostrar uma opção mais em conta também?', 'Nunca desvalorize o produto'],
        ] as [$type, $input, $output, $notes]) {
            $this->make(AiHubTrainingExample::class, [
                'ai_hub_agent_id' => $sofia->id, 'hub_example_id' => 'ex_' . Str::lower(Str::random(8)),
                'type' => $type, 'input' => $input, 'expected_output' => $output, 'notes' => $notes,
            ]);
        }
    }

    /**
     * @param array<int, array{0:string,1:string,2:?array,3:int,4:int}> $nodes key, type, data, x, y
     * @param array<int, array{0:string,1:string,2:?string}> $edges
     */
    private function buildFlow(string $key, string $name, array $nodes, array $edges, int $ageDays): Flow
    {
        $flow = $this->flows[$key] ?? $this->make(Flow::class, ['name' => $name, 'tenant_id' => $this->tenant->id]);
        $flow->forceFill([
            'created_at' => $this->now->copy()->subDays($ageDays),
            'last_updated_at' => $this->now->copy()->subDays(max(0, $ageDays - 5))->addHours(3),
        ])->save();

        foreach ($nodes as [$nodeKey, $type, $data, $x, $y]) {
            $this->nodes["{$key}.{$nodeKey}"] = $this->make(FlowNode::class, [
                'flow_id' => $flow->id, 'type' => $type, 'data' => $data, 'position_x' => $x, 'position_y' => $y,
            ]);
        }

        foreach ($edges as [$from, $to, $condition]) {
            $this->make(FlowEdge::class, [
                'source_node_id' => $this->nodes["{$key}.{$from}"]->id,
                'target_node_id' => $this->nodes["{$key}.{$to}"]->id,
                'condition_value' => $condition,
            ]);
        }

        return $this->flows[$key] = $flow;
    }

    private function seedFlows(): void
    {
        // Rows first, so go-to-flow nodes can point at flows built later.
        foreach (['inicial' => 'Atendimento inicial', 'pix' => 'Cobrança Pix', 'fora' => 'Fora do horário',
                  'pesquisa' => 'Pesquisa de satisfação', 'api' => 'Qualificação de leads (API)', 'suporte' => 'Suporte técnico',
                  'rascunho' => 'Black Friday (rascunho)'] as $key => $name) {
            $this->flows[$key] = $this->make(Flow::class, ['name' => $name, 'tenant_id' => $this->tenant->id]);
        }

        $text = fn (string $body, int $delay = 0) => ['message_type' => 'text', 'body' => $body, 'delay' => $delay];

        $this->buildFlow('inicial', 'Atendimento inicial', [
            ['start', 'start', null, 0, 260],
            ['welcome', 'message', ['label' => 'Boas-vindas', 'wait_for_reply' => false, 'messages' => [
                $text('Olá, {{contact.name}}! 👋 Que bom ter você aqui.'), $text('Sou a assistente virtual da Loja Aurora.', 2)]], 260, 220],
            ['menu', 'interactive', ['label' => 'Menu principal', 'interactive_type' => 'button', 'header' => 'Loja Aurora',
                'body' => 'Como podemos ajudar hoje?', 'footer' => 'Atendimento 24h', 'buttons' => [
                    ['id' => 'btn_comprar', 'title' => 'Quero comprar'], ['id' => 'btn_suporte', 'title' => 'Preciso de suporte'],
                    ['id' => 'btn_atendente', 'title' => 'Falar com atendente']]], 600, 190],
            ['response', 'response', ['label' => 'Pergunta: e-mail', 'body' => 'Ótimo! Qual é o seu e-mail para enviarmos as ofertas?',
                'message_type' => 'text', 'variable_key' => 'email', 'validation' => 'email',
                'error_message' => 'Não consegui ler esse e-mail. Pode repetir?', 'timeout_seconds' => 3600], 980, -160],
            ['tag', 'tagging', ['action' => 'add', 'target' => 'conversation', 'tags' => [$this->tags['orcamento']->id]], 1340, -240],
            ['lead', 'lead', ['pipeline_id' => $this->pipeline?->id, 'stage_id' => $this->stage('Qualificação'), 'only_forward' => true,
                'title' => 'Interesse via WhatsApp', 'value' => '', 'owner_id' => $this->users['carlos']->id], 1680, -240],
            ['pixel', 'pixel', ['integration_ids' => [$this->integrations['pixel']->id], 'event' => 'lead', 'value' => '', 'currency' => 'BRL', 'parameters' => []], 2020, -240],
            ['later', 'message', ['wait_for_reply' => false, 'messages' => [$text('Sem problemas! Quando quiser, é só chamar 😉')]], 1340, 40],
            ['hours', 'condition', ['label' => 'Dentro do horário?', 'field' => 'service_hours.is_open', 'operator' => 'equals', 'value' => 'true'], 980, 300],
            ['ai', 'ai_agent', ['ai_hub_agent_id' => $this->ai['sofia']->id,
                'welcoming_message' => 'Oi! Sou a Sofia, assistente da Loja Aurora. Me conta o que aconteceu? 🙂',
                'answer_first_message' => true, 'service_hours_behavior' => 'handoff_in_hours', 'response_delay_seconds' => 8,
                'response_audio' => ['mode' => 'dynamic']], 1340, 380],
            ['closed', 'message', ['wait_for_reply' => false, 'messages' => [$text('Estamos fora do horário de atendimento agora 🌙. Deixe sua mensagem que respondemos assim que abrirmos!')]], 1340, 720],
            ['end', 'status', ['value' => 'resolved'], 1700, 560],
            ['note', 'action', ['type' => 'internal_note', 'parameters' => ['note' => 'Cliente pediu atendente pelo menu inicial.']], 980, 980],
            ['human', 'action', ['type' => 'transfer_human', 'parameters' => []], 1340, 1000],
        ], [
            ['start', 'welcome', null], ['welcome', 'menu', null], ['menu', 'response', 'btn_comprar'], ['menu', 'hours', 'btn_suporte'],
            ['menu', 'note', 'btn_atendente'], ['response', 'tag', 'replied'], ['response', 'later', 'timeout'], ['tag', 'lead', null],
            ['lead', 'pixel', null], ['later', 'end', null], ['hours', 'ai', 'true'], ['hours', 'closed', 'false'], ['closed', 'end', null], ['note', 'human', null],
        ], 70);

        $this->buildFlow('pix', 'Cobrança Pix', [
            ['start', 'start', null, 0, 200],
            ['cpf', 'response', ['label' => 'Pergunta: CPF', 'body' => 'Para emitir o Pix, qual é o seu CPF?', 'message_type' => 'text',
                'variable_key' => 'cpf', 'validation' => 'number', 'error_message' => 'Digite só os números do CPF, por favor.', 'timeout_seconds' => 0], 280, 160],
            ['pay', 'payment', ['integration_id' => $this->integrations['openpix']->id, 'method' => 'pix', 'amount' => '49,90',
                'description' => 'Pedido {{contact.name}}', 'expires_in_minutes' => 60,
                'message' => 'Para finalizar, pague {{payment_amount}} com os dados abaixo:', 'send_qr_code' => true,
                'send_copy_paste' => true, 'send_link' => false, 'payer_document' => '{{cpf}}'], 620, 160],
            ['pixel', 'pixel', ['integration_ids' => [$this->integrations['pixel']->id, $this->integrations['ga4']->id], 'event' => 'purchase',
                'value' => '{{payment_value}}', 'currency' => 'BRL', 'parameters' => []], 980, 40],
            ['won', 'lead', ['pipeline_id' => $this->pipeline?->id, 'stage_id' => $this->stage('Cliente'), 'only_forward' => true, 'title' => '', 'value' => '{{payment_value}}', 'owner_id' => null], 1300, 40],
            ['expired', 'message', ['wait_for_reply' => false, 'messages' => [$text('O Pix expirou, mas posso gerar outro! Um atendente vai te ajudar.')]], 980, 380],
            ['human', 'action', ['type' => 'transfer_human', 'parameters' => []], 1300, 400],
        ], [['start', 'cpf', null], ['cpf', 'pay', 'replied'], ['pay', 'pixel', 'paid'], ['pay', 'expired', 'failed'], ['pixel', 'won', null], ['expired', 'human', null]], 40);

        $this->buildFlow('fora', 'Fora do horário', [
            ['start', 'start', null, 0, 160],
            ['hours', 'condition', ['field' => 'service_hours.is_open', 'operator' => 'equals', 'value' => 'false'], 280, 120],
            ['msg', 'message', ['wait_for_reply' => false, 'messages' => [$text('Olá! Nosso horário é de segunda a sexta, das 9h às 18h. Deixe sua mensagem que retornamos 💙')]], 620, 60],
            ['end', 'status', ['value' => 'resolved'], 960, 80],
        ], [['start', 'hours', null], ['hours', 'msg', 'true'], ['msg', 'end', null]], 55);

        $this->buildFlow('pesquisa', 'Pesquisa de satisfação', [
            ['start', 'start', null, 0, 200],
            ['ask', 'interactive', ['interactive_type' => 'button', 'body' => 'Como foi o seu atendimento hoje?', 'footer' => 'Leva 5 segundos',
                'buttons' => [['id' => 'nota_otimo', 'title' => 'Ótimo 😍'], ['id' => 'nota_bom', 'title' => 'Bom 🙂'], ['id' => 'nota_ruim', 'title' => 'Ruim 😕']]], 280, 160],
            ['tag', 'tagging', ['action' => 'add', 'target' => 'contact', 'tags' => [$this->tags['recorrente']->id]], 660, 20],
            ['thanks', 'message', ['wait_for_reply' => false, 'messages' => [$text('Obrigada pela avaliação! 💙')]], 660, 200],
            ['goto', 'go_to_flow', ['flow_id' => $this->flows['suporte']->id, 'carry_variables' => true], 660, 380],
        ], [['start', 'ask', null], ['ask', 'tag', 'nota_otimo'], ['ask', 'thanks', 'nota_bom'], ['ask', 'goto', 'nota_ruim']], 30);

        $this->buildFlow('api', 'Qualificação de leads (API)', [
            ['start', 'start', null, 0, 200],
            ['cnpj', 'response', ['label' => 'Pergunta: CNPJ', 'body' => 'Para liberar a tabela de atacado, qual é o CNPJ da sua loja?', 'message_type' => 'text',
                'variable_key' => 'cnpj', 'validation' => 'number', 'error_message' => 'Envie só os números do CNPJ.', 'timeout_seconds' => 1800], 280, 160],
            ['http', 'http_request', ['label' => 'Consulta CNPJ (HTTP)', 'method' => 'POST', 'url' => 'https://api.lojaaurora.example/v1/leads/consulta',
                'headers' => [['key' => 'Authorization', 'value' => 'Bearer {{token_api}}'], ['key' => 'Content-Type', 'value' => 'application/json']],
                'body' => '{"cnpj":"{{cnpj}}","origem":"whatsapp"}', 'timeout' => 15,
                'response_mappings' => [['path' => 'data.razao_social', 'variable' => 'razao_social'], ['path' => 'data.porte', 'variable' => 'porte']]], 640, 160],
            ['big', 'condition', ['field' => 'porte', 'operator' => 'equals', 'value' => 'grande'], 1000, 60],
            ['lead', 'lead', ['pipeline_id' => $this->pipeline?->id, 'stage_id' => $this->stage('Proposta'), 'only_forward' => true, 'title' => 'Atacado — {{razao_social}}', 'value' => '', 'owner_id' => $this->users['carlos']->id], 1340, 0],
            ['human', 'action', ['type' => 'transfer_human', 'parameters' => []], 1000, 360],
        ], [['start', 'cnpj', null], ['cnpj', 'http', 'replied'], ['http', 'big', 'success'], ['http', 'human', 'error'], ['big', 'lead', 'true'], ['big', 'human', 'false']], 25);

        $this->buildFlow('suporte', 'Suporte técnico', [
            ['start', 'start', null, 0, 160],
            ['msg', 'message', ['wait_for_reply' => false, 'messages' => [$text('Você está falando com o suporte técnico da Loja Aurora 🔧')]], 280, 120],
            ['ai', 'ai_agent', ['ai_hub_agent_id' => $this->ai['suporte']->id, 'welcoming_message' => 'Oi! Conte para mim qual é o problema e eu te ajudo.',
                'answer_first_message' => true, 'service_hours_behavior' => 'always_ai', 'response_delay_seconds' => 8, 'response_audio' => ['mode' => 'text_only']], 620, 100],
        ], [['start', 'msg', null], ['msg', 'ai', null]], 50);

        $this->buildFlow('rascunho', 'Black Friday (rascunho)', [['start', 'start', null, 0, 160]], [], 2);

        $blueprint = $this->blueprintOf($this->flows['inicial']);
        $this->make(FlowAssistantMessage::class, [
            'flow_id' => $this->flows['inicial']->id, 'tenant_id' => $this->tenant->id, 'user_id' => $this->users['marina']->id,
            'role' => 'user', 'content' => 'Crie uma saudação, um menu de 3 opções e a transferência para um atendente',
            'created_at' => $this->now->copy()->subDays(8),
        ]);
        $this->make(FlowAssistantMessage::class, [
            'flow_id' => $this->flows['inicial']->id, 'tenant_id' => $this->tenant->id, 'user_id' => null, 'role' => 'assistant',
            'content' => 'Montei uma saudação em duas mensagens, um menu com 3 botões e, no último, uma nota interna seguida da transferência para a fila humana.',
            'blueprint' => $blueprint, 'warnings' => [], 'created_at' => $this->now->copy()->subDays(8)->addMinute(),
        ]);
    }

    /** The assistant's blueprint format for a flow as it is now (key = node id). */
    private function blueprintOf(Flow $flow): array
    {
        $nodes = FlowNode::where('flow_id', $flow->id)->get();
        $ids = $nodes->pluck('id');

        return [
            'name' => $flow->name,
            'nodes' => $nodes->map(fn (FlowNode $n) => ['key' => (string) $n->id, 'type' => $n->type, 'data' => $n->data, 'position_x' => $n->position_x, 'position_y' => $n->position_y])->values()->all(),
            'edges' => FlowEdge::whereIn('source_node_id', $ids)->get()
                ->map(fn (FlowEdge $e) => ['source_key' => (string) $e->source_node_id, 'target_key' => (string) $e->target_node_id, 'condition_value' => $e->condition_value])->values()->all(),
        ];
    }

    private function seedTrainedAgents(): void
    {
        $extra = [
            ['contabilidade', 'Assistente Fiscal NF-e', 'Tira dúvidas sobre emissão e cancelamento de notas fiscais.', 'FileText', 9900],
            ['consultorio-medico', 'Confirmação de Consultas', 'Confirma, remarca e lembra pacientes das consultas.', 'CalendarCheck', 7900],
            ['academia-bem-estar', 'Retenção de Alunos', 'Reengaja alunos que pararam de treinar.', 'HeartPulse', 0],
        ];

        foreach ($extra as $i => [$category, $name, $tagline, $icon, $price]) {
            $this->make(TrainedAgentBlueprint::class, [
                'trained_agent_category_id' => TrainedAgentCategory::where('slug', $category)->value('id'),
                'name' => $name, 'slug' => Str::slug($name), 'tagline' => $tagline, 'icon' => $icon, 'model' => 'gpt-4o-mini',
                'system_prompt' => "Você é o agente \"{$name}\". Responda em português do Brasil.", 'temperature' => 0.5, 'max_tokens' => 800,
                'price_cents' => $price, 'currency' => 'BRL', 'is_active' => true, 'is_public' => true, 'sort_order' => 10 + $i,
            ]);
        }

        $fitness = TrainedAgentBlueprint::where('name', 'Consultor de Academia')->first();
        $clinic = TrainedAgentBlueprint::where('name', 'Recepção de Consultório')->first();
        $accounting = TrainedAgentBlueprint::where('name', 'Assistente Contábil')->first();

        $this->ai['fitness'] = $this->make(AiHubAgent::class, [
            'ai_hub_tenant_id' => $this->creds['openai']->ai_hub_tenant_id, 'ai_hub_provider_credential_id' => $this->creds['openai']->id,
            'hub_agent_id' => 'agt_' . Str::lower(Str::random(10)), 'external_id' => "pingly_{$this->tenant->id}_fitness",
            'name' => 'Consultor Fitness Aurora', 'description' => 'Indica produtos conforme o treino do cliente (contratado do catálogo)',
            'model' => $fitness?->model ?? 'gpt-4o-mini', 'system_prompt' => $fitness?->system_prompt ?? 'Consultor fitness.',
            'temperature' => 0.6, 'max_tokens' => 800, 'status' => 'ACTIVE',
            'handoff_rules' => ['humanRequested' => true, 'angryCustomer' => true, 'outOfScope' => false],
            'created_at' => $this->now->copy()->subDays(9),
        ]);

        $hires = [
            [$fitness, 'active', 'included', 0, 'Consultor Fitness Aurora', $this->ai['fitness']->id, 9, []],
            [$clinic, 'provisioning', 'included', 0, 'Recepção Parceira', null, 0, ['progress' => ['agent' => true]]],
            [$accounting, 'failed', 'purchased', 14900, 'Assistente Contábil Aurora', null, 4, ['failure' => ['reason' => 'Falha ao copiar o conhecimento para o hub de IA'], 'refunded_to_balance_at' => $this->now->copy()->subDays(4)->toIso8601String()]],
        ];

        foreach ($hires as $i => [$blueprint, $status, $source, $price, $agentName, $agentId, $days, $meta]) {
            if (! $blueprint) {
                continue;
            }

            $this->make(TrainedAgentHire::class, [
                'tenant_id' => $this->tenant->id, 'trained_agent_blueprint_id' => $blueprint->id, 'ai_hub_agent_id' => $agentId,
                'ai_hub_provider_credential_id' => $this->creds['openai']->id, 'external_ref' => 'pingly-ta-demo-' . ($i + 1),
                'source' => $source, 'status' => $status, 'agent_name' => $agentName, 'price_cents' => $price, 'currency' => 'BRL',
                'blueprint_snapshot' => ['name' => $blueprint->name, 'model' => $blueprint->model], 'meta' => $meta ?: null,
                'hired_at' => $status === 'active' ? $this->now->copy()->subDays($days) : null,
                'created_at' => $this->now->copy()->subDays($days)->subMinutes(5),
            ]);
        }
    }
}

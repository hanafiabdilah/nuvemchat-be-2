<?php

namespace Database\Seeders\ManualDemo;

use App\Models\Company;
use App\Models\Contact;
use App\Models\CrmAutomation;
use App\Models\CrmAutomationRun;
use App\Models\CrmCustomField;
use App\Models\CrmPipeline;
use App\Models\CrmPipelineStage;
use App\Models\CrmSavedView;
use App\Models\CrmSegment;
use App\Models\CrmTask;
use App\Models\Customer;
use App\Models\CustomerRelationship;
use App\Models\Deal;
use App\Models\Lead;
use App\Services\Crm\CrmTimeline;
use App\Services\Crm\LeadScoring;
use Throwable;

/**
 * The CRM of the demo workspace: the customers the contacts already became
 * (ContactObserver links every seeded contact), plus companies, two pipelines
 * of deals, leads with scores, tasks, logged activities, segments and
 * automations — enough for every CRM screen to look like a shop in use.
 */
trait Crm
{
    /** @var array<string, CrmPipelineStage> */
    private array $stages = [];

    /** @var array<string, Deal> */
    private array $deals = [];

    protected function seedCrm(): void
    {
        $tenantId = $this->tenant->id;
        $u = $this->users;

        // Custom fields first: customers below fill them in.
        $fields = [
            ['customer', 'tamanho_calcado', 'Tamanho de calçado', 'select', ['34', '35', '36', '37', '38', '39', '40', '41', '42', '43', '44']],
            ['customer', 'aniversario', 'Data de aniversário', 'date', null],
            ['company', 'cnpj', 'CNPJ', 'text', null],
        ];
        foreach ($fields as $i => [$entity, $key, $label, $type, $options]) {
            $this->make(CrmCustomField::class, [
                'tenant_id' => $tenantId, 'entity' => $entity, 'key' => $key, 'label' => $label,
                'type' => $type, 'options' => $options, 'position' => $i,
            ]);
        }

        $companies = [
            'bia' => ['Bia Store Moda Ltda', 'biastore.example', 'Varejo de moda', 'contato@biastore.example', '5511934560010', '12.345.678/0001-90', 'carlos'],
            'castro' => ['Castro Distribuidora', 'castrodistribuidora.example', 'Distribuição', 'compras@castrodistribuidora.example', '5519933450011', '23.456.789/0001-01', 'carlos'],
            'academia' => ['Academia Movimento', 'academiamovimento.example', 'Esporte e bem-estar', 'gerencia@academiamovimento.example', '5511922340012', '34.567.890/0001-12', 'rafael'],
        ];
        $company = [];
        foreach ($companies as $key => [$name, $domain, $industry, $email, $phone, $cnpj, $owner]) {
            $company[$key] = $this->make(Company::class, [
                'tenant_id' => $tenantId, 'name' => $name, 'domain' => $domain, 'industry' => $industry,
                'email' => $email, 'phone' => $phone, 'website' => 'https://'.$domain,
                'owner_user_id' => $u[$owner]->id, 'custom_fields' => ['cnpj' => $cnpj],
                'created_at' => $this->now->copy()->subDays(40), 'updated_at' => $this->now->copy()->subDays(2),
            ]);
        }

        // [person key => status, owner, company, job title, e-mail, custom fields]
        $profiles = [
            'mariana' => ['customer', 'rafael', null, null, 'mariana.souza@email.example', ['tamanho_calcado' => '37', 'aniversario' => '1992-12-14']],
            'joao' => ['prospect', 'carlos', null, null, 'joao.almeida@email.example', ['tamanho_calcado' => '42']],
            'bia' => ['customer', 'carlos', 'bia', 'Proprietária', 'bia@biastore.example', []],
            'aline' => ['customer', 'carlos', 'castro', 'Compradora', 'aline@castrodistribuidora.example', []],
            'ricardo' => ['prospect', 'rafael', 'academia', 'Gerente', 'ricardo@academiamovimento.example', []],
            'patricia' => ['customer', 'rafael', 'academia', 'Coordenadora de compras', 'patricia@academiamovimento.example', []],
            'pedro' => ['prospect', 'carlos', null, null, null, ['tamanho_calcado' => '41']],
            'marcos' => ['prospect', 'gabriela', null, null, null, []],
            'gustavo' => ['customer', 'gabriela', null, 'Treinador de corrida', 'gustavo.run@email.example', ['tamanho_calcado' => '43']],
            'lucas' => ['customer', 'gabriela', null, null, null, []],
            'thiago' => ['prospect', 'gabriela', null, null, null, []],
            'roberto' => ['customer', 'carlos', null, null, null, []],
            'vanessa' => ['new', null, null, null, null, []],
            'fernanda' => ['inactive', 'juliana', null, null, 'fe.lima@email.example', []],
        ];
        $customer = [];
        foreach ($profiles as $key => [$status, $owner, $companyKey, $job, $email, $custom]) {
            $c = $this->customerOf($this->people[$key] ?? null);
            if (! $c) {
                continue;
            }
            $c->forceFill(array_filter([
                'status' => $status,
                'owner_user_id' => $owner ? $u[$owner]->id : null,
                'company_id' => $companyKey ? $company[$companyKey]->id : null,
                'job_title' => $job,
                'email' => $email ?? $c->email,
                'custom_fields' => $custom ?: null,
            ], fn ($v) => $v !== null))->save();
            $customer[$key] = $c;
        }
        foreach (['camila', 'eduardo', 'diego', 'sergio', 'renata', 'paulo'] as $key) {
            if ($c = $this->customerOf($this->people[$key] ?? null)) {
                $customer[$key] = $c;
            }
        }

        // Two customers typed in by hand — one of them a likely duplicate of Mariana.
        $customer['julia'] = $this->make(Customer::class, [
            'tenant_id' => $tenantId, 'name' => 'Júlia Andrade', 'email' => 'julia.andrade@email.example',
            'phone' => '5511988770013', 'status' => 'prospect', 'source' => 'manual', 'owner_user_id' => $u['juliana']->id,
            'created_at' => $this->now->copy()->subDays(6), 'updated_at' => $this->now->copy()->subDays(6),
        ]);
        $customer['mariana_dup'] = $this->make(Customer::class, [
            'tenant_id' => $tenantId, 'name' => 'Mariana Souza', 'email' => 'mariana.souza@email.example',
            'status' => 'new', 'source' => 'import',
            'created_at' => $this->now->copy()->subDays(3), 'updated_at' => $this->now->copy()->subDays(3),
        ]);

        if (isset($customer['patricia'], $customer['ricardo'])) {
            $this->make(CustomerRelationship::class, [
                'tenant_id' => $tenantId, 'customer_id' => $customer['patricia']->id,
                'related_customer_id' => $customer['ricardo']->id, 'label' => 'Colega de trabalho',
            ]);
        }

        // Pipelines.
        $sales = $this->pipeline('Vendas', true, 0, [
            ['novo', 'Novo', 10, 'open', '#3b82f6'],
            ['qualificado', 'Qualificado', 25, 'open', '#06b6d4'],
            ['proposta', 'Proposta', 50, 'open', '#a855f7'],
            ['negociacao', 'Negociação', 75, 'open', '#f59e0b'],
            ['ganho', 'Ganho', 100, 'won', '#22c55e'],
            ['perdido', 'Perdido', 0, 'lost', '#ef4444'],
        ]);
        $wholesale = $this->pipeline('Atacado', false, 1, [
            ['at_contato', 'Contato inicial', 10, 'open', '#3b82f6'],
            ['at_amostras', 'Amostras enviadas', 40, 'open', '#06b6d4'],
            ['at_proposta', 'Proposta', 60, 'open', '#a855f7'],
            ['at_fechado', 'Pedido fechado', 100, 'won', '#22c55e'],
            ['at_perdido', 'Perdido', 0, 'lost', '#ef4444'],
        ]);

        // [key, pipeline, stage, person, title, value (R$), owner, close in days, entered days ago, company, extra]
        $deals = [
            ['mariana', $sales, 'negociacao', 'mariana', 'Kit presente de Natal (5 unidades)', 1450, 'rafael', 5, 2, null, ['canal_venda' => 'WhatsApp']],
            ['joao', $sales, 'proposta', 'joao', 'Tênis Aurora Run + Relógio Pulse', 1189, 'carlos', 7, 4, null, ['canal_venda' => 'WhatsApp']],
            ['pedro', $sales, 'qualificado', 'pedro', 'Mochila Urbana para a equipe', 899, 'carlos', 12, 3, null, []],
            ['roberto', $sales, 'qualificado', 'roberto', 'Boné Aurora (20 unidades)', 1380, 'carlos', 10, 1, null, []],
            ['marcos', $sales, 'novo', 'marcos', 'Jaqueta corta-vento (3 unidades)', 1047, 'gabriela', 15, 1, null, []],
            ['vanessa', $sales, 'novo', 'vanessa', 'Fone Bass Pro', 349, null, 20, 0, null, ['canal_venda' => 'Loja online']],
            ['thiago', $sales, 'proposta', 'thiago', 'Relógio Pulse Grafite', 799, 'gabriela', 4, 6, null, []],
            ['gustavo', $sales, 'ganho', 'gustavo', 'Kit equipe de corrida', 2300, 'gabriela', -3, 3, null, ['canal_venda' => 'Instagram']],
            ['lucas', $sales, 'ganho', 'lucas', 'Garrafa térmica (10 unidades)', 690, 'gabriela', -9, 9, null, []],
            ['fernanda', $sales, 'perdido', 'fernanda', 'Troca com upgrade do Aurora Run', 220, 'juliana', -6, 6, null, []],
            ['bia', $wholesale, 'at_proposta', 'bia', 'Pedido de revenda — coleção primavera', 8400, 'carlos', 8, 2, 'bia', ['canal_venda' => 'Revenda']],
            ['aline', $wholesale, 'at_amostras', 'aline', 'Reposição mensal — Castro Distribuidora', 12600, 'carlos', 14, 5, 'castro', ['canal_venda' => 'Revenda']],
            ['ricardo', $wholesale, 'at_contato', 'ricardo', 'Uniformes Academia Movimento', 5250, 'rafael', 25, 1, 'academia', []],
            ['patricia', $wholesale, 'at_fechado', 'patricia', 'Pedido de setembro — Academia Movimento', 3900, 'rafael', -15, 15, 'academia', []],
        ];
        $position = 0;
        foreach ($deals as [$key, $pipeline, $stageKey, $person, $title, $value, $owner, $closeIn, $enteredAgo, $companyKey, $custom]) {
            if (! isset($customer[$person])) {
                continue;
            }
            $stage = $this->stages[$stageKey];
            $kind = $stage->kind instanceof \BackedEnum ? $stage->kind->value : $stage->kind;
            $entered = $this->now->copy()->subDays($enteredAgo)->subHours(3);
            $deal = $this->make(Deal::class, [
                'tenant_id' => $tenantId, 'pipeline_id' => $pipeline->id, 'stage_id' => $stage->id,
                'customer_id' => $customer[$person]->id,
                'company_id' => $companyKey ? $company[$companyKey]->id : $customer[$person]->company_id,
                'title' => $title, 'value_cents' => $value * 100, 'currency' => 'BRL',
                'expected_close_date' => $this->now->copy()->addDays($closeIn)->toDateString(),
                'status' => $kind, 'owner_user_id' => $owner ? $u[$owner]->id : null,
                'position' => $position++, 'stage_entered_at' => $entered,
                'won_at' => $kind === 'won' ? $entered : null,
                'lost_at' => $kind === 'lost' ? $entered : null,
                'lost_reason' => $kind === 'lost' ? 'Preferiu o reembolso' : null,
                'created_at' => $this->now->copy()->subDays($enteredAgo + 8),
                'updated_at' => $entered,
            ]);
            $this->deals[$key] = $deal;

            $this->timeline($deal, CrmTimeline::CREATED, $owner, ['entity' => 'deal', 'title' => $title, 'stage' => $pipeline->stages()->orderBy('position')->first()?->name], $enteredAgo + 8);
            if ($kind === 'won') {
                $this->timeline($deal, CrmTimeline::DEAL_WON, $owner, ['entity' => 'deal', 'title' => $title, 'value_cents' => $value * 100], $enteredAgo);
            } elseif ($kind === 'lost') {
                $this->timeline($deal, CrmTimeline::DEAL_LOST, $owner, ['entity' => 'deal', 'title' => $title, 'reason' => 'Preferiu o reembolso'], $enteredAgo);
            } elseif ($stage->position > 0) {
                $this->timeline($deal, CrmTimeline::STAGE_CHANGED, $owner, ['entity' => 'deal', 'title' => $title, 'from' => 'Qualificado', 'to' => $stage->name], $enteredAgo);
            }
        }

        // Lead scoring, switched on with a few readable rules.
        $this->tenant->forceFill(['crm_lead_scoring' => [
            'enabled' => true,
            'rules' => [
                ['criterion' => 'has_email', 'points' => 10],
                ['criterion' => 'has_phone', 'points' => 10],
                ['criterion' => 'has_company', 'points' => 15],
                ['criterion' => 'value_at_least', 'value' => 100000, 'points' => 20],
                ['criterion' => 'wrote_within_days', 'value' => 7, 'points' => 25],
                ['criterion' => 'has_tag', 'value' => [$this->tags['atacado']->id], 'points' => 20],
            ],
        ]])->save();

        // [person, title, status, source, value (R$), owner, created days ago, extra]
        $leads = [
            ['eduardo', 'Orçamento de presente corporativo', 'contacted', 'live_chat_widget', 3000, 'juliana', 2, []],
            ['camila', 'Interesse em tênis infantil', 'new', 'live_chat_widget', null, null, 0, []],
            ['sergio', 'Revenda em Campinas', 'qualified', 'whatsapp_proxyhub', 6000, 'carlos', 4, []],
            ['renata', 'Compra para equipe de corrida', 'contacted', 'whatsapp_proxyhub', 1800, 'gabriela', 3, []],
            ['diego', 'Parceria com criador de conteúdo', 'new', 'instagram', null, null, 1, []],
            ['paulo', 'Pediu catálogo de atacado', 'disqualified', 'whatsapp_proxyhub', null, 'carlos', 9, ['disqualified_reason' => 'Só compra no varejo']],
            ['julia', 'Lista de casamento', 'new', 'manual', 2500, 'juliana', 1, []],
            ['gustavo', 'Kit para a assessoria de corrida', 'converted', 'instagram', 2300, 'gabriela', 12, ['deal' => 'gustavo']],
            ['roberto', 'Bonés para evento da empresa', 'converted', 'telegram', 1380, 'carlos', 6, ['deal' => 'roberto']],
        ];
        $leadRows = [];
        foreach ($leads as [$person, $title, $status, $source, $value, $owner, $daysAgo, $extra]) {
            if (! isset($customer[$person])) {
                continue;
            }
            $deal = isset($extra['deal']) ? ($this->deals[$extra['deal']] ?? null) : null;
            $created = $this->now->copy()->subDays($daysAgo)->subHours(2);
            $lead = $this->make(Lead::class, [
                'tenant_id' => $tenantId, 'customer_id' => $customer[$person]->id, 'title' => $title,
                'status' => $status, 'source' => $source, 'value_cents' => $value ? $value * 100 : null,
                'owner_user_id' => $owner ? $u[$owner]->id : null,
                'disqualified_reason' => $extra['disqualified_reason'] ?? null,
                'converted_deal_id' => $deal?->id,
                'converted_at' => $deal ? $created->copy()->addDays(2) : null,
                'created_at' => $created, 'updated_at' => $created,
            ]);
            if ($deal) {
                $deal->forceFill(['lead_id' => $lead->id])->save();
                $this->timeline($lead, CrmTimeline::LEAD_CONVERTED, $owner, ['entity' => 'lead', 'title' => $title, 'deal_id' => $deal->id], max(0, $daysAgo - 2));
            }
            $this->timeline($lead, CrmTimeline::CREATED, $owner, ['entity' => 'lead', 'title' => $title, 'source' => $source], $daysAgo);
            $leadRows[$person] = $lead;
        }
        foreach ($leadRows as $lead) {
            try {
                LeadScoring::apply($lead);
            } catch (Throwable) {
                $lead->forceFill(['score' => 40])->save();
            }
        }

        // Tasks: one overdue, one done, the rest spread over the week.
        $tasks = [
            ['Ligar para a Mariana sobre o kit de Natal', 'high', 'rafael', $this->brt(0, 16), 'deal', 'mariana', null],
            ['Enviar proposta de revenda para a Bia Store', 'normal', 'carlos', $this->brt(-1, 10), 'deal', 'bia', null],
            ['Confirmar se as amostras chegaram na Castro Distribuidora', 'high', 'carlos', $this->brt(2, 11), 'deal', 'aline', null],
            ['Retornar o orçamento do Eduardo', 'normal', 'juliana', $this->brt(-3, 14), 'lead', 'eduardo', null],
            ['Pedir uma avaliação ao Gustavo', 'low', 'gabriela', $this->brt(1, 15), 'deal', 'gustavo', $this->brt(1, 15)],
            ['Atualizar o cadastro da Academia Movimento', 'low', 'marina', null, 'company', 'academia', null],
            ['Aprovar o desconto do pedido de revenda', 'high', 'marina', $this->brt(0, 18), 'deal', 'bia', null],
            ['Revisar a proposta da Castro Distribuidora', 'normal', 'marina', $this->brt(1, 9), 'deal', 'aline', null],
        ];
        foreach ($tasks as [$title, $priority, $who, $due, $subjectType, $subjectKey, $done]) {
            [$subjectId, $customerId] = match ($subjectType) {
                'deal' => [($this->deals[$subjectKey] ?? null)?->id, ($this->deals[$subjectKey] ?? null)?->customer_id],
                'lead' => [($leadRows[$subjectKey] ?? null)?->id, ($leadRows[$subjectKey] ?? null)?->customer_id],
                'company' => [$company[$subjectKey]->id, null],
            };
            if (! $subjectId) {
                continue;
            }
            $task = $this->make(CrmTask::class, [
                'tenant_id' => $tenantId, 'title' => $title, 'priority' => $priority,
                'due_at' => $due, 'remind_at' => $due?->copy()->subHour(),
                'reminded_at' => $due && $due->isPast() ? $due->copy()->subHour() : null,
                'completed_at' => $done, 'completed_by' => $done ? $u[$who]->id : null,
                'assignee_user_id' => $u[$who]->id, 'created_by' => $u['rafael']->id,
                'subject_type' => $subjectType, 'subject_id' => $subjectId, 'customer_id' => $customerId,
                'created_at' => $this->now->copy()->subDays(4), 'updated_at' => $done ?? $this->now->copy()->subDays(4),
            ]);
            if ($done && $task->subject) {
                $this->timeline($task->subject, CrmTimeline::TASK_COMPLETED, $who, ['title' => $title, 'task_id' => $task->id], 1);
            }
        }

        // Logged activities — calls, notes and a meeting.
        if (isset($customer['mariana'])) {
            $this->timeline($customer['mariana'], 'note', 'rafael', [], 20, 'Cliente VIP desde 2024 — compra todo ano na Black Friday e prefere entrega pela manhã.');
            $this->timeline($this->deals['mariana'] ?? $customer['mariana'], 'call', 'rafael', ['outcome' => 'Interessada', 'duration_minutes' => 12], 1, 'Conversamos sobre o kit de Natal. Quer embalagem personalizada com cartão para cada presente.');
        }
        if (isset($this->deals['bia'])) {
            $this->timeline($this->deals['bia'], 'meeting', 'carlos', ['duration_minutes' => 45], 3, 'Reunião por vídeo para apresentar a coleção primavera e as condições de revenda.');
        }
        if (isset($this->deals['aline'])) {
            $this->timeline($this->deals['aline'], 'email', 'carlos', [], 5, 'Enviei a tabela de preços de atacado e o prazo de entrega das amostras.');
        }

        // Segments and a shared saved view.
        $this->make(CrmSegment::class, [
            'tenant_id' => $tenantId, 'name' => 'Clientes VIP', 'description' => 'Quem tem a tag VIP e já comprou',
            'match' => 'all', 'created_by' => $u['marina']->id,
            'rules' => [
                ['field' => 'tags', 'operator' => 'has_any', 'value' => [$this->tags['vip']->id]],
                ['field' => 'won_deal', 'operator' => 'exists'],
            ],
        ]);
        $this->make(CrmSegment::class, [
            'tenant_id' => $tenantId, 'name' => 'Sem contato há 30 dias', 'description' => 'Para a campanha de reativação',
            'match' => 'all', 'created_by' => $u['rafael']->id,
            'rules' => [['field' => 'last_interaction', 'operator' => 'older_than_days', 'value' => 30]],
        ]);
        $this->make(CrmSegment::class, [
            'tenant_id' => $tenantId, 'name' => 'Revendedores', 'description' => null,
            'match' => 'any', 'created_by' => $u['carlos']->id,
            'rules' => [
                ['field' => 'tags', 'operator' => 'has_any', 'value' => [$this->tags['atacado']->id]],
                ['field' => 'company', 'operator' => 'is_not_empty'],
            ],
        ]);
        $this->make(CrmSavedView::class, [
            'tenant_id' => $tenantId, 'user_id' => $u['marina']->id, 'entity' => 'customers', 'name' => 'Meus clientes ativos',
            'filters' => ['status' => ['customer'], 'owner' => 'me'], 'shared' => true,
        ]);

        // Automations, one of them with a few runs behind it.
        $welcome = $this->make(CrmAutomation::class, [
            'tenant_id' => $tenantId, 'name' => 'Distribuir leads novos', 'description' => 'Revezamento entre Carlos e Gabriela',
            'trigger' => 'lead_created', 'trigger_config' => null, 'match' => 'all', 'conditions' => [],
            'actions' => [
                ['type' => 'assign_owner', 'mode' => 'round_robin', 'user_ids' => [$u['carlos']->id, $u['gabriela']->id]],
                ['type' => 'create_task', 'title' => 'Fazer o primeiro contato', 'due_in_days' => 1, 'priority' => 'high', 'assignee' => 'owner'],
            ],
            'is_active' => true, 'runs_count' => 14, 'failures_count' => 0, 'last_run_at' => $this->ago(95),
            'created_by' => $u['marina']->id,
        ]);
        $this->make(CrmAutomation::class, [
            'tenant_id' => $tenantId, 'name' => 'Negócio ganho vira cliente', 'description' => null,
            'trigger' => 'deal_won', 'trigger_config' => null, 'match' => 'all', 'conditions' => [],
            'actions' => [
                ['type' => 'set_customer_status', 'status' => 'customer'],
                ['type' => 'add_customer_tags', 'tag_ids' => [$this->tags['recorrente']->id]],
                ['type' => 'notify', 'recipients' => 'owner', 'message' => 'Parabéns! Mais uma venda fechada.'],
            ],
            'is_active' => true, 'runs_count' => 6, 'failures_count' => 0, 'last_run_at' => $this->ago(60 * 24 * 3),
            'created_by' => $u['marina']->id,
        ]);
        $this->make(CrmAutomation::class, [
            'tenant_id' => $tenantId, 'name' => 'Lembrar de negócios parados', 'description' => 'Avisa quem cuida do negócio',
            'trigger' => 'deal_stale', 'trigger_config' => ['days' => 7], 'match' => 'all',
            'conditions' => [['field' => 'value', 'operator' => 'at_least', 'value' => 100000]],
            'actions' => [['type' => 'create_task', 'title' => 'Retomar o contato com o cliente', 'due_in_days' => 0, 'priority' => 'normal', 'assignee' => 'owner']],
            'is_active' => false, 'runs_count' => 0, 'failures_count' => 0,
            'created_by' => $u['rafael']->id,
        ]);
        foreach (['sergio' => 'Revenda em Campinas', 'renata' => 'Compra para equipe de corrida', 'eduardo' => 'Orçamento de presente corporativo'] as $person => $title) {
            if (! isset($leadRows[$person])) {
                continue;
            }
            $this->make(CrmAutomationRun::class, [
                'tenant_id' => $tenantId, 'automation_id' => $welcome->id, 'trigger' => 'lead_created',
                'subject_type' => 'lead', 'subject_id' => $leadRows[$person]->id, 'subject_title' => $title,
                'customer_id' => $leadRows[$person]->customer_id, 'status' => 'succeeded',
                'results' => [['type' => 'assign_owner', 'ok' => true], ['type' => 'create_task', 'ok' => true]],
                'finished_at' => $leadRows[$person]->created_at, 'created_at' => $leadRows[$person]->created_at,
                'updated_at' => $leadRows[$person]->created_at,
            ]);
        }
    }

    /**
     * Two flows built after everything else (capture scripts address the
     * older flows' nodes by id): one with the AI media node, one using the
     * CRM actions of the Action node.
     */
    protected function seedCrmFlows(): void
    {
        $text = fn (string $body) => ['message_type' => 'text', 'body' => $body, 'delay' => 0];

        $this->buildFlow('retrato', 'Retrato com o Aurora Run', [
            ['start', 'start', null, 0, 200],
            ['foto', 'wait_response', ['label' => 'Pede a foto', 'message' => 'Me manda uma foto sua de corpo inteiro que eu crio três imagens suas usando o Aurora Run 📸',
                'variable_key' => '', 'timeout_seconds' => 3600, 'timeout_unit' => 'hours', 'buffer_seconds' => 0, 'validation' => 'any'], 280, 160],
            ['midia', 'ai_media', [
                'label' => 'Gera os retratos', 'media_type' => 'image', 'provider_credential_id' => $this->creds['openai']->id,
                'prompt' => 'A pessoa da foto correndo num parque ao amanhecer, usando o tênis Aurora Run coral. Foto realista, luz suave.',
                'images' => [
                    ['prompt' => 'A pessoa da foto correndo num parque ao amanhecer, usando o tênis Aurora Run coral. Foto realista, luz suave.', 'caption' => 'No parque, logo cedo 🌅'],
                    ['prompt' => 'A mesma pessoa cruzando a linha de chegada de uma corrida de rua, com o Aurora Run grafite. Estilo foto de evento esportivo.', 'caption' => 'Na linha de chegada 🏁'],
                    ['prompt' => 'Retrato da pessoa sentada num banco amarrando o Aurora Run, fundo urbano desfocado.', 'caption' => 'Pronta para o próximo treino 👟'],
                ],
                'use_customer_image' => true,
                'reference_urls' => [$this->mediaUrl('produto-tenis-aurora-run.jpg')],
                'image' => ['size' => '1024x1536', 'quality' => 'medium'],
                'wait_message' => 'Recebi! Estou criando suas imagens, leva cerca de um minuto ⏳',
                'send_to_customer' => true,
            ], 640, 120],
            ['oferta', 'message', ['messages' => [$text('Gostou? O Aurora Run está com 15% de desconto hoje. Quer que eu reserve o seu tamanho?')]], 1020, 40],
            ['tarefa', 'action', ['type' => 'create_task', 'parameters' => ['task_title' => 'Oferecer o Aurora Run para {{contact.name}}', 'task_due_in_days' => 1, 'task_assignee_id' => $this->users['juliana']->id]], 1380, 40],
            ['falha', 'action', ['type' => 'transfer_human', 'parameters' => []], 1020, 340],
        ], [['start', 'foto', null], ['foto', 'midia', 'replied'], ['midia', 'oferta', 'generated'], ['midia', 'falha', 'failed'], ['oferta', 'tarefa', null]], 4);

        $this->buildFlow('atacado_crm', 'Cadastro de revendedor', [
            ['start', 'start', null, 0, 200],
            ['loja', 'wait_response', ['label' => 'Nome da loja', 'message' => 'Que bom que você quer revender a Loja Aurora! Qual é o nome da sua loja?',
                'variable_key' => 'loja', 'timeout_seconds' => 0, 'timeout_unit' => 'minutes', 'buffer_seconds' => 0, 'validation' => 'any'], 280, 160],
            ['lead', 'action', ['type' => 'create_lead', 'parameters' => ['lead_title' => 'Revenda — {{loja}}', 'lead_value_cents' => 500000]], 640, 160],
            ['tarefa', 'action', ['type' => 'create_task', 'parameters' => ['task_title' => 'Enviar a tabela de atacado para {{loja}}', 'task_due_in_days' => 1, 'task_assignee_id' => $this->users['carlos']->id]], 1000, 160],
            ['fim', 'message', ['messages' => [$text('Anotado! Nosso consultor Carlos te manda a tabela de atacado até amanhã.')]], 1360, 160],
        ], [['start', 'loja', null], ['loja', 'lead', 'replied'], ['lead', 'tarefa', null], ['tarefa', 'fim', null]], 3);
    }

    private function customerOf(?Contact $contact): ?Customer
    {
        if (! $contact) {
            return null;
        }
        $contact->refresh();

        return $contact->customer_id ? Customer::find($contact->customer_id) : null;
    }

    /** @param  list<array{0: string, 1: string, 2: int, 3: string, 4: string}>  $stages */
    private function pipeline(string $name, bool $default, int $position, array $stages): CrmPipeline
    {
        $pipeline = $this->make(CrmPipeline::class, [
            'tenant_id' => $this->tenant->id, 'name' => $name, 'position' => $position, 'is_default' => $default,
        ]);
        foreach ($stages as $i => [$key, $stageName, $probability, $kind, $color]) {
            $this->stages[$key] = $this->make(CrmPipelineStage::class, [
                'tenant_id' => $this->tenant->id, 'pipeline_id' => $pipeline->id, 'name' => $stageName,
                'position' => $i, 'probability' => $probability, 'kind' => $kind, 'color' => $color,
            ]);
        }

        return $pipeline;
    }

    private function timeline($subject, string $type, ?string $who, array $data, int $daysAgo, ?string $body = null): void
    {
        try {
            CrmTimeline::record(
                $subject,
                $type,
                $who ? $this->users[$who] : null,
                $data,
                body: $body,
                occurredAt: $this->now->copy()->subDays($daysAgo)->subMinutes(37),
            );
        } catch (Throwable $e) {
            $this->command?->warn('CRM timeline entry skipped: '.$e->getMessage());
        }
    }
}

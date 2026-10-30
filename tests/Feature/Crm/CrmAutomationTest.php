<?php

use App\Enums\Billing\BillingCycle;
use App\Enums\Billing\PaymentMethod;
use App\Enums\Billing\SubscriptionStatus;
use App\Enums\Connection\Channel;
use App\Enums\Connection\Status as ConnectionStatus;
use App\Enums\Conversation\Status as ConversationStatus;
use App\Enums\Flow\NodeType;
use App\Enums\Message\MessageType;
use App\Events\CrmChanged;
use App\Events\CrmNotice;
use App\Jobs\Crm\RunCrmAutomation;
use App\Models\Connection;
use App\Models\CrmActivity;
use App\Models\CrmAutomation;
use App\Models\CrmAutomationRun;
use App\Models\CrmPipeline;
use App\Models\CrmTask;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\Flow;
use App\Models\FlowEdge;
use App\Models\FlowNode;
use App\Models\FlowState;
use App\Models\Lead;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tag;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Billing\SubscriptionGate;
use App\Services\Crm\Automation\AutomationActions;
use App\Services\Crm\CrmTimeline;
use App\Services\Flow\FlowExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\CrmFixtures;

/*
 * CRM automations: when a lead, deal, customer or task changes — or stays
 * still too long — and it matches, do these things, and keep the history of
 * every run.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    Event::fake([CrmNotice::class, CrmChanged::class]);
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT1']]])]);
});

/** Someone who may build automations and work the sales they act on. */
function automationAdmin(?Tenant $tenant = null, string $name = 'Ana'): User
{
    return CrmFixtures::user([...CrmFixtures::SALES_ADMIN, 'crm-automations.manage'], $tenant, $name);
}

function automationStages(int $tenantId): array
{
    $pipeline = CrmPipeline::query()->where('tenant_id', $tenantId)->with('stages')->firstOrFail();

    return $pipeline->stages->mapWithKeys(fn ($stage) => [$stage->kind->value === 'open' ? 'open'.$stage->position : $stage->kind->value => $stage->id])->all();
}

function automationWhatsapp(User $user): Connection
{
    $connection = Connection::create([
        'tenant_id' => $user->tenant_id,
        'channel' => Channel::WhatsappOfficial,
        'name' => 'WA',
        'color' => '#22c55e',
        'status' => ConnectionStatus::Active,
        'credentials' => ['phone_number_id' => '111000111', 'access_token' => 'wa-token', 'business_account_id' => '222000222'],
    ]);

    $user->connections()->syncWithoutDetaching([$connection->id]);

    return $connection;
}

function automationFlowNode(Flow $flow, NodeType $type, ?array $data = null): FlowNode
{
    return $flow->nodes()->create(['type' => $type, 'data' => $data, 'position_x' => 0, 'position_y' => 0]);
}

test('a lead turning qualified goes to the next in turn, gets a follow-up task and a note that says who did it', function () {
    $admin = automationAdmin();
    $budi = CrmFixtures::user(CrmFixtures::SALES, $admin->tenant, 'Budi');
    $sari = CrmFixtures::user(CrmFixtures::SALES, $admin->tenant, 'Sari');
    $customer = Customer::create(['tenant_id' => $admin->tenant_id, 'name' => 'Toko Sinar', 'status' => 'new']);

    $this->actingAs($admin)->postJson('/api/crm/automations', [
        'name' => 'Qualified leads',
        'trigger' => 'lead_status_changed',
        'trigger_config' => ['to' => ['qualified']],
        'conditions' => [],
        'actions' => [
            ['type' => 'assign_owner', 'mode' => 'round_robin', 'user_ids' => [$budi->id, $sari->id]],
            ['type' => 'create_task', 'title' => 'Telepon {{customer}} soal {{title}}', 'due_in_days' => 1, 'assignee' => 'owner', 'priority' => 'high'],
            ['type' => 'add_note', 'body' => 'Diberikan ke {{owner}}'],
        ],
    ])->assertCreated()->assertJsonPath('data.trigger', 'lead_status_changed');

    $first = $this->actingAs($admin)->postJson('/api/leads', ['title' => 'Grosir', 'customer_id' => $customer->id])->json('data.id');
    $second = $this->actingAs($admin)->postJson('/api/leads', ['title' => 'Eceran', 'customer_id' => $customer->id])->json('data.id');

    // Not the status it is waiting for.
    $this->actingAs($admin)->putJson("/api/leads/{$first}", ['status' => 'contacted'])->assertOk();
    expect(CrmAutomationRun::count())->toBe(0);

    $this->actingAs($admin)->putJson("/api/leads/{$first}", ['status' => 'qualified'])->assertOk();
    $this->actingAs($admin)->putJson("/api/leads/{$second}", ['status' => 'qualified'])->assertOk();

    expect(Lead::find($first)->owner_user_id)->toBe($budi->id)
        ->and(Lead::find($second)->owner_user_id)->toBe($sari->id);

    $task = CrmTask::query()->where('subject_type', 'lead')->where('subject_id', $first)->firstOrFail();
    expect($task->title)->toBe('Telepon Toko Sinar soal Grosir')
        ->and($task->assignee_user_id)->toBe($budi->id)
        ->and($task->priority->value)->toBe('high')
        ->and($task->created_by)->toBeNull()
        ->and($task->due_at->isFuture())->toBeTrue();

    $note = CrmActivity::query()->where('subject_type', 'lead')->where('subject_id', $first)->where('type', 'note')->firstOrFail();
    expect($note->body)->toBe('Diberikan ke Budi')
        ->and($note->user_id)->toBeNull()
        ->and($note->data['automation'])->toBe('Qualified leads');

    // The reassignment itself is on the timeline as the automation's doing.
    $reassigned = CrmActivity::query()->where('subject_type', 'lead')->where('subject_id', $first)->where('type', CrmTimeline::UPDATED)->latest('id')->first();
    expect($reassigned->data['automation'] ?? null)->toBe('Qualified leads');

    $runs = CrmAutomationRun::query()->orderBy('id')->get();
    expect($runs)->toHaveCount(2)
        ->and($runs->pluck('status')->map->value->all())->toBe(['succeeded', 'succeeded'])
        ->and($runs[0]->results[0])->toMatchArray(['type' => 'assign_owner', 'status' => 'done', 'detail' => 'Budi'])
        ->and(CrmAutomation::first()->runs_count)->toBe(2);

    // Given to someone else: they hear about it, as when a person does it.
    Event::assertDispatched(CrmNotice::class, fn (CrmNotice $notice) => $notice->userId === $budi->id && $notice->kind === 'assigned');
});

test('conditions decide which records an automation acts on', function () {
    $admin = automationAdmin();
    $customer = Customer::create(['tenant_id' => $admin->tenant_id, 'name' => 'Rina', 'status' => 'new']);

    $this->actingAs($admin)->postJson('/api/crm/automations', [
        'name' => 'Big deals',
        'trigger' => 'deal_created',
        'match' => 'all',
        'conditions' => [
            ['field' => 'value', 'operator' => 'at_least', 'value' => 100000000],
            ['field' => 'customer_status', 'operator' => 'in', 'value' => ['new', 'prospect']],
        ],
        'actions' => [['type' => 'notify', 'recipients' => 'owner', 'message' => '{{title}} senilai {{value}}']],
    ])->assertCreated();

    $this->actingAs($admin)->postJson('/api/deals', ['title' => 'Kecil', 'customer_id' => $customer->id, 'value_cents' => 50000])->assertCreated();
    expect(CrmAutomationRun::count())->toBe(0);

    $this->actingAs($admin)->postJson('/api/deals', ['title' => 'Besar', 'customer_id' => $customer->id, 'value_cents' => 250000000])->assertCreated();

    expect(CrmAutomationRun::count())->toBe(1)
        ->and(CrmAutomationRun::first()->status->value)->toBe('succeeded');
    Event::assertDispatched(CrmNotice::class, fn (CrmNotice $notice) => $notice->kind === 'automation'
        && $notice->userId === $admin->id
        && $notice->payload['automation'] === 'Big deals'
        && str_starts_with($notice->payload['message'], 'Besar senilai R$'));
});

test('a change an automation makes does not start automations', function () {
    $admin = automationAdmin();
    $customer = Customer::create(['tenant_id' => $admin->tenant_id, 'name' => 'Dewi', 'status' => 'new']);
    $deal = $this->actingAs($admin)->postJson('/api/deals', ['title' => 'Paket', 'customer_id' => $customer->id])->json('data.id');
    $stages = automationStages($admin->tenant_id);

    // Into the second stage → straight on to the third; and anything landing
    // in the third would note it. The second must never run.
    $this->actingAs($admin)->postJson('/api/crm/automations', [
        'name' => 'Skip ahead', 'trigger' => 'deal_stage_changed', 'trigger_config' => ['stage_ids' => [$stages['open2']]], 'conditions' => [],
        'actions' => [['type' => 'move_deal_stage', 'stage_id' => $stages['open3']]],
    ])->assertCreated();
    $this->actingAs($admin)->postJson('/api/crm/automations', [
        'name' => 'Note third', 'trigger' => 'deal_stage_changed', 'trigger_config' => ['stage_ids' => [$stages['open3']]], 'conditions' => [],
        'actions' => [['type' => 'add_note', 'body' => 'Sampai tahap tiga']],
    ])->assertCreated();

    $this->actingAs($admin)->postJson("/api/deals/{$deal}/move", ['stage_id' => $stages['open2']])->assertOk();

    expect(Deal::find($deal)->stage_id)->toBe($stages['open3'])
        ->and(CrmAutomationRun::count())->toBe(1)
        ->and(CrmActivity::query()->where('type', 'note')->exists())->toBeFalse();

    // A person moving it there does.
    $this->actingAs($admin)->postJson("/api/deals/{$deal}/move", ['stage_id' => $stages['open1']])->assertOk();
    $this->actingAs($admin)->postJson("/api/deals/{$deal}/move", ['stage_id' => $stages['open3']])->assertOk();
    expect(CrmActivity::query()->where('type', 'note')->count())->toBe(1);
});

test('a won deal tags the person on their channels', function () {
    $admin = automationAdmin();
    $whatsapp = CrmFixtures::connection($admin);
    $contact = CrmFixtures::contact($whatsapp, '6281200000201', 'Maya');
    $vip = Tag::create(['tenant_id' => $admin->tenant_id, 'name' => 'VIP', 'color' => '#22c55e']);
    $prospect = Tag::create(['tenant_id' => $admin->tenant_id, 'name' => 'Prospek', 'color' => '#f59e0b']);
    $contact->tags()->attach($prospect->id);

    $this->actingAs($admin)->postJson('/api/crm/automations', [
        'name' => 'Won', 'trigger' => 'deal_won', 'conditions' => [],
        'actions' => [
            ['type' => 'add_customer_tags', 'tag_ids' => [$vip->id]],
            ['type' => 'remove_customer_tags', 'tag_ids' => [$prospect->id]],
        ],
    ])->assertCreated();

    $deal = $this->actingAs($admin)->postJson('/api/deals', ['title' => 'Raket', 'customer_id' => $contact->customer_id])->json('data.id');
    $this->actingAs($admin)->postJson("/api/deals/{$deal}/move", ['stage_id' => automationStages($admin->tenant_id)['won']])->assertOk();

    expect($contact->fresh()->tags->pluck('id')->all())->toBe([$vip->id])
        ->and(CrmAutomationRun::first()->status->value)->toBe('succeeded');
});

test('a deal stuck in a stage is acted on once per stay, and not when it moved on before the run', function () {
    $admin = automationAdmin();
    $customer = Customer::create(['tenant_id' => $admin->tenant_id, 'name' => 'Joko', 'status' => 'new']);

    $this->actingAs($admin)->postJson('/api/crm/automations', [
        'name' => 'Stale', 'trigger' => 'deal_stale', 'trigger_config' => ['days' => 3], 'conditions' => [],
        'actions' => [['type' => 'create_task', 'title' => 'Dorong {{title}}', 'due_in_days' => 0, 'assignee' => 'owner']],
    ])->assertCreated();

    $stuck = Deal::find($this->actingAs($admin)->postJson('/api/deals', ['title' => 'Lama', 'customer_id' => $customer->id])->json('data.id'));
    $fresh = Deal::find($this->actingAs($admin)->postJson('/api/deals', ['title' => 'Baru', 'customer_id' => $customer->id])->json('data.id'));
    $stages = automationStages($admin->tenant_id);
    Deal::query()->whereKey($stuck->id)->update(['stage_entered_at' => now()->subDays(5)]);
    Deal::query()->whereKey($fresh->id)->update(['stage_entered_at' => now()->subDay()]);

    $this->artisan('crm:automations-scan')->assertSuccessful();
    $this->artisan('crm:automations-scan')->assertSuccessful();

    expect(CrmAutomationRun::count())->toBe(1)
        ->and(CrmTask::query()->where('subject_type', 'deal')->where('subject_id', $stuck->id)->count())->toBe(1)
        ->and(CrmTask::query()->where('subject_type', 'deal')->where('subject_id', $fresh->id)->exists())->toBeFalse();

    // Another stay — moved, and stuck again — is another occurrence.
    $this->actingAs($admin)->postJson("/api/deals/{$stuck->id}/move", ['stage_id' => $stages['open2']])->assertOk();
    Deal::query()->whereKey($stuck->id)->update(['stage_entered_at' => now()->subDays(4)]);
    $this->artisan('crm:automations-scan')->assertSuccessful();
    expect(CrmAutomationRun::count())->toBe(2);

    // Claimed by a pass, then moved on before its turn came: nothing is done.
    Queue::fake();
    Deal::query()->whereKey($fresh->id)->update(['stage_entered_at' => now()->subDays(6)]);
    $this->artisan('crm:automations-scan')->assertSuccessful();
    $run = CrmAutomationRun::query()->where('subject_id', $fresh->id)->firstOrFail();
    $this->actingAs($admin)->postJson("/api/deals/{$fresh->id}/move", ['stage_id' => $stages['open2']])->assertOk();

    (new RunCrmAutomation($run->id))->handle(app(AutomationActions::class));

    expect($run->fresh()->status->value)->toBe('skipped')
        ->and($run->fresh()->error)->toBe('no_longer_due')
        ->and(CrmTask::query()->where('subject_type', 'deal')->where('subject_id', $fresh->id)->exists())->toBeFalse();
});

test('an overdue task tells whoever has it, once; a quiet lead is chased unless someone logged a call', function () {
    $admin = automationAdmin();
    $budi = CrmFixtures::user(CrmFixtures::SALES, $admin->tenant, 'Budi');
    $customer = Customer::create(['tenant_id' => $admin->tenant_id, 'name' => 'Lina', 'status' => 'new']);

    $this->actingAs($admin)->postJson('/api/crm/automations', [
        'name' => 'Overdue', 'trigger' => 'task_overdue', 'conditions' => [],
        'actions' => [['type' => 'notify', 'recipients' => 'owner', 'message' => 'Terlambat: {{title}}']],
    ])->assertCreated();
    $this->actingAs($admin)->postJson('/api/crm/automations', [
        'name' => 'Quiet', 'trigger' => 'lead_idle', 'trigger_config' => ['days' => 7], 'conditions' => [],
        'actions' => [['type' => 'set_lead_status', 'status' => 'contacted']],
    ])->assertCreated();

    CrmTask::create([
        'tenant_id' => $admin->tenant_id, 'subject_type' => 'customer', 'subject_id' => $customer->id, 'customer_id' => $customer->id,
        'title' => 'Kirim penawaran', 'assignee_user_id' => $budi->id, 'due_at' => now()->subHour(),
    ]);
    CrmTask::create([
        'tenant_id' => $admin->tenant_id, 'subject_type' => 'customer', 'subject_id' => $customer->id, 'customer_id' => $customer->id,
        'title' => 'Belum waktunya', 'assignee_user_id' => $budi->id, 'due_at' => now()->addDay(),
    ]);

    $quiet = Lead::create(['tenant_id' => $admin->tenant_id, 'customer_id' => $customer->id, 'title' => 'Diam', 'status' => 'new']);
    $called = Lead::create(['tenant_id' => $admin->tenant_id, 'customer_id' => $customer->id, 'title' => 'Ditelepon', 'status' => 'new']);
    Lead::query()->whereKey([$quiet->id, $called->id])->toBase()->update(['updated_at' => now()->subDays(10)]);
    CrmActivity::create([
        'tenant_id' => $admin->tenant_id, 'subject_type' => 'lead', 'subject_id' => $called->id, 'customer_id' => $customer->id,
        'type' => 'call', 'user_id' => $admin->id, 'occurred_at' => now()->subDay(),
    ]);

    $this->artisan('crm:automations-scan')->assertSuccessful();
    $this->artisan('crm:automations-scan')->assertSuccessful();

    Event::assertDispatchedTimes(CrmNotice::class, 1);
    Event::assertDispatched(CrmNotice::class, fn (CrmNotice $notice) => $notice->kind === 'automation'
        && $notice->userId === $budi->id
        && $notice->payload['message'] === 'Terlambat: Kirim penawaran');

    expect($quiet->fresh()->status->value)->toBe('contacted')
        ->and($called->fresh()->status->value)->toBe('new')
        ->and(CrmAutomationRun::count())->toBe(2);
});

test('a notice goes to the people the automation names', function () {
    $admin = automationAdmin();
    $operator = CrmFixtures::user(CrmFixtures::SALES, $admin->tenant, 'Operator');
    $customer = Customer::create(['tenant_id' => $admin->tenant_id, 'name' => 'Hadi', 'status' => 'new']);

    $this->actingAs($admin)->postJson('/api/crm/automations', [
        'name' => 'Tell them', 'trigger' => 'lead_created', 'conditions' => [],
        'actions' => [['type' => 'notify', 'recipients' => 'users', 'user_ids' => [$operator->id], 'message' => 'Lead novo']],
    ])->assertCreated();

    $this->actingAs($admin)->postJson('/api/leads', ['title' => 'Central', 'customer_id' => $customer->id])->assertCreated();
    Event::assertDispatched(CrmNotice::class, fn (CrmNotice $notice) => $notice->kind === 'automation' && $notice->userId === $operator->id);
});

test('a failing action is recorded with its reason, the rest still run, and repeated failures pause the automation', function () {
    $admin = automationAdmin();
    $budi = CrmFixtures::user(CrmFixtures::SALES, $admin->tenant, 'Budi');
    $customer = Customer::create(['tenant_id' => $admin->tenant_id, 'name' => 'Eka', 'status' => 'new']);

    $id = $this->actingAs($admin)->postJson('/api/crm/automations', [
        'name' => 'To Budi', 'trigger' => 'lead_created', 'conditions' => [],
        'actions' => [
            ['type' => 'assign_owner', 'mode' => 'user', 'user_id' => $budi->id],
            ['type' => 'add_note', 'body' => 'Masuk'],
        ],
    ])->assertCreated()->json('data.id');

    // Budi leaves the workspace.
    $budi->forceFill(['tenant_id' => Tenant::create(['user_id' => $budi->id])->id])->save();

    $this->actingAs($admin)->postJson('/api/leads', ['title' => 'Satu', 'customer_id' => $customer->id])->assertCreated();

    $run = CrmAutomationRun::first();
    expect($run->status->value)->toBe('partial')
        ->and($run->results[0])->toMatchArray(['type' => 'assign_owner', 'status' => 'failed', 'code' => 'user_missing'])
        ->and($run->results[1])->toMatchArray(['type' => 'add_note', 'status' => 'done']);

    // Only the failing action left: ten failures in a row and it stops.
    $this->actingAs($admin)->putJson("/api/crm/automations/{$id}", [
        'actions' => [['type' => 'assign_owner', 'mode' => 'user', 'user_id' => $admin->id], ['type' => 'notify', 'recipients' => 'owner', 'message' => 'x']],
    ])->assertOk();
    CrmAutomation::query()->whereKey($id)->update(['actions' => json_encode([['type' => 'assign_owner', 'mode' => 'user', 'user_id' => $budi->id]])]);

    foreach (range(1, RunCrmAutomation::PAUSE_AFTER_FAILURES + 2) as $n) {
        $this->actingAs($admin)->postJson('/api/leads', ['title' => "Lead {$n}", 'customer_id' => $customer->id])->assertCreated();
    }

    $automation = CrmAutomation::find($id);
    expect($automation->is_active)->toBeFalse()
        ->and($automation->paused_reason)->toBe('repeated_failures')
        ->and(CrmAutomationRun::query()->where('status', 'failed')->count())->toBe(RunCrmAutomation::PAUSE_AFTER_FAILURES);

    // Switched back on: a clean slate.
    $this->actingAs($admin)->putJson("/api/crm/automations/{$id}", ['is_active' => true])
        ->assertOk()->assertJsonPath('data.paused_reason', null);
    expect(CrmAutomation::find($id)->consecutive_failures)->toBe(0);
});

test('an automation is refused when it names what is not the workspace\'s or does not fit its trigger', function () {
    $admin = automationAdmin();
    $stranger = automationAdmin(null, 'Orang lain');
    $base = ['name' => 'X', 'conditions' => []];

    $this->actingAs($admin)->postJson('/api/crm/automations', $base + [
        'trigger' => 'lead_created', 'actions' => [['type' => 'assign_owner', 'mode' => 'user', 'user_id' => $stranger->id]],
    ])->assertStatus(422)->assertJsonValidationErrors('actions.0.user_id');

    $this->actingAs($admin)->postJson('/api/crm/automations', $base + [
        'trigger' => 'lead_created', 'actions' => [['type' => 'move_deal_stage', 'stage_id' => 1]],
    ])->assertStatus(422)->assertJsonValidationErrors('actions.0.type');

    $this->actingAs($admin)->postJson('/api/crm/automations', [
        'name' => 'X', 'trigger' => 'lead_created',
        'conditions' => [['field' => 'stage', 'operator' => 'in', 'value' => [1]]],
        'actions' => [['type' => 'add_note', 'body' => 'x']],
    ])->assertStatus(422)->assertJsonValidationErrors('conditions.0.field');

    $this->actingAs($admin)->postJson('/api/crm/automations', $base + [
        'trigger' => 'lead_idle', 'actions' => [['type' => 'add_note', 'body' => 'x']],
    ])->assertStatus(422)->assertJsonValidationErrors('trigger_config.days');

    $this->actingAs($admin)->postJson('/api/crm/automations', $base + [
        'trigger' => 'deal_created', 'actions' => [],
    ])->assertStatus(422)->assertJsonValidationErrors('actions');

    expect(CrmAutomation::count())->toBe(0);
});

test('automations are for those allowed to build them, and only on a plan with the CRM', function () {
    $admin = automationAdmin();
    $seller = CrmFixtures::user(CrmFixtures::SALES, $admin->tenant, 'Budi');
    $customer = Customer::create(['tenant_id' => $admin->tenant_id, 'name' => 'Tono', 'status' => 'new']);

    $this->actingAs($seller)->getJson('/api/crm/automations')->assertForbidden();
    $this->actingAs($seller)->getJson('/api/crm/automations/runs')->assertForbidden();

    $this->actingAs($admin)->postJson('/api/crm/automations', [
        'name' => 'Note', 'trigger' => 'lead_created', 'conditions' => [], 'actions' => [['type' => 'add_note', 'body' => 'x']],
    ])->assertCreated();
    $this->actingAs($admin)->getJson('/api/crm/automations/meta')->assertOk()->assertJsonStructure(['triggers', 'fields', 'actions', 'limits', 'people', 'flows', 'pipelines' => [['id', 'name', 'stages']]]);

    config(['services.billing.enforce' => true]);
    $features = ['chat' => true, 'crm' => false];
    $plan = Plan::create([
        'name' => 'Starter', 'slug' => 'auto-'.uniqid(), 'price_cents' => 9990, 'currency' => 'BRL',
        'billing_cycle' => BillingCycle::Monthly, 'is_active' => true, 'features' => $features, 'quotas' => [],
    ]);
    $subscription = Subscription::create([
        'tenant_id' => $admin->tenant_id, 'plan_id' => $plan->id, 'status' => SubscriptionStatus::Active,
        'payment_method' => PaymentMethod::Checkout, 'billing_cycle' => BillingCycle::Monthly, 'price_cents' => 9990, 'quantity' => 1,
        'current_period_start' => now(), 'current_period_end' => now()->addMonth(), 'quotas_snapshot' => [], 'features_snapshot' => $features,
    ]);
    $admin->tenant->forceFill(['current_subscription_id' => $subscription->id])->save();
    app(SubscriptionGate::class)->forget($admin->tenant->fresh());

    $this->actingAs($admin)->getJson('/api/crm/automations')->assertForbidden()->assertJsonPath('feature', 'crm');

    // A lead written anyway (an import, an API) starts nothing.
    Lead::create(['tenant_id' => $admin->tenant_id, 'customer_id' => $customer->id, 'title' => 'Diam', 'status' => 'new']);
    expect(CrmAutomationRun::count())->toBe(0);
});

test('starting a flow happens in a conversation nobody holds, never in one an operator has', function () {
    $admin = automationAdmin();
    $whatsapp = automationWhatsapp($admin);
    $contact = CrmFixtures::contact($whatsapp, '6281200000301', 'Wati');
    $conversation = CrmFixtures::conversation($contact, $whatsapp);

    $flow = Flow::create(['tenant_id' => $admin->tenant_id, 'name' => 'Sambutan prospek']);
    $start = automationFlowNode($flow, NodeType::Start);
    $message = automationFlowNode($flow, NodeType::Message, ['messages' => [['message_type' => 'text', 'body' => 'Halo dari tim', 'delay' => 0]]]);
    FlowEdge::create(['source_node_id' => $start->id, 'target_node_id' => $message->id]);

    $this->actingAs($admin)->postJson('/api/crm/automations', [
        'name' => 'Welcome', 'trigger' => 'lead_created', 'conditions' => [],
        'actions' => [['type' => 'start_flow', 'flow_id' => $flow->id]],
    ])->assertCreated();

    $this->actingAs($admin)->postJson('/api/leads', ['title' => 'Tanya', 'conversation_id' => $conversation->id])->assertCreated();

    expect(FlowState::query()->where('conversation_id', $conversation->id)->value('flow_id'))->toBe($flow->id)
        ->and($conversation->messages()->where('message_type', MessageType::Info)->get()
            ->contains(fn ($m) => ($m->meta['info']['code'] ?? null) === 'crm_automation_started_flow'))->toBeTrue()
        ->and(CrmAutomationRun::first()->results[0])->toMatchArray(['status' => 'done', 'detail' => 'Sambutan prospek']);

    // Someone has the thread: the automation leaves it alone.
    $other = CrmFixtures::contact($whatsapp, '6281200000302', 'Yanti');
    $held = CrmFixtures::conversation($other, $whatsapp, ['status' => ConversationStatus::Active, 'user_id' => $admin->id]);
    $this->actingAs($admin)->postJson('/api/leads', ['title' => 'Dipegang', 'conversation_id' => $held->id])->assertCreated();

    expect(FlowState::query()->where('conversation_id', $held->id)->exists())->toBeFalse()
        ->and(CrmAutomationRun::query()->latest('id')->first()->results[0])->toMatchArray(['status' => 'skipped', 'code' => 'conversation_with_human']);
});

test('a flow opens a lead for the person once and writes them a follow-up task', function () {
    $admin = automationAdmin();
    $budi = CrmFixtures::user(CrmFixtures::SALES, $admin->tenant, 'Budi');
    $whatsapp = automationWhatsapp($admin);
    $contact = CrmFixtures::contact($whatsapp, '6281200000401', 'Rudi');

    $flow = Flow::create(['tenant_id' => $admin->tenant_id, 'name' => 'Menu harga']);
    $start = automationFlowNode($flow, NodeType::Start);
    $lead = automationFlowNode($flow, NodeType::Action, ['type' => 'create_lead', 'parameters' => ['lead_title' => 'Minta harga — {{contact.name}}', 'lead_value_cents' => 150000000]]);
    $task = automationFlowNode($flow, NodeType::Action, ['type' => 'create_task', 'parameters' => ['task_title' => 'Kirim daftar harga', 'task_due_in_days' => 1, 'task_assignee_id' => $budi->id]]);
    FlowEdge::create(['source_node_id' => $start->id, 'target_node_id' => $lead->id]);
    FlowEdge::create(['source_node_id' => $lead->id, 'target_node_id' => $task->id]);

    $first = CrmFixtures::conversation($contact, $whatsapp);
    (new FlowExecutor)->triggerFlow($first, $flow);

    $opened = Lead::query()->where('customer_id', $contact->customer_id)->firstOrFail();
    expect($opened->title)->toBe('Minta harga — Rudi')
        ->and($opened->value_cents)->toBe(150000000)
        ->and($opened->source)->toBe('whatsapp_official')
        ->and($opened->conversation_id)->toBe($first->id)
        ->and(FlowState::query()->where('conversation_id', $first->id)->first()->state_data['lead_id'])->toBe((string) $opened->id);

    $created = CrmActivity::query()->where('subject_type', 'lead')->where('subject_id', $opened->id)->where('type', CrmTimeline::CREATED)->firstOrFail();
    expect($created->data['flow'] ?? null)->toBe('Menu harga');

    $followUp = CrmTask::query()->where('customer_id', $contact->customer_id)->firstOrFail();
    expect($followUp->title)->toBe('Kirim daftar harga')
        ->and($followUp->assignee_user_id)->toBe($budi->id)
        ->and($followUp->conversation_id)->toBe($first->id);

    $notes = $first->messages()->where('message_type', MessageType::Info)->get()->map(fn ($m) => $m->meta['info']['code'] ?? null)->all();
    expect($notes)->toContain('crm_lead_created_by_flow')->toContain('crm_task_created_by_flow');

    // The same person through the flow again, the lead still open: no second lead.
    $first->update(['status' => ConversationStatus::Resolved]);
    $second = CrmFixtures::conversation($contact, $whatsapp);
    (new FlowExecutor)->triggerFlow($second, $flow);

    expect(Lead::query()->where('customer_id', $contact->customer_id)->count())->toBe(1)
        ->and(CrmTask::query()->where('customer_id', $contact->customer_id)->count())->toBe(2);
});

test('the history lists every run with what each action did', function () {
    $admin = automationAdmin();
    $customer = Customer::create(['tenant_id' => $admin->tenant_id, 'name' => 'Nina', 'status' => 'new']);

    $id = $this->actingAs($admin)->postJson('/api/crm/automations', [
        'name' => 'Note', 'trigger' => 'lead_created', 'conditions' => [], 'actions' => [['type' => 'add_note', 'body' => 'x']],
    ])->json('data.id');

    $this->actingAs($admin)->postJson('/api/leads', ['title' => 'Lead A', 'customer_id' => $customer->id])->assertCreated();

    $runs = $this->actingAs($admin)->getJson('/api/crm/automations/runs')->assertOk();
    expect($runs->json('data.0.automation.name'))->toBe('Note')
        ->and($runs->json('data.0.subject'))->toMatchArray(['type' => 'lead', 'title' => 'Lead A'])
        ->and($runs->json('data.0.status'))->toBe('succeeded')
        ->and($runs->json('data.0.results.0.type'))->toBe('add_note');

    $this->actingAs($admin)->getJson("/api/crm/automations/{$id}/runs?status=failed")->assertOk()->assertJsonCount(0, 'data');
    $this->actingAs($admin)->getJson('/api/crm/automations')->assertOk()
        ->assertJsonPath('data.0.last_status', 'succeeded')->assertJsonPath('data.0.runs_count', 1);

    // Another workspace's automation is not there to read.
    $stranger = automationAdmin(null, 'Orang lain');
    $this->actingAs($stranger)->getJson("/api/crm/automations/{$id}")->assertNotFound();
    $this->actingAs($stranger)->getJson('/api/crm/automations/runs')->assertOk()->assertJsonCount(0, 'data');

    $this->actingAs($admin)->deleteJson("/api/crm/automations/{$id}")->assertOk();
    expect(CrmAutomationRun::count())->toBe(0);
});

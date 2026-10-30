<?php

use App\Enums\Billing\BillingCycle;
use App\Enums\Billing\PaymentMethod;
use App\Enums\Billing\SubscriptionStatus;
use App\Events\CrmChanged;
use App\Events\CrmNotice;
use App\Models\CrmActivity;
use App\Models\CrmPipeline;
use App\Models\CrmTask;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\Billing\SubscriptionGate;
use App\Services\Crm\CrmTimeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Spatie\Permission\Models\Role;
use Tests\Support\CrmFixtures;

/*
 * CRM sales: leads (worked from first contact to converted), deals moving
 * along pipeline boards, tasks with reminders, and logged activity — all about
 * the customer behind the conversation, all on their timeline.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    Event::fake([CrmNotice::class, CrmChanged::class]);
});

function salesStageIds(int $tenantId): array
{
    $pipeline = CrmPipeline::query()->where('tenant_id', $tenantId)->with('stages')->firstOrFail();

    return $pipeline->stages->mapWithKeys(fn ($stage) => [$stage->kind->value === 'open' ? 'open'.$stage->position : $stage->kind->value => $stage->id])->all();
}

test('a lead started from a conversation is about its customer, and makes them a prospect', function () {
    $user = CrmFixtures::user(CrmFixtures::SALES);
    $whatsapp = CrmFixtures::connection($user);
    $contact = CrmFixtures::contact($whatsapp, '6281200000101', 'Maya');
    $conversation = CrmFixtures::conversation($contact, $whatsapp);

    $response = $this->actingAs($user)->postJson('/api/leads', [
        'title' => 'Tanya paket gym tahunan',
        'conversation_id' => $conversation->id,
        'value_cents' => 450000000,
    ])->assertCreated();

    expect($response->json('data.customer.id'))->toBe($contact->customer_id)
        ->and($response->json('data.source'))->toBe('whatsapp_official')
        ->and($response->json('data.owner.id'))->toBe($user->id)
        ->and($response->json('data.status'))->toBe('new')
        ->and(Customer::find($contact->customer_id)->status->value)->toBe('prospect');

    $timeline = $this->actingAs($user)->getJson("/api/customers/{$contact->customer_id}/timeline")->assertOk()->json('data');
    $created = collect($timeline)->firstWhere('type', CrmTimeline::CREATED);

    expect($created['subject_type'])->toBe('lead')
        ->and($created['conversation_id'])->toBe($conversation->id);
});

test('giving a lead to someone else tells them; taking it yourself does not', function () {
    $user = CrmFixtures::user(CrmFixtures::SALES);
    $colleague = CrmFixtures::user(CrmFixtures::SALES, $user->tenant, 'Budi');
    $customer = Customer::create(['tenant_id' => $user->tenant_id, 'name' => 'Rina', 'status' => 'new']);

    $this->actingAs($user)->postJson('/api/leads', ['title' => 'Sendiri', 'customer_id' => $customer->id])->assertCreated();
    Event::assertNotDispatched(CrmNotice::class);

    $id = $this->actingAs($user)->postJson('/api/leads', ['title' => 'Untuk Budi', 'customer_id' => $customer->id, 'owner_user_id' => $colleague->id])
        ->assertCreated()->json('data.id');

    Event::assertDispatched(CrmNotice::class, fn (CrmNotice $notice) => $notice->userId === $colleague->id && $notice->kind === 'assigned' && $notice->payload['id'] === $id);
    Event::assertDispatched(CrmChanged::class);
});

test('converting a lead makes a deal in the first open stage and closes the lead', function () {
    $user = CrmFixtures::user(CrmFixtures::SALES);
    $customer = Customer::create(['tenant_id' => $user->tenant_id, 'name' => 'Andi', 'status' => 'new']);
    $leadId = $this->actingAs($user)->postJson('/api/leads', ['title' => 'Sepatu lari', 'customer_id' => $customer->id, 'value_cents' => 150000000])
        ->assertCreated()->json('data.id');

    $deal = $this->actingAs($user)->postJson("/api/leads/{$leadId}/convert")->assertCreated()->json('data');
    $stages = salesStageIds($user->tenant_id);

    expect($deal['stage_id'])->toBe($stages['open0'])
        ->and($deal['value_cents'])->toBe(150000000)
        ->and($deal['lead_id'])->toBe($leadId)
        ->and($deal['customer']['id'])->toBe($customer->id);

    $lead = Lead::find($leadId);
    expect($lead->status->value)->toBe('converted')->and($lead->converted_deal_id)->toBe($deal['id']);

    $this->actingAs($user)->postJson("/api/leads/{$leadId}/convert")->assertStatus(422);
    $this->actingAs($user)->putJson("/api/leads/{$leadId}", ['status' => 'qualified'])->assertStatus(422);
    $this->actingAs($user)->putJson("/api/leads/{$leadId}", ['status' => 'converted'])->assertStatus(422);
});

test('the board: winning a deal makes its customer a customer, losing keeps the reason, reopening clears it', function () {
    $user = CrmFixtures::user(CrmFixtures::SALES);
    $customer = Customer::create(['tenant_id' => $user->tenant_id, 'name' => 'Toko Sinar', 'status' => 'new']);

    $board = $this->actingAs($user)->getJson('/api/deals/board')->assertOk();
    expect($board->json('columns'))->toHaveCount(6)
        ->and(collect($board->json('columns'))->pluck('kind')->unique()->values()->all())->toBe(['open', 'won', 'lost']);

    $id = $this->actingAs($user)->postJson('/api/deals', ['title' => 'Grosir raket', 'customer_id' => $customer->id, 'value_cents' => 200000000])
        ->assertCreated()->json('data.id');
    $stages = salesStageIds($user->tenant_id);

    $this->actingAs($user)->postJson("/api/deals/{$id}/move", ['stage_id' => $stages['won']])
        ->assertOk()->assertJsonPath('data.status', 'won');
    expect(Deal::find($id)->won_at)->not->toBeNull()
        ->and($customer->fresh()->status->value)->toBe('customer')
        ->and(CrmActivity::where('customer_id', $customer->id)->where('type', CrmTimeline::DEAL_WON)->exists())->toBeTrue();

    $this->actingAs($user)->postJson("/api/deals/{$id}/move", ['stage_id' => $stages['lost'], 'lost_reason' => 'Harga'])
        ->assertOk()->assertJsonPath('data.status', 'lost')->assertJsonPath('data.lost_reason', 'Harga');
    // Losing never moves the customer back.
    expect($customer->fresh()->status->value)->toBe('customer');

    $this->actingAs($user)->postJson("/api/deals/{$id}/move", ['stage_id' => $stages['open1']])
        ->assertOk()->assertJsonPath('data.status', 'open')->assertJsonPath('data.lost_reason', null);
    expect(CrmActivity::where('subject_type', 'deal')->where('subject_id', $id)->where('type', CrmTimeline::DEAL_REOPENED)->exists())->toBeTrue();
});

test('the board totals each stage and weighs it by its probability, and keeps the dragged order', function () {
    $user = CrmFixtures::user(CrmFixtures::SALES);
    $customer = Customer::create(['tenant_id' => $user->tenant_id, 'name' => 'Pak Joko', 'status' => 'new']);

    $first = $this->actingAs($user)->postJson('/api/deals', ['title' => 'A', 'customer_id' => $customer->id, 'value_cents' => 100000])->json('data.id');
    $second = $this->actingAs($user)->postJson('/api/deals', ['title' => 'B', 'customer_id' => $customer->id, 'value_cents' => 300000])->json('data.id');
    $stages = salesStageIds($user->tenant_id);

    // B dragged to the top of its column.
    $this->actingAs($user)->postJson("/api/deals/{$second}/move", ['stage_id' => $stages['open0'], 'position' => 0])->assertOk();

    $column = collect($this->actingAs($user)->getJson('/api/deals/board')->json('columns'))->firstWhere('id', $stages['open0']);

    expect(collect($column['deals'])->pluck('id')->all())->toBe([$second, $first])
        ->and($column['count'])->toBe(2)
        ->and($column['total_cents'])->toBe(400000)
        ->and($column['weighted_cents'])->toBe(40000); // 10%
});

test('a pipeline keeps somewhere to win and somewhere to lose, and a stage with deals is never emptied silently', function () {
    $admin = CrmFixtures::user(CrmFixtures::SALES_ADMIN);
    $customer = Customer::create(['tenant_id' => $admin->tenant_id, 'name' => 'Lia', 'status' => 'new']);
    $this->actingAs($admin)->postJson('/api/deals', ['title' => 'X', 'customer_id' => $customer->id])->assertCreated();

    $pipeline = CrmPipeline::where('tenant_id', $admin->tenant_id)->first();
    $stages = salesStageIds($admin->tenant_id);

    $this->actingAs($admin)->deleteJson("/api/crm/pipelines/{$pipeline->id}/stages/{$stages['open0']}")->assertStatus(422);
    $this->actingAs($admin)->deleteJson("/api/crm/pipelines/{$pipeline->id}/stages/{$stages['open0']}?move_to={$stages['open1']}")->assertOk();
    expect(Deal::first()->stage_id)->toBe($stages['open1']);

    $this->actingAs($admin)->deleteJson("/api/crm/pipelines/{$pipeline->id}/stages/{$stages['won']}")->assertStatus(422);
    $this->actingAs($admin)->putJson("/api/crm/pipelines/{$pipeline->id}/stages/{$stages['open1']}", ['kind' => 'won'])->assertStatus(422);
    $this->actingAs($admin)->putJson("/api/crm/pipelines/{$pipeline->id}/stages/order", ['stage_ids' => [$stages['won']]])->assertStatus(422);
    $this->actingAs($admin)->deleteJson("/api/crm/pipelines/{$pipeline->id}")->assertStatus(422);

    // A second board; the first one's deals move along when it is deleted.
    $other = $this->actingAs($admin)->postJson('/api/crm/pipelines', ['name' => 'Grosir'])->assertCreated()->json('data');
    $this->actingAs($admin)->deleteJson("/api/crm/pipelines/{$pipeline->id}")->assertStatus(422);
    $this->actingAs($admin)->deleteJson("/api/crm/pipelines/{$pipeline->id}?move_to_pipeline_id={$other['id']}")->assertOk();

    expect(Deal::first()->pipeline_id)->toBe($other['id'])
        ->and(Deal::first()->stage_id)->toBe($other['stages'][0]['id'])
        ->and(CrmPipeline::find($other['id'])->is_default)->toBeTrue();

    // Managing pipelines is its own permission.
    $member = CrmFixtures::user(CrmFixtures::SALES, $admin->tenant, 'Member');
    $this->actingAs($member)->postJson('/api/crm/pipelines', ['name' => 'Y'])->assertForbidden();
    $this->actingAs($member)->getJson('/api/crm/pipelines')->assertOk();
});

test('a task written beside a conversation is about its customer, and its reminder goes out once', function () {
    $user = CrmFixtures::user(CrmFixtures::SALES);
    $colleague = CrmFixtures::user(CrmFixtures::SALES, $user->tenant, 'Budi');
    $whatsapp = CrmFixtures::connection($user);
    $contact = CrmFixtures::contact($whatsapp, '6281200000102', 'Sari');
    $conversation = CrmFixtures::conversation($contact, $whatsapp);

    $this->actingAs($user)->postJson('/api/crm/tasks', [
        'title' => 'Telepon balik', 'due_at' => now()->addHour()->toIso8601String(), 'remind_at' => now()->addHours(2)->toIso8601String(),
    ])->assertStatus(422);

    $task = $this->actingAs($user)->postJson('/api/crm/tasks', [
        'title' => 'Kirim katalog',
        'conversation_id' => $conversation->id,
        'assignee_user_id' => $colleague->id,
        'due_at' => now()->addMinutes(30)->toIso8601String(),
        'remind_at' => now()->subMinute()->toIso8601String(),
    ])->assertCreated()->json('data');

    expect($task['subject_type'])->toBe('customer')
        ->and($task['customer']['id'])->toBe($contact->customer_id)
        ->and($task['assignee']['id'])->toBe($colleague->id);

    Event::fake([CrmNotice::class, CrmChanged::class]);
    $this->artisan('crm:task-reminders')->assertSuccessful();
    $this->artisan('crm:task-reminders')->assertSuccessful();

    Event::assertDispatchedTimes(CrmNotice::class, 1);
    Event::assertDispatched(CrmNotice::class, fn (CrmNotice $notice) => $notice->kind === 'task_reminder' && $notice->userId === $colleague->id);

    // Their list, today; completing it lands on the customer's timeline.
    $mine = $this->actingAs($colleague)->getJson('/api/crm/tasks?view=today')->assertOk();
    expect($mine->json('data.0.id'))->toBe($task['id'])->and($mine->json('counts.today'))->toBe(1);

    $this->actingAs($colleague)->postJson("/api/crm/tasks/{$task['id']}/complete")->assertOk();
    expect(CrmTask::find($task['id'])->completed_at)->not->toBeNull()
        ->and(CrmActivity::where('customer_id', $contact->customer_id)->where('type', CrmTimeline::TASK_COMPLETED)->exists())->toBeTrue();

    $this->actingAs($colleague)->getJson('/api/crm/tasks?view=done')->assertJsonPath('data.0.id', $task['id']);
});

test('a note logged on a deal is on the customer timeline; only its author or the owner may change it', function () {
    $user = CrmFixtures::user(CrmFixtures::SALES);
    $colleague = CrmFixtures::user(CrmFixtures::SALES, $user->tenant, 'Budi');
    $customer = Customer::create(['tenant_id' => $user->tenant_id, 'name' => 'Dewi', 'status' => 'new']);
    $dealId = $this->actingAs($user)->postJson('/api/deals', ['title' => 'Paket', 'customer_id' => $customer->id])->json('data.id');

    $note = $this->actingAs($user)->postJson('/api/crm/activities', [
        'type' => 'call', 'body' => 'Minta diskon 10%', 'subject_type' => 'deal', 'subject_id' => $dealId, 'outcome' => 'Callback', 'duration_minutes' => 5,
    ])->assertCreated()->json('data');

    expect($note['customer_id'])->toBe($customer->id)->and($note['data']['outcome'])->toBe('Callback');

    $timeline = collect($this->actingAs($user)->getJson("/api/customers/{$customer->id}/timeline")->json('data'));
    expect($timeline->firstWhere('type', 'call')['body'])->toBe('Minta diskon 10%');

    $this->actingAs($colleague)->putJson("/api/crm/activities/{$note['activity_id']}", ['body' => 'X'])->assertForbidden();
    $this->actingAs($user)->putJson("/api/crm/activities/{$note['activity_id']}", ['body' => 'Minta diskon 5%'])->assertOk()->assertJsonPath('data.body', 'Minta diskon 5%');

    $owner = CrmFixtures::user(CrmFixtures::SALES, $user->tenant, 'Owner');
    $owner->assignRole(Role::findOrCreate('owner', 'web'));
    $this->actingAs($owner->fresh())->deleteJson("/api/crm/activities/{$note['activity_id']}")->assertOk();

    // What the CRM recorded itself is history.
    $system = CrmActivity::where('subject_type', 'deal')->where('subject_id', $dealId)->where('type', CrmTimeline::CREATED)->first();
    $this->actingAs($user)->putJson("/api/crm/activities/{$system->id}", ['body' => 'X'])->assertStatus(422);

    // The log shows what people logged; the CRM's own entries on request.
    $this->actingAs($user)->postJson('/api/crm/activities', ['type' => 'note', 'body' => 'Catatan', 'subject_type' => 'customer', 'subject_id' => $customer->id])->assertCreated();
    expect(collect($this->actingAs($user)->getJson('/api/crm/activities')->json('data'))->pluck('type')->all())->toBe(['note'])
        ->and(collect($this->actingAs($user)->getJson('/api/crm/activities?include_system=1')->json('data'))->pluck('type'))->toContain(CrmTimeline::CREATED);
});

test('without the crm plan feature, the whole CRM is closed', function () {
    config(['services.billing.enforce' => true]);
    $user = CrmFixtures::user(CrmFixtures::SALES);
    $features = ['chat' => true, 'crm' => false];

    $plan = Plan::create([
        'name' => 'Starter', 'slug' => 'crm-'.uniqid(), 'price_cents' => 9990, 'currency' => 'BRL',
        'billing_cycle' => BillingCycle::Monthly, 'is_active' => true, 'features' => $features, 'quotas' => [],
    ]);
    $subscription = Subscription::create([
        'tenant_id' => $user->tenant_id, 'plan_id' => $plan->id, 'status' => SubscriptionStatus::Active,
        'payment_method' => PaymentMethod::Checkout, 'billing_cycle' => BillingCycle::Monthly, 'price_cents' => 9990, 'quantity' => 1,
        'current_period_start' => now(), 'current_period_end' => now()->addMonth(), 'quotas_snapshot' => [], 'features_snapshot' => $features,
    ]);
    $user->tenant->forceFill(['current_subscription_id' => $subscription->id])->save();
    app(SubscriptionGate::class)->forget($user->tenant->fresh());

    $this->actingAs($user)->getJson('/api/leads')->assertForbidden()->assertJsonPath('feature', 'crm');
    $this->actingAs($user)->getJson('/api/deals/board')->assertForbidden();
    $this->actingAs($user)->getJson('/api/crm/tasks')->assertForbidden();
    // Customers and companies too: everything CRM is sold under the one key.
    $this->actingAs($user)->getJson('/api/customers')->assertForbidden()->assertJsonPath('feature', 'crm');
    $this->actingAs($user)->getJson('/api/companies')->assertForbidden();
});

test('merging customers carries their leads, deals and tasks; a customer with sales is not deleted', function () {
    $admin = CrmFixtures::user([...CrmFixtures::SALES_ADMIN, ...CrmFixtures::ALL]);
    $keep = Customer::create(['tenant_id' => $admin->tenant_id, 'name' => 'Keep', 'status' => 'new']);
    $gone = Customer::create(['tenant_id' => $admin->tenant_id, 'name' => 'Gone', 'status' => 'new']);

    $this->actingAs($admin)->postJson('/api/leads', ['title' => 'L', 'customer_id' => $gone->id])->assertCreated();
    $dealId = $this->actingAs($admin)->postJson('/api/deals', ['title' => 'D', 'customer_id' => $gone->id])->json('data.id');
    $this->actingAs($admin)->postJson('/api/crm/tasks', ['title' => 'T', 'subject_type' => 'customer', 'subject_id' => $gone->id])->assertCreated();

    $this->actingAs($admin)->deleteJson("/api/customers/{$gone->id}")->assertStatus(422);

    $this->actingAs($admin)->postJson("/api/customers/{$keep->id}/merge", ['source_id' => $gone->id])->assertOk();

    expect(Lead::where('customer_id', $keep->id)->count())->toBe(1)
        ->and(Deal::find($dealId)->customer_id)->toBe($keep->id)
        ->and(CrmTask::where('customer_id', $keep->id)->count())->toBe(1);

    $sales = $this->actingAs($admin)->getJson("/api/customers/{$keep->id}/sales")->assertOk();
    expect($sales->json('leads'))->toHaveCount(1)
        ->and($sales->json('deals'))->toHaveCount(1)
        ->and($sales->json('tasks'))->toHaveCount(1)
        ->and($sales->json('summary.open_deals'))->toBe(1);
});

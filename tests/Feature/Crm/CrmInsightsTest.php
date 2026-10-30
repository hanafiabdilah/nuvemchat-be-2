<?php

use App\Events\CrmChanged;
use App\Events\CrmNotice;
use App\Models\AuditLog;
use App\Models\CrmPipeline;
use App\Models\CrmTask;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\Tag;
use App\Services\Contact\ContactTags;
use App\Services\Crm\LeadScoring;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Tests\Support\CrmFixtures;

/*
 * CRM insights: segments evaluated live, saved views, lead scoring, bulk
 * actions, data in and out, and the reports — each limited to what the reader
 * may see and to the permission a single change would need.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    Event::fake([CrmNotice::class, CrmChanged::class]);
});

function insightsUser(array $extra = [], $tenant = null, string $name = 'Ana')
{
    return CrmFixtures::user([...CrmFixtures::ALL, ...CrmFixtures::SALES_ADMIN, ...CrmFixtures::INSIGHTS, ...$extra], $tenant, $name);
}

test('a segment is evaluated live: status, tags, open deals and last contact', function () {
    $user = insightsUser();
    $whatsapp = CrmFixtures::connection($user);
    $vip = Tag::create(['tenant_id' => $user->tenant_id, 'name' => 'VIP', 'color' => '#f00']);

    $maya = CrmFixtures::contact($whatsapp, '6281200000201', 'Maya');
    app(ContactTags::class)->sync($maya, [$vip->id]);
    CrmFixtures::conversation($maya, $whatsapp, ['last_message_at' => now()->subDays(2)]);
    Customer::find($maya->customer_id)->update(['status' => 'customer']);

    $budi = CrmFixtures::contact($whatsapp, '6281200000202', 'Budi');
    CrmFixtures::conversation($budi, $whatsapp, ['last_message_at' => now()->subDays(60)]);
    app(ContactTags::class)->sync($budi, [$vip->id]);

    $rules = ['match' => 'all', 'rules' => [
        ['field' => 'tags', 'operator' => 'has_any', 'value' => [$vip->id]],
        ['field' => 'last_interaction', 'operator' => 'within_days', 'value' => 30],
    ]];

    $this->actingAs($user)->postJson('/api/crm/segments/preview', $rules)->assertOk()
        ->assertJsonPath('count', 1)->assertJsonPath('sample.0.id', $maya->customer_id);

    $id = $this->actingAs($user)->postJson('/api/crm/segments', ['name' => 'VIP aktif', ...$rules])->assertCreated()->json('data.id');

    // Live: Budi writes again and joins without anybody touching the segment.
    \App\Models\Conversation::where('contact_id', $budi->id)->update(['last_message_at' => now()]);
    $this->actingAs($user)->getJson("/api/crm/segments/{$id}")->assertJsonPath('data.customers_count', 2);

    // The customer list and the campaign picker read the same segment.
    $listed = collect($this->actingAs($user)->getJson("/api/customers?segment_id={$id}")->assertOk()->json('data'))->pluck('id')->sort()->values()->all();
    expect($listed)->toBe(collect([$maya->customer_id, $budi->customer_id])->sort()->values()->all());
    $this->actingAs($user)->getJson("/api/contacts?segment_id={$id}")->assertOk()->assertJsonPath('meta.total', 2);

    // Any: an open deal OR status customer.
    Deal::query()->delete();
    $any = ['match' => 'any', 'rules' => [
        ['field' => 'status', 'operator' => 'in', 'value' => ['customer']],
        ['field' => 'open_deal', 'operator' => 'exists'],
    ]];
    $this->actingAs($user)->postJson('/api/crm/segments/preview', $any)->assertJsonPath('count', 1);
});

test('a segment never refers to another workspace, and its count is what the reader may see', function () {
    $user = insightsUser();
    $stranger = insightsUser();
    $foreignTag = Tag::create(['tenant_id' => $stranger->tenant_id, 'name' => 'X', 'color' => '#000']);

    $this->actingAs($user)->postJson('/api/crm/segments/preview', ['match' => 'all', 'rules' => [
        ['field' => 'tags', 'operator' => 'has_any', 'value' => [$foreignTag->id]],
    ]])->assertStatus(422);

    $this->actingAs($user)->postJson('/api/crm/segments/preview', ['match' => 'all', 'rules' => [
        ['field' => 'status', 'operator' => 'between', 'value' => ['new']],
    ]])->assertStatus(422);

    // Everyone in the workspace counts the same customers; only
    // `segments.manage` may save a segment.
    $whatsapp = CrmFixtures::connection($user);
    CrmFixtures::contact($whatsapp, '5511900000203', 'Kita');
    CrmFixtures::contact($whatsapp, '5511900000204', 'Mereka');

    $operator = CrmFixtures::user(['customers.view', 'leads.view'], $user->tenant, 'Op');

    $everyone = ['match' => 'all', 'rules' => [['field' => 'status', 'operator' => 'in', 'value' => ['new', 'prospect', 'customer', 'inactive']]]];
    $this->actingAs($user)->postJson('/api/crm/segments/preview', $everyone)->assertJsonPath('count', 2);
    $this->actingAs($operator)->postJson('/api/crm/segments/preview', $everyone)->assertJsonPath('count', 2);
    $this->actingAs($operator)->postJson('/api/crm/segments', ['name' => 'X', ...$everyone])->assertForbidden();
});

test('saved views are private unless shared, and sharing needs the settings permission', function () {
    $owner = insightsUser();
    $member = CrmFixtures::user(CrmFixtures::SALES, $owner->tenant, 'Member');

    $mine = $this->actingAs($member)->postJson('/api/crm/views', ['entity' => 'leads', 'name' => 'Hot', 'filters' => ['status' => ['qualified'], 'owner' => 'me', 'nested' => ['a' => ['b']]]])
        ->assertCreated()->json('data');
    expect($mine['filters'])->toBe(['status' => ['qualified'], 'owner' => 'me']);

    $this->actingAs($owner)->getJson('/api/crm/views?entity=leads')->assertOk()->assertJsonCount(0, 'data');

    $this->actingAs($member)->postJson('/api/crm/views', ['entity' => 'leads', 'name' => 'Team', 'filters' => [], 'shared' => true])->assertForbidden();
    $shared = $this->actingAs($owner)->postJson('/api/crm/views', ['entity' => 'leads', 'name' => 'Pipeline review', 'filters' => [], 'shared' => true])->assertCreated()->json('data.id');

    $this->actingAs($member)->getJson('/api/crm/views?entity=leads')->assertJsonCount(2, 'data');
    $this->actingAs($member)->deleteJson("/api/crm/views/{$shared}")->assertForbidden();
    $this->actingAs($member)->deleteJson("/api/crm/views/{$mine['id']}")->assertOk();
});

test('lead scoring computes the score from the rules and explains it; typing a score changes nothing', function () {
    $user = insightsUser();
    $whatsapp = CrmFixtures::connection($user);
    $contact = CrmFixtures::contact($whatsapp, '6281200000205', 'Sinta');
    CrmFixtures::conversation($contact, $whatsapp, ['last_message_at' => now()->subDay()]);

    $this->actingAs($user)->putJson('/api/crm/lead-scoring', ['enabled' => true, 'rules' => [
        ['criterion' => 'has_email', 'points' => 20],
        ['criterion' => 'source_is', 'value' => ['whatsapp_official'], 'points' => 30],
        ['criterion' => 'wrote_within_days', 'value' => 7, 'points' => 25],
        ['criterion' => 'value_at_least', 'value' => 100000000, 'points' => 40],
    ]])->assertOk();

    $id = $this->actingAs($user)->postJson('/api/leads', ['title' => 'Paket', 'customer_id' => $contact->customer_id, 'source' => 'whatsapp_official', 'score' => 99])
        ->assertCreated()->json('data.id');

    $lead = Lead::find($id);
    expect($lead->score)->toBe(55)
        ->and(collect($lead->score_details)->pluck('criterion')->all())->toBe(['source_is', 'wrote_within_days']);

    // The lead page reads the same explanation.
    $this->actingAs($user)->getJson("/api/leads/{$id}")
        ->assertOk()
        ->assertJsonPath('data.score', 55)
        ->assertJsonPath('data.score_details.0.criterion', 'source_is')
        ->assertJsonPath('data.score_details.1.points', 25);

    // The customer gains an e-mail: their leads are rescored.
    $this->actingAs($user)->putJson("/api/customers/{$contact->customer_id}", ['email' => 'sinta@example.com'])->assertOk();
    expect($lead->fresh()->score)->toBe(75);

    // A typed score is ignored while scoring is on.
    $this->actingAs($user)->putJson("/api/leads/{$id}", ['score' => 5, 'value_cents' => 200000000])->assertOk();
    expect($lead->fresh()->score)->toBe(100);

    // Time alone moves "wrote within 7 days"; the hourly sweep catches it.
    \App\Models\Conversation::where('contact_id', $contact->id)->update(['last_message_at' => now()->subDays(30)]);
    $this->artisan('crm:score-leads')->assertSuccessful();
    expect($lead->fresh()->score)->toBe(90); // 20 + 30 + 40

    // Rules from another workspace are refused.
    $stranger = insightsUser();
    $foreign = Tag::create(['tenant_id' => $stranger->tenant_id, 'name' => 'X', 'color' => '#000']);
    $this->actingAs($user)->putJson('/api/crm/lead-scoring', ['enabled' => true, 'rules' => [['criterion' => 'has_tag', 'value' => [$foreign->id], 'points' => 10]]])->assertStatus(422);
    expect(LeadScoring::config($user->tenant->fresh())['rules'])->toHaveCount(4);
});

test('bulk actions go through the same rules as one change, and assignments are announced once', function () {
    $user = insightsUser();
    $colleague = CrmFixtures::user(CrmFixtures::SALES, $user->tenant, 'Budi');
    $customers = collect(range(1, 3))->map(fn ($i) => Customer::create(['tenant_id' => $user->tenant_id, 'name' => "C{$i}", 'status' => 'new']));

    $this->actingAs($user)->postJson('/api/customers/bulk', ['action' => 'set_status', 'value' => 'prospect', 'ids' => $customers->pluck('id')->all()])
        ->assertOk()->assertJsonPath('updated', 3);
    expect(Customer::whereIn('id', $customers->pluck('id'))->pluck('status')->map->value->unique()->all())->toBe(['prospect']);

    // By filters: everyone matching the list's filters.
    $this->actingAs($user)->postJson('/api/customers/bulk', ['action' => 'set_owner', 'value' => $colleague->id, 'filters' => ['status' => ['prospect']]])
        ->assertOk()->assertJsonPath('updated', 3);

    $leads = $customers->map(fn ($customer) => Lead::create(['tenant_id' => $user->tenant_id, 'customer_id' => $customer->id, 'title' => 'L'.$customer->id, 'status' => 'new']));
    $leads[0]->update(['status' => 'converted']);

    Event::fake([CrmNotice::class, CrmChanged::class]);
    $result = $this->actingAs($user)->postJson('/api/leads/bulk', ['action' => 'set_owner', 'value' => $colleague->id, 'ids' => $leads->pluck('id')->all()])->assertOk();
    expect($result->json('updated'))->toBe(3);
    Event::assertDispatchedTimes(CrmNotice::class, 1);
    Event::assertDispatched(CrmNotice::class, fn (CrmNotice $n) => $n->userId === $colleague->id && $n->payload['count'] === 3);

    $this->actingAs($user)->postJson('/api/leads/bulk', ['action' => 'set_status', 'value' => 'qualified', 'ids' => $leads->pluck('id')->all()])
        ->assertOk()->assertJsonPath('updated', 2)->assertJsonPath('skipped', 1);

    // Deals to Won; customers become customers.
    $deals = $customers->map(fn ($customer) => $this->actingAs($user)->postJson('/api/deals', ['title' => 'D', 'customer_id' => $customer->id])->json('data.id'));
    $won = CrmPipeline::where('tenant_id', $user->tenant_id)->first()->stages()->where('kind', 'won')->value('id');
    $this->actingAs($user)->postJson('/api/deals/bulk', ['action' => 'move_stage', 'value' => $won, 'ids' => $deals->all()])->assertJsonPath('updated', 3);
    expect(Deal::whereIn('id', $deals)->pluck('status')->unique()->all())->toBe(['won'])
        ->and(Customer::whereIn('id', $customers->pluck('id'))->pluck('status')->map->value->unique()->all())->toBe(['customer']);

    // Tasks.
    $task = CrmTask::create(['tenant_id' => $user->tenant_id, 'title' => 'T', 'assignee_user_id' => $user->id, 'created_by' => $user->id]);
    $this->actingAs($user)->postJson('/api/crm/tasks/bulk', ['action' => 'complete', 'ids' => [$task->id]])->assertJsonPath('updated', 1);
    expect($task->fresh()->completed_at)->not->toBeNull();

    // Deleting needs the delete permission, like one at a time.
    $member = CrmFixtures::user(CrmFixtures::SALES, $user->tenant, 'Member');
    $this->actingAs($member)->postJson('/api/leads/bulk', ['action' => 'delete', 'ids' => $leads->pluck('id')->all()])->assertForbidden();
});

test('exports are CSV with formulas defused, need the export permission, and are audited', function () {
    $user = insightsUser();
    Customer::create(['tenant_id' => $user->tenant_id, 'name' => '=HYPERLINK("http://x")', 'status' => 'new', 'email' => 'a@example.com']);

    $response = $this->actingAs($user)->get('/api/crm/export/customers')->assertOk();
    $csv = $response->streamedContent();

    expect($csv)->toStartWith("\xEF\xBB\xBF")
        ->and($csv)->toContain('id,name,phone,email')
        ->and($csv)->toContain("\"\t=HYPERLINK(")
        ->and(AuditLog::where('action', 'crm.export')->count())->toBe(1);

    $lead = Lead::create(['tenant_id' => $user->tenant_id, 'customer_id' => Customer::first()->id, 'title' => 'Sepatu', 'status' => 'new', 'value_cents' => 15000000]);
    expect($this->actingAs($user)->get('/api/crm/export/leads')->streamedContent())->toContain('Sepatu')->toContain('150000');

    $member = CrmFixtures::user(CrmFixtures::SALES, $user->tenant, 'Member');
    $this->actingAs($member)->get('/api/crm/export/customers')->assertForbidden();
    $this->actingAs($user)->get('/api/crm/export/secrets')->assertNotFound();
});

test('customers come in from a spreadsheet: matched by phone, filled not overwritten, companies created', function () {
    $user = insightsUser();
    $existing = Customer::create(['tenant_id' => $user->tenant_id, 'name' => 'Rina Asli', 'phone' => '5511900000301', 'status' => 'new']);

    $csv = "Nome,Celular,E-mail,Empresa,Status,Responsável\n"
        ."Rina Typo,(11) 90000-0301,rina@example.com,PT Maju,cliente,nobody@example.com\n"
        ."Joko,11900000302,joko@example.com,PT Maju,prospecto,{$user->email}\n"
        .",11900000303,,,,\n";

    $preview = $this->actingAs($user)->post('/api/customers/import/preview', [
        'file' => UploadedFile::fake()->createWithContent('customers.csv', $csv),
    ])->assertOk()->json();

    expect($preview['mapping'])->toMatchArray(['name' => 0, 'phone' => 1, 'email' => 2, 'company' => 3, 'status' => 4, 'owner' => 5])
        ->and($preview['total_rows'])->toBe(3);

    $report = $this->actingAs($user)->postJson('/api/customers/import', [
        'token' => $preview['token'], 'mapping' => $preview['mapping'], 'on_match' => 'fill',
    ])->assertOk()->json();

    expect($report['created'])->toBe(1)->and($report['updated'])->toBe(1)->and($report['skipped'])->toBe(1);

    $existing->refresh();
    expect($existing->name)->toBe('Rina Asli')
        ->and($existing->email)->toBe('rina@example.com')
        ->and($existing->company?->name)->toBe('PT Maju')
        ->and($existing->owner_user_id)->toBeNull();

    $joko = Customer::where('name', 'Joko')->first();
    expect($joko->source)->toBe('import')
        ->and($joko->status->value)->toBe('prospect')
        ->and($joko->owner_user_id)->toBe($user->id)
        ->and($joko->company_id)->toBe($existing->company_id)
        ->and(collect($report['rows'])->pluck('message')->implode(' '))->toContain('nobody@example.com');

    $member = CrmFixtures::user(CrmFixtures::EDITOR, $user->tenant, 'Member');
    $this->actingAs($member)->post('/api/customers/import/preview', ['file' => UploadedFile::fake()->createWithContent('c.csv', $csv)])->assertForbidden();
});

test('the overview and reports count what the reader may see', function () {
    $user = insightsUser();
    $colleague = CrmFixtures::user(CrmFixtures::SALES, $user->tenant, 'Budi');
    $customer = Customer::create(['tenant_id' => $user->tenant_id, 'name' => 'Toko', 'status' => 'new']);

    $this->actingAs($user)->postJson('/api/leads', ['title' => 'L1', 'customer_id' => $customer->id, 'source' => 'instagram'])->assertCreated();
    $first = $this->actingAs($user)->postJson('/api/deals', ['title' => 'A', 'customer_id' => $customer->id, 'value_cents' => 1000000])->json('data.id');
    $second = $this->actingAs($user)->postJson('/api/deals', ['title' => 'B', 'customer_id' => $customer->id, 'value_cents' => 3000000, 'owner_user_id' => $colleague->id, 'expected_close_date' => now('Asia/Jakarta')->toDateString()])->json('data.id');
    $stages = CrmPipeline::where('tenant_id', $user->tenant_id)->first()->stages;

    $this->actingAs($user)->postJson("/api/deals/{$first}/move", ['stage_id' => $stages->firstWhere('kind.value', 'won')->id])->assertOk();
    $this->actingAs($user)->postJson('/api/crm/activities', ['type' => 'call', 'body' => 'Halo', 'subject_type' => 'customer', 'subject_id' => $customer->id])->assertCreated();

    $overview = $this->actingAs($user)->getJson('/api/crm/overview?period=30d')->assertOk()->json();
    expect($overview['kpis']['won_count'])->toBe(1)
        ->and($overview['kpis']['won_value_cents']['value'])->toBe(1000000)
        ->and($overview['kpis']['open_count'])->toBe(1)
        ->and($overview['kpis']['weighted_value_cents'])->toBe(300000) // 10% of 3,000,000
        ->and($overview['kpis']['win_rate'])->toEqual(100)
        ->and($overview['kpis']['forecast_month']['value_cents'])->toBe(3000000)
        ->and($overview['leads_by_source'][0]['source'])->toBe('instagram');

    $sales = $this->actingAs($user)->getJson('/api/crm/reports/sales')->assertOk()->json();
    expect(collect($sales['rows'])->firstWhere('owner.id', $user->id)['won_value_cents'])->toBe(1000000)
        ->and(collect($sales['rows'])->firstWhere('owner.id', $colleague->id)['open_value_cents'])->toBe(3000000);

    $pipeline = $this->actingAs($user)->getJson('/api/crm/reports/pipeline')->assertOk()->json();
    expect(collect($pipeline['stages'])->firstWhere('kind', 'won')['entered'])->toBe(1)
        ->and(collect($pipeline['stages'])->firstWhere('id', $stages->first()->id)['entered'])->toBe(2);

    $this->actingAs($user)->getJson('/api/crm/reports/sources')->assertJsonPath('rows.0.source', 'instagram');
    $this->actingAs($user)->getJson('/api/crm/reports/forecast')->assertJsonPath('months.0.value_cents', 3000000);
    $this->actingAs($user)->getJson('/api/crm/reports/activities')->assertJsonPath('rows.0.call', 1);

    // Reports are their own permission; the overview is for everyone in sales.
    $this->actingAs($colleague)->getJson('/api/crm/reports/sales')->assertForbidden();
    $this->actingAs($colleague)->getJson('/api/crm/overview')->assertOk();
});

<?php

use App\Enums\Connection\Channel;
use App\Enums\Conversation\Status as ConversationStatus;
use App\Models\Company;
use App\Models\CrmActivity;
use App\Models\Customer;
use App\Services\Crm\CrmTimeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CrmFixtures;

/*
 * The CRM pages over the API: the list, Customer 360, the timeline, the inbox
 * panel's lookup, companies and custom fields — and who may see and change
 * what.
 */

uses(RefreshDatabase::class);

test('Customer 360 shows every identity, its conversations and the counts', function () {
    $user = CrmFixtures::user();
    $whatsapp = CrmFixtures::connection($user);
    $instagram = CrmFixtures::connection($user, Channel::Instagram, 'IG');

    $wa = CrmFixtures::contact($whatsapp, '6281200000001', 'Maya');
    $ig = CrmFixtures::contact($instagram, 'IGSID-10', 'maya.id');
    $this->actingAs($user)->postJson("/api/customers/{$wa->customer_id}/contacts", ['contact_id' => $ig->id])->assertOk();

    $open = CrmFixtures::conversation($wa, $whatsapp);
    CrmFixtures::conversation($ig, $instagram, ['status' => ConversationStatus::Resolved, 'resolved_at' => now()->subDay(), 'last_message_at' => now()->subDay()]);

    $response = $this->actingAs($user)->getJson("/api/customers/{$wa->customer_id}")->assertOk();

    expect($response->json('data.name'))->toBe('Maya')
        ->and($response->json('data.channels'))->toEqualCanonicalizing(['whatsapp_official', 'instagram'])
        ->and($response->json('identities'))->toHaveCount(2)
        ->and(collect($response->json('identities'))->firstWhere('id', $wa->id)['latest_conversation_id'])->toBe($open->id)
        ->and($response->json('counts.conversations'))->toBe(2)
        ->and($response->json('counts.open_conversations'))->toBe(1);

    $this->actingAs($user)->getJson("/api/customers/{$wa->customer_id}/conversations")
        ->assertOk()
        ->assertJsonPath('data.0.id', $open->id)
        ->assertJsonCount(2, 'data');
});

test('the list searches by name, phone and any channel identifier, and filters', function () {
    $user = CrmFixtures::user();
    $whatsapp = CrmFixtures::connection($user);
    $telegram = CrmFixtures::connection($user, Channel::Telegram, 'TG');

    CrmFixtures::contact($whatsapp, '6281300000001', 'Rudi Hartono');
    CrmFixtures::contact($telegram, '555000', 'Siska');
    Customer::create(['tenant_id' => $user->tenant_id, 'name' => 'Calon Pembeli', 'status' => 'prospect']);

    $names = fn (string $query) => collect($this->actingAs($user)->getJson('/api/customers?'.$query)->assertOk()->json('data'))->pluck('name')->sort()->values()->all();

    expect($names('search=rudi'))->toBe(['Rudi Hartono'])
        ->and($names('search=13000000'))->toBe(['Rudi Hartono'])
        ->and($names('search=555000'))->toBe(['Siska'])
        ->and($names('channel[]=telegram'))->toBe(['Siska'])
        ->and($names('status[]=prospect'))->toBe(['Calon Pembeli']);
});

test('editing a customer is recorded on the timeline, with names for the ids', function () {
    $user = CrmFixtures::user();
    $customer = Customer::create(['tenant_id' => $user->tenant_id, 'name' => 'Agus', 'status' => 'new']);
    $company = Company::create(['tenant_id' => $user->tenant_id, 'name' => 'PT Maju']);

    $this->actingAs($user)->putJson("/api/customers/{$customer->id}", [
        'status' => 'prospect',
        'company_id' => $company->id,
        'owner_user_id' => $user->id,
        'phone' => '+62 812-0000-0009',
    ])->assertOk()
        ->assertJsonPath('data.company.name', 'PT Maju')
        ->assertJsonPath('data.phone', '6281200000009');

    $entry = CrmActivity::where('subject_id', $customer->id)->where('type', CrmTimeline::UPDATED)->first();

    expect($entry->data['changes']['status'])->toBe(['from' => 'new', 'to' => 'prospect'])
        ->and($entry->data['changes']['company_id']['to_name'])->toBe('PT Maju')
        ->and($entry->data['changes']['owner_user_id']['to_name'])->toBe('Ana')
        ->and($entry->user_id)->toBe($user->id);
});

test('the timeline mixes CRM entries with the conversations the viewer can open', function () {
    $user = CrmFixtures::user();
    $whatsapp = CrmFixtures::connection($user);
    $contact = CrmFixtures::contact($whatsapp, '6281400000001', 'Wati');
    $thread = CrmFixtures::conversation($contact, $whatsapp, ['created_at' => now()->subHours(2)]);

    $types = collect($this->actingAs($user)->getJson("/api/customers/{$contact->customer_id}/timeline")->assertOk()->json('data'))->pluck('type');

    expect($types)->toContain('created')
        ->and($types)->toContain('conversation_started');
});

test('the inbox panel finds the customer of a contact whose conversation the operator can open', function () {
    $owner = CrmFixtures::user();
    $whatsapp = CrmFixtures::connection($owner);
    $contact = CrmFixtures::contact($whatsapp, '6281600000001', 'Yuni');
    CrmFixtures::conversation($contact, $whatsapp);

    $operator = CrmFixtures::user(['customers.view'], $owner->tenant, 'Citra');
    $operator->connections()->sync([$whatsapp->id]);

    $this->actingAs($operator)->getJson("/api/contacts/{$contact->id}/customer")
        ->assertOk()
        ->assertJsonPath('data.id', $contact->customer_id)
        ->assertJsonPath('identities.0.id', $contact->id);

    // No access to the connection: the person is the workspace's, like the
    // contact book, but the thread is not theirs to open.
    $outsider = CrmFixtures::user(['customers.view'], $owner->tenant, 'Dodi');

    $this->actingAs($outsider)->getJson("/api/contacts/{$contact->id}/customer")
        ->assertOk()
        ->assertJsonPath('identities.0.latest_conversation_id', null);
});

test('viewing, editing, merging and deleting each need their own permission', function () {
    $owner = CrmFixtures::user();
    $a = Customer::create(['tenant_id' => $owner->tenant_id, 'name' => 'A', 'status' => 'new']);
    $b = Customer::create(['tenant_id' => $owner->tenant_id, 'name' => 'B', 'status' => 'new']);

    $viewer = CrmFixtures::user(['customers.view'], $owner->tenant, 'Viewer');
    $this->actingAs($viewer)->getJson("/api/customers/{$a->id}")->assertOk();
    $this->actingAs($viewer)->putJson("/api/customers/{$a->id}", ['name' => 'X'])->assertForbidden();
    $this->actingAs($viewer)->postJson('/api/customers', ['name' => 'X'])->assertForbidden();

    $editor = CrmFixtures::user(CrmFixtures::EDITOR, $owner->tenant, 'Editor');
    $this->actingAs($editor)->putJson("/api/customers/{$a->id}", ['name' => 'A2'])->assertOk();
    $this->actingAs($editor)->postJson("/api/customers/{$a->id}/merge", ['source_id' => $b->id])->assertForbidden();
    $this->actingAs($editor)->deleteJson("/api/customers/{$b->id}")->assertForbidden();

    // Another workspace's customer is not found at all.
    $stranger = CrmFixtures::user();
    $this->actingAs($stranger)->getJson("/api/customers/{$a->id}")->assertNotFound();
});

test('a new customer typed in by hand is matched when they first write in', function () {
    $user = CrmFixtures::user();
    $whatsapp = CrmFixtures::connection($user);

    $id = $this->actingAs($user)->postJson('/api/customers', ['name' => 'Seu Hadi', 'phone' => '(11) 99999-0000'])
        ->assertCreated()
        ->json('data.id');

    // A national number is read in the workspace's country (the market's
    // calling code — Brazil here).
    expect(Customer::find($id)->phone)->toBe('5511999990000');

    $contact = CrmFixtures::contact($whatsapp, '5511999990000', 'hadi');

    expect($contact->customer_id)->toBe($id);

    // WhatsApp may report the number without the ninth digit: still them.
    $older = CrmFixtures::contact(CrmFixtures::connection($user), '551199990000', 'hadi');

    expect($older->customer_id)->toBe($id);
});

test('companies keep their customers when deleted, and count only what the viewer may see', function () {
    $user = CrmFixtures::user();

    $companyId = $this->actingAs($user)->postJson('/api/companies', ['name' => 'CV Sejahtera', 'website' => 'https://sejahtera.id'])
        ->assertCreated()
        ->json('data.id');

    $customer = Customer::create(['tenant_id' => $user->tenant_id, 'name' => 'Karyawan', 'status' => 'new', 'company_id' => $companyId]);

    $this->actingAs($user)->getJson('/api/companies')->assertOk()->assertJsonPath('data.0.customers_count', 1);
    $this->actingAs($user)->getJson("/api/companies/{$companyId}")->assertOk()->assertJsonPath('customers.0.name', 'Karyawan');

    $this->actingAs($user)->putJson("/api/companies/{$companyId}", ['industry' => 'Retail'])->assertOk();
    expect(CrmActivity::where('subject_type', 'company')->where('type', CrmTimeline::UPDATED)->exists())->toBeTrue();

    $this->actingAs($user)->deleteJson("/api/companies/{$companyId}")->assertOk();

    expect($customer->fresh()->company_id)->toBeNull();
});

test('custom fields are defined once and checked on every save', function () {
    $user = CrmFixtures::user();
    $customer = Customer::create(['tenant_id' => $user->tenant_id, 'name' => 'Lina', 'status' => 'new']);

    $this->actingAs($user)->postJson('/api/crm/custom-fields', ['entity' => 'customer', 'label' => 'Ukuran baju', 'type' => 'select', 'options' => ['S', 'M', 'L']])
        ->assertCreated()
        ->assertJsonPath('data.key', 'ukuran_baju');
    $this->actingAs($user)->postJson('/api/crm/custom-fields', ['entity' => 'customer', 'label' => 'Ulang tahun', 'type' => 'date'])->assertCreated();

    $this->actingAs($user)->putJson("/api/customers/{$customer->id}", ['custom_fields' => ['ukuran_baju' => 'M', 'ulang_tahun' => '1990-05-01']])
        ->assertOk()
        ->assertJsonPath('data.custom_fields.ukuran_baju', 'M');

    $this->actingAs($user)->putJson("/api/customers/{$customer->id}", ['custom_fields' => ['ukuran_baju' => 'XXL']])
        ->assertStatus(422)->assertJsonValidationErrors('custom_fields.ukuran_baju');

    $this->actingAs($user)->putJson("/api/customers/{$customer->id}", ['custom_fields' => ['nomor_sepatu' => '42']])
        ->assertStatus(422)->assertJsonValidationErrors('custom_fields.nomor_sepatu');

    // Clearing one keeps the other.
    $this->actingAs($user)->putJson("/api/customers/{$customer->id}", ['custom_fields' => ['ukuran_baju' => null]])->assertOk();
    expect($customer->fresh()->custom_fields)->toBe(['ulang_tahun' => '1990-05-01']);

    // Defining fields is its own permission.
    $editor = CrmFixtures::user(CrmFixtures::EDITOR, $user->tenant, 'Editor');
    $this->actingAs($editor)->postJson('/api/crm/custom-fields', ['entity' => 'customer', 'label' => 'X', 'type' => 'text'])->assertForbidden();
});

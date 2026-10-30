<?php

use App\Enums\Connection\Channel;
use App\Models\Contact;
use App\Models\CrmActivity;
use App\Models\Customer;
use App\Models\Tag;
use App\Services\Conversation\GroupConversationService;
use App\Services\Crm\CrmTimeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CrmFixtures;

/*
 * One customer above the channel contacts: every new contact is recognised
 * (same phone, same e-mail) or becomes a new customer, and what is true of the
 * person — name, tags — is the same on every identity, from whichever screen
 * it was changed.
 */

uses(RefreshDatabase::class);

test('a contact written in from a channel becomes a customer, with its phone and source', function () {
    $user = CrmFixtures::user();
    $whatsapp = CrmFixtures::connection($user);

    $contact = CrmFixtures::contact($whatsapp, '6281234567890', 'Budi Santoso');

    $customer = $contact->customer;
    expect($customer)->not->toBeNull()
        ->and($customer->name)->toBe('Budi Santoso')
        ->and($customer->phone)->toBe('6281234567890')
        ->and($customer->source)->toBe('whatsapp_official')
        ->and($customer->status->value)->toBe('new')
        ->and(CrmActivity::where('subject_id', $customer->id)->where('type', CrmTimeline::CREATED)->exists())->toBeTrue();
});

test('a person already known by phone is recognised, not created twice', function () {
    $user = CrmFixtures::user();
    $whatsapp = CrmFixtures::connection($user);

    // Typed in by hand before they ever wrote.
    $known = Customer::create(['tenant_id' => $user->tenant_id, 'name' => 'Bu Sari', 'phone' => '6281111111111', 'status' => 'prospect', 'source' => 'manual']);

    $contact = CrmFixtures::contact($whatsapp, '6281111111111', '~sari');

    expect($contact->customer_id)->toBe($known->id)
        ->and(Customer::where('tenant_id', $user->tenant_id)->count())->toBe(1)
        ->and(CrmActivity::where('subject_id', $known->id)->where('type', CrmTimeline::CONTACT_LINKED)->first()->data['automatic'])->toBeTrue();
});

test('a name alone never links two people', function () {
    $user = CrmFixtures::user();
    $telegram = CrmFixtures::connection($user, Channel::Telegram);

    $first = CrmFixtures::contact($telegram, '1001', 'Budi');
    $second = CrmFixtures::contact($telegram, '1002', 'Budi');

    expect($first->customer_id)->not->toBe($second->customer_id);
});

test('a group chat never becomes a customer', function () {
    $user = CrmFixtures::user();
    $telegram = CrmFixtures::connection($user, Channel::Telegram);

    $group = GroupConversationService::resolveGroupContact($telegram, '-100200', 'Grup Reseller');

    expect($group->fresh()->customer_id)->toBeNull()
        ->and(Customer::count())->toBe(0);
});

test('the hourly sweep links contacts that existed before the CRM', function () {
    $user = CrmFixtures::user();
    $whatsapp = CrmFixtures::connection($user);

    $old = Contact::withoutEvents(fn () => Contact::create([
        'tenant_id' => $user->tenant_id, 'external_id' => '6282222222222', 'name' => 'Pak Joko', 'channel' => Channel::WhatsappOfficial,
    ]));

    expect($old->customer_id)->toBeNull();

    $this->artisan('crm:link-contacts')->assertSuccessful();

    expect($old->fresh()->customer->name)->toBe('Pak Joko');

    // Idempotent: nothing left, nothing created twice.
    $this->artisan('crm:link-contacts')->assertSuccessful();
    expect(Customer::count())->toBe(1);
});

test('renaming a contact from the inbox renames the person on every channel', function () {
    $user = CrmFixtures::user();
    $whatsapp = CrmFixtures::connection($user);
    $instagram = CrmFixtures::connection($user, Channel::Instagram, 'IG');

    $wa = CrmFixtures::contact($whatsapp, '6283333333333', 'Rina');
    $ig = CrmFixtures::contact($instagram, 'IGSID-1', 'rina.shop');
    $this->actingAs($user)->postJson("/api/customers/{$wa->customer_id}/contacts", ['contact_id' => $ig->id])->assertOk();

    $this->actingAs($user)->putJson("/api/contacts/{$wa->id}", ['name' => 'Rina Kartika'])->assertOk();

    expect($wa->fresh()->customer->name)->toBe('Rina Kartika')
        ->and($ig->fresh()->name)->toBe('Rina Kartika')
        ->and($ig->fresh()->name_locked)->toBeTrue();
});

test('a tag put on one identity is on all of the person\'s identities', function () {
    $user = CrmFixtures::user();
    $whatsapp = CrmFixtures::connection($user);
    $instagram = CrmFixtures::connection($user, Channel::Instagram, 'IG');
    $vip = Tag::create(['tenant_id' => $user->tenant_id, 'name' => 'VIP', 'color' => '#f00']);

    $wa = CrmFixtures::contact($whatsapp, '6284444444444', 'Dewi');
    $ig = CrmFixtures::contact($instagram, 'IGSID-2', 'dewi');
    $this->actingAs($user)->postJson("/api/customers/{$wa->customer_id}/contacts", ['contact_id' => $ig->id])->assertOk();

    // From the panel beside the WhatsApp thread.
    $this->actingAs($user)->postJson("/api/contacts/{$wa->id}/tags", ['tags' => [$vip->id]])->assertOk();

    expect($ig->fresh()->tags->pluck('name')->all())->toBe(['VIP']);

    // And from the customer page, clearing them.
    $this->actingAs($user)->postJson("/api/customers/{$wa->customer_id}/tags", ['tags' => []])->assertOk();

    expect($wa->fresh()->tags)->toHaveCount(0)
        ->and($ig->fresh()->tags)->toHaveCount(0)
        ->and(CrmActivity::where('type', CrmTimeline::TAGS_CHANGED)->exists())->toBeTrue();
});

test('linking an identity folds an empty customer in; unlinking separates it again', function () {
    $user = CrmFixtures::user();
    $whatsapp = CrmFixtures::connection($user);
    $instagram = CrmFixtures::connection($user, Channel::Instagram, 'IG');
    $vip = Tag::create(['tenant_id' => $user->tenant_id, 'name' => 'VIP', 'color' => '#f00']);

    $wa = CrmFixtures::contact($whatsapp, '6285555555555', 'Tono');
    $ig = CrmFixtures::contact($instagram, 'IGSID-3', 'tono');
    $ig->tags()->attach($vip);
    $igCustomer = $ig->customer_id;

    $this->actingAs($user)->postJson("/api/customers/{$wa->customer_id}/contacts", ['contact_id' => $ig->id])->assertOk();

    expect($ig->fresh()->customer_id)->toBe($wa->customer_id)
        ->and(Customer::find($igCustomer))->toBeNull()
        // The union of their tags is on both now.
        ->and($wa->fresh()->tags->pluck('name')->all())->toBe(['VIP']);

    $response = $this->actingAs($user)->deleteJson("/api/customers/{$wa->customer_id}/contacts/{$ig->id}")->assertOk();

    expect($ig->fresh()->customer_id)->toBe($response->json('customer_id'))
        ->and($ig->fresh()->customer_id)->not->toBe($wa->customer_id);

    // The last identity cannot be separated: that would be a delete.
    $this->actingAs($user)->deleteJson("/api/customers/{$wa->customer_id}/contacts/{$wa->id}")
        ->assertStatus(422);
});

test('merging moves identities, history and relationships, and fills the gaps', function () {
    $user = CrmFixtures::user();
    $whatsapp = CrmFixtures::connection($user);
    $email = CrmFixtures::connection($user, Channel::Email, 'Mail');

    $wa = CrmFixtures::contact($whatsapp, '6286666666666', 'Andi');
    $mail = CrmFixtures::contact($email, 'andi@example.com', 'Andi W');
    $friend = Customer::create(['tenant_id' => $user->tenant_id, 'name' => 'Teman', 'status' => 'new']);

    $target = $wa->customer;
    $source = $mail->customer;

    $this->actingAs($user)->postJson("/api/customers/{$source->id}/relationships", ['related_customer_id' => $friend->id, 'label' => 'Rekan'])->assertCreated();

    $this->actingAs($user)->postJson("/api/customers/{$target->id}/merge", ['source_id' => $source->id])->assertOk();

    $target->refresh();
    expect(Customer::find($source->id))->toBeNull()
        ->and($mail->fresh()->customer_id)->toBe($target->id)
        ->and($target->email)->toBe('andi@example.com')
        ->and($target->relationships()->first()->related_customer_id)->toBe($friend->id)
        ->and($friend->relationships()->first()->related_customer_id)->toBe($target->id)
        ->and(CrmActivity::where('subject_id', $target->id)->where('subject_type', 'customer')->where('type', CrmTimeline::MERGED)->exists())->toBeTrue()
        // The source's own history moved with it.
        ->and(CrmActivity::where('subject_type', 'customer')->where('subject_id', $source->id)->exists())->toBeFalse();
});

test('a customer with channel contacts cannot be deleted; one without can', function () {
    $user = CrmFixtures::user();
    $whatsapp = CrmFixtures::connection($user);
    $wa = CrmFixtures::contact($whatsapp, '6287777777777', 'Lia');
    $manual = Customer::create(['tenant_id' => $user->tenant_id, 'name' => 'Calon', 'status' => 'new']);

    $this->actingAs($user)->deleteJson("/api/customers/{$wa->customer_id}")->assertStatus(422);
    $this->actingAs($user)->deleteJson("/api/customers/{$manual->id}")->assertOk();

    expect(Customer::find($manual->id))->toBeNull();
});

test('possible duplicates are offered by phone, e-mail or the exact same name', function () {
    $user = CrmFixtures::user();
    $a = Customer::create(['tenant_id' => $user->tenant_id, 'name' => 'Siti Aminah', 'phone' => '6288888888888', 'status' => 'new']);
    $byName = Customer::create(['tenant_id' => $user->tenant_id, 'name' => 'siti aminah', 'status' => 'new']);
    $byPhone = Customer::create(['tenant_id' => $user->tenant_id, 'name' => 'S.', 'phone' => '6288888888888', 'status' => 'new']);
    Customer::create(['tenant_id' => $user->tenant_id, 'name' => 'Orang lain', 'status' => 'new']);

    $response = $this->actingAs($user)->getJson("/api/customers/{$a->id}/duplicates")->assertOk();

    expect(collect($response->json('data'))->pluck('id')->sort()->values()->all())->toBe(collect([$byName->id, $byPhone->id])->sort()->values()->all());
});

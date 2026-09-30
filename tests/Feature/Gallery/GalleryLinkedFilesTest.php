<?php

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status as ConnectionStatus;
use App\Enums\Conversation\Status as ConversationStatus;
use App\Enums\Conversation\Type as ConversationType;
use App\Enums\Message\MessageType;
use App\Enums\Message\SenderType;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Flow;
use App\Models\FlowNode;
use App\Models\GalleryAsset;
use App\Models\Message;
use App\Models\Tenant;
use App\Services\Gallery\GalleryStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\GalleryFixtures;

/**
 * Files the gallery lists without storing: uploads made for flows, campaigns
 * and products, and attachments agents send.
 *
 * The rule every test here protects: listing a file never costs quota and never
 * makes the original upload or send fail — including in a workspace with no
 * gallery space at all, which is every workspace on a legacy plan.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    Storage::fake('public');
});

function linkedConversation(Tenant $tenant): Conversation
{
    $connection = Connection::create([
        'tenant_id' => $tenant->id,
        'channel' => Channel::WhatsappApiway,
        'name' => 'WhatsApp',
        'status' => ConnectionStatus::Active,
    ]);

    $contact = Contact::create([
        'tenant_id' => $tenant->id,
        'external_id' => '5511999999999',
        'name' => 'Ana',
        'channel' => $connection->channel,
    ]);

    return Conversation::create([
        'contact_id' => $contact->id,
        'connection_id' => $connection->id,
        'external_id' => $contact->external_id,
        'type' => ConversationType::Private,
        'status' => ConversationStatus::Active,
    ]);
}

function linkedAttachment(Conversation $conversation, array $overrides = []): Message
{
    $path = 'media/'.Str::random(6).'/foto.jpg';
    Storage::disk('local')->put($path, $overrides['bytes'] ?? 'jpeg-bytes-'.Str::random(8));
    unset($overrides['bytes']);

    return $conversation->messages()->create(array_merge([
        'external_id' => (string) Str::uuid(),
        'sender_type' => SenderType::Outgoing,
        'message_type' => MessageType::Image,
        'attachment' => $path,
        'sent_at' => now(),
    ], $overrides));
}

it('lists a flow upload in a workspace with no gallery space, without counting it', function () {
    $tenant = GalleryFixtures::tenant(planGb: 0);
    Sanctum::actingAs($tenant->user);

    $this->post('/api/uploads', [
        'file' => UploadedFile::fake()->image('banner.png')->size(40),
        'purpose' => 'flow',
    ])->assertOk();

    $asset = GalleryAsset::first();

    expect($asset->origin->value)->toBe('flow')
        ->and($asset->path)->toStartWith("uploads/{$tenant->id}/")
        ->and($asset->publicUrl())->toContain('banner.png');

    $summary = app(GalleryStorage::class)->summary($tenant);

    expect($summary['used_bytes'])->toBe(0)
        ->and($summary['files'])->toBe(0)
        ->and($summary['linked_files'])->toBe(1);

    // Linked bytes are never copied onto the gallery disk.
    expect(Storage::disk('local')->allFiles('gallery'))->toBeEmpty();
});

it('labels an upload from an older client as "other"', function () {
    $tenant = GalleryFixtures::tenant();
    Sanctum::actingAs($tenant->user);

    $this->post('/api/uploads', ['file' => UploadedFile::fake()->image('x.png')])->assertOk();

    expect(GalleryAsset::first()->origin->value)->toBe('upload');
});

it('updates the row in place when an upload replaces a file', function () {
    $tenant = GalleryFixtures::tenant();
    Sanctum::actingAs($tenant->user);

    $this->post('/api/uploads', ['file' => UploadedFile::fake()->image('menu.png')->size(10), 'purpose' => 'catalog'])->assertOk();
    $this->post('/api/uploads', [
        'file' => UploadedFile::fake()->image('menu.png')->size(30),
        'purpose' => 'catalog',
        'on_conflict' => 'replace',
    ])->assertOk();

    expect(GalleryAsset::count())->toBe(1)
        ->and(GalleryAsset::first()->size_bytes)->toBe(30 * 1024);
});

it('lists identical bytes once even under two names', function () {
    $tenant = GalleryFixtures::tenant();
    Sanctum::actingAs($tenant->user);

    $bytes = UploadedFile::fake()->image('a.png')->size(10)->getContent();

    $this->post('/api/uploads', ['file' => UploadedFile::fake()->createWithContent('a.png', $bytes), 'purpose' => 'flow'])->assertOk();
    $this->post('/api/uploads', ['file' => UploadedFile::fake()->createWithContent('b.png', $bytes), 'purpose' => 'campaign'])->assertOk();

    expect(Storage::disk('public')->allFiles("uploads/{$tenant->id}"))->toHaveCount(2)
        ->and(GalleryAsset::count())->toBe(1);
});

it('lists what an agent sends, and nothing a customer sends', function () {
    $tenant = GalleryFixtures::tenant();
    $conversation = linkedConversation($tenant);

    $sent = linkedAttachment($conversation, ['sent_by_user_id' => $tenant->user->id]);
    linkedAttachment($conversation, ['sender_type' => SenderType::Incoming]);
    // A flow send went out with a file already listed under its own origin.
    linkedAttachment($conversation);

    $assets = GalleryAsset::all();

    expect($assets)->toHaveCount(1)
        ->and($assets->first()->origin->value)->toBe('message')
        ->and($assets->first()->message_id)->toBe($sent->id)
        ->and($assets->first()->uploaded_by_user_id)->toBe($tenant->user->id)
        ->and(app(GalleryStorage::class)->usedBytes($tenant))->toBe(0);
});

it('registers an attachment whose sender is stamped after the file', function () {
    $tenant = GalleryFixtures::tenant();
    $message = linkedAttachment(linkedConversation($tenant));

    expect(GalleryAsset::count())->toBe(0);

    // Create-then-update is how most send paths stamp the agent.
    $message->update(['sent_by_user_id' => $tenant->user->id]);

    expect(GalleryAsset::where('message_id', $message->id)->count())->toBe(1);
});

it('drops an attachment from the gallery when the purge deletes its file', function () {
    config(['media.retention.enabled' => true]);

    $tenant = GalleryFixtures::tenant();
    $message = linkedAttachment(linkedConversation($tenant), ['sent_by_user_id' => $tenant->user->id]);

    expect(GalleryAsset::count())->toBe(1);

    Message::withoutTimestamps(fn () => $message->forceFill(['created_at' => now()->subDays(200)])->save());

    $this->artisan('media:purge')->assertSuccessful();

    expect(GalleryAsset::count())->toBe(0);
});

it('hides a linked file without deleting the file behind it', function () {
    $tenant = GalleryFixtures::tenant();
    Sanctum::actingAs($tenant->user);

    $this->post('/api/uploads', ['file' => UploadedFile::fake()->image('menu.png'), 'purpose' => 'flow'])->assertOk();
    $asset = GalleryAsset::first();

    $this->deleteJson("/api/gallery/{$asset->id}")
        ->assertOk()
        ->assertJsonPath('message', 'Arquivo ocultado da galeria.');

    expect(GalleryAsset::count())->toBe(0);
    Storage::disk('public')->assertExists($asset->path);
});

it('refuses to keep an attachment when there is no space, and keeps it when there is', function () {
    $empty = GalleryFixtures::tenant(planGb: 0);
    $message = linkedAttachment(linkedConversation($empty), ['sent_by_user_id' => $empty->user->id]);
    $linked = GalleryAsset::where('message_id', $message->id)->first();

    Sanctum::actingAs($empty->user);

    $this->postJson("/api/gallery/{$linked->id}/keep")
        ->assertStatus(422)
        ->assertJsonPath('code', 'gallery_quota_exceeded');

    // Refusing to keep it never touches the attachment itself.
    expect(GalleryAsset::find($linked->id))->not->toBeNull();

    $roomy = GalleryFixtures::tenant(planGb: 1);
    $message = linkedAttachment(linkedConversation($roomy), ['sent_by_user_id' => $roomy->user->id]);
    $linked = GalleryAsset::where('message_id', $message->id)->first();

    Sanctum::actingAs($roomy->user);

    $response = $this->postJson("/api/gallery/{$linked->id}/keep")->assertCreated();

    expect($response->json('data.origin'))->toBe('gallery')
        ->and($response->json('data.counts_toward_quota'))->toBeTrue()
        ->and(GalleryAsset::find($linked->id))->toBeNull()
        ->and(app(GalleryStorage::class)->usedBytes($roomy))->toBeGreaterThan(0);
});

it('uploading the same bytes into the library replaces the linked tile', function () {
    $tenant = GalleryFixtures::tenant(planGb: 1);
    Sanctum::actingAs($tenant->user);

    $bytes = UploadedFile::fake()->image('a.png')->size(10)->getContent();

    $this->post('/api/uploads', ['file' => UploadedFile::fake()->createWithContent('a.png', $bytes), 'purpose' => 'flow'])->assertOk();
    $this->post('/api/gallery', ['file' => UploadedFile::fake()->createWithContent('a.png', $bytes)])->assertCreated();

    expect(GalleryAsset::count())->toBe(1)
        ->and(GalleryAsset::first()->origin->value)->toBe('gallery');
});

it('keeps temporary files out of pickers that save the url', function () {
    $tenant = GalleryFixtures::tenant();
    Sanctum::actingAs($tenant->user);

    linkedAttachment(linkedConversation($tenant), ['sent_by_user_id' => $tenant->user->id]);
    $this->post('/api/uploads', ['file' => UploadedFile::fake()->image('b.png'), 'purpose' => 'flow'])->assertOk();

    $this->getJson('/api/gallery')->assertJsonCount(2, 'data');

    $this->getJson('/api/gallery?permanent=1')
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.origin', 'flow');

    $this->getJson('/api/gallery?origin=linked')->assertJsonCount(2, 'data');
    $this->getJson('/api/gallery?origin=kept')->assertJsonCount(0, 'data');

    $message = GalleryAsset::where('origin', 'message')->first();
    $row = collect($this->getJson('/api/gallery')->json('data'))->firstWhere('id', $message->id);

    expect($row['expires_at'])->not->toBeNull()
        ->and($row['counts_toward_quota'])->toBeFalse();
});

it('backfills existing uploads, classifying them by where they are used', function () {
    $tenant = GalleryFixtures::tenant();

    Storage::disk('public')->put("uploads/{$tenant->id}/Promo.png", 'promo');
    Storage::disk('public')->put("uploads/{$tenant->id}/Solto.pdf", 'loose');
    // Pre-folder era: no owner in the path, and nothing refers to it.
    Storage::disk('public')->put('uploads/abc123.jpg', 'orphan');

    $flow = Flow::create(['tenant_id' => $tenant->id, 'name' => 'Promo']);
    FlowNode::create([
        'flow_id' => $flow->id,
        'type' => 'message',
        'data' => ['messages' => [['attachment_url' => "https://chat.test/storage/uploads/{$tenant->id}/Promo.png"]]],
    ]);

    $this->artisan('gallery:index-uploads')->assertSuccessful();

    expect(GalleryAsset::where('path', "uploads/{$tenant->id}/Promo.png")->first()?->origin->value)->toBe('flow')
        ->and(GalleryAsset::where('path', "uploads/{$tenant->id}/Solto.pdf")->first()?->origin->value)->toBe('upload')
        ->and(GalleryAsset::where('path', 'uploads/abc123.jpg')->exists())->toBeFalse();

    // Idempotent.
    $this->artisan('gallery:index-uploads')->assertSuccessful();
    expect(GalleryAsset::count())->toBe(2);
});

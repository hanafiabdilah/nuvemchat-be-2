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
use App\Models\GalleryFolder;
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
 * Files the gallery lists without copying: uploads made for flows, campaigns
 * and products, attachments agents (and the AI) send, and what the AI media
 * node makes.
 *
 * Since Nov 2026 every one of them counts against the storage quota, and every
 * door a person adds a file through refuses it when it does not fit (where
 * billing is enforced). Listing a file still never makes the original upload or
 * send fail — the refusal happens before, at the door, or not at all.
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

it('counts a flow upload against the storage quota', function () {
    config(['services.billing.enforce' => true]);
    $tenant = GalleryFixtures::tenant(planGb: 1);
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

    expect($summary['used_bytes'])->toBe(40 * 1024)
        ->and($summary['files'])->toBe(1)
        ->and($summary['linked_files'])->toBe(1);

    // Linked bytes are never copied onto the gallery disk.
    expect(Storage::disk('local')->allFiles('gallery'))->toBeEmpty();
});

it('refuses a flow, campaign or product upload when the storage is full, before writing anything', function () {
    config(['services.billing.enforce' => true]);
    $tenant = GalleryFixtures::tenant(planGb: 0);
    Sanctum::actingAs($tenant->user);

    foreach (['flow', 'campaign', 'catalog'] as $purpose) {
        $this->post('/api/uploads', [
            'file' => UploadedFile::fake()->image('banner.png')->size(40),
            'purpose' => $purpose,
        ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'storage_quota_exceeded')
            ->assertJsonPath('required_bytes', 40 * 1024)
            ->assertJsonPath('shortfall_bytes', 40 * 1024);
    }

    expect(GalleryAsset::count())->toBe(0)
        ->and(Storage::disk('public')->allFiles('uploads'))->toBeEmpty();
});

it('lets a replace through when it is not bigger than the file it replaces', function () {
    config(['services.billing.enforce' => true]);
    $tenant = GalleryFixtures::tenant(planGb: 1);
    Sanctum::actingAs($tenant->user);

    $this->post('/api/uploads', ['file' => UploadedFile::fake()->image('menu.png')->size(30), 'purpose' => 'catalog'])->assertOk();

    // The rest of the gigabyte is already taken: only a replace that does not
    // grow the meter can still go through.
    GalleryFixtures::asset($tenant, GalleryFixtures::gb(1) - 30 * 1024);

    $this->post('/api/uploads', [
        'file' => UploadedFile::fake()->image('menu.png')->size(10),
        'purpose' => 'catalog',
        'on_conflict' => 'replace',
    ])->assertOk();

    $this->post('/api/uploads', [
        'file' => UploadedFile::fake()->image('menu.png')->size(60),
        'purpose' => 'catalog',
        'on_conflict' => 'replace',
    ], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonPath('code', 'storage_quota_exceeded');
});

it('does not refuse uploads where billing is not enforced', function () {
    config(['services.billing.enforce' => false]);
    $tenant = GalleryFixtures::tenant(planGb: 0);
    Sanctum::actingAs($tenant->user);

    $this->post('/api/uploads', ['file' => UploadedFile::fake()->image('a.png'), 'purpose' => 'flow'])->assertOk();
});

it('refuses an agent attachment from a conversation when the storage is full', function () {
    config(['services.billing.enforce' => true]);
    $tenant = GalleryFixtures::tenant(planGb: 0);
    $tenant->user->assignRole(\Spatie\Permission\Models\Role::findOrCreate('owner', 'web'));
    $conversation = linkedConversation($tenant);
    $conversation->update(['user_id' => $tenant->user->id]);
    Sanctum::actingAs($tenant->user);

    $this->post("/api/conversations/{$conversation->id}/send-image", [
        'image' => UploadedFile::fake()->image('foto.jpg')->size(20),
    ], ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'storage_quota_exceeded');

    $this->post("/api/conversations/{$conversation->id}/send-document", [
        'document' => UploadedFile::fake()->create('contrato.pdf', 20, 'application/pdf'),
    ], ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'storage_quota_exceeded');

    $this->postJson("/api/conversations/{$conversation->id}/video-upload", [
        'upload_id' => Str::random(20),
        'index' => 0,
        'total' => 3,
        'size' => 20 * 1024 * 1024,
        'name' => 'video.mp4',
        'chunk' => UploadedFile::fake()->create('piece', 10),
    ])->assertStatus(422)->assertJsonPath('code', 'storage_quota_exceeded');

    expect($conversation->messages()->count())->toBe(0);
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
        // Counted: it is space the workspace uses until its retention ends.
        ->and(app(GalleryStorage::class)->usedBytes($tenant))->toBe($assets->first()->size_bytes)
        ->and($assets->first()->size_bytes)->toBeGreaterThan(0);
});

it('counts the voice reply the AI sends, without ever refusing it', function () {
    config(['services.billing.enforce' => true]);
    $tenant = GalleryFixtures::tenant(planGb: 0);
    $hubTenant = \App\Models\AiHubTenant::create([
        'tenant_id' => $tenant->id,
        'external_id' => 'hub-'.Str::random(8),
        'name' => 'Hub',
        'status' => 'ACTIVE',
    ]);
    $agent = \App\Models\AiHubAgent::create([
        'ai_hub_tenant_id' => $hubTenant->id,
        'hub_agent_id' => 'hub-agent-'.Str::random(6),
        'external_id' => 'ana_ia',
        'name' => 'Ana IA',
        'model' => 'gpt-4o-mini',
        'status' => 'ACTIVE',
    ]);

    $message = linkedAttachment(linkedConversation($tenant), ['message_type' => MessageType::Audio]);
    // Stamped after the send, the way FlowExecutor::stampAiMessage does.
    $message->update(['sent_by_ai_hub_agent_id' => $agent->id]);

    $asset = GalleryAsset::where('message_id', $message->id)->first();

    expect($asset?->origin->value)->toBe('message')
        ->and(app(GalleryStorage::class)->usedBytes($tenant))->toBe($asset->size_bytes)
        // Over its limit, and that is allowed: nothing the AI makes waits on it.
        ->and(app(GalleryStorage::class)->summary($tenant)['over_quota'])->toBeTrue();
});

it('counts every attachment of an e-mail an agent sends, not only the first', function () {
    $tenant = GalleryFixtures::tenant();
    $conversation = linkedConversation($tenant);

    Storage::disk('local')->put('media/mail/1/a.pdf', 'first-attachment');
    Storage::disk('local')->put('media/mail/1/b.pdf', 'second-attachment-longer');

    $conversation->messages()->create([
        'external_id' => (string) Str::uuid(),
        'sender_type' => SenderType::Outgoing,
        'message_type' => MessageType::Document,
        'attachment' => 'media/mail/1/a.pdf',
        'sent_by_user_id' => $tenant->user->id,
        'meta' => ['email' => ['attachments' => [
            ['name' => 'a.pdf', 'path' => 'media/mail/1/a.pdf'],
            ['name' => 'b.pdf', 'path' => 'media/mail/1/b.pdf'],
        ]]],
        'sent_at' => now(),
    ]);

    expect(GalleryAsset::where('origin', 'message')->pluck('path')->sort()->values()->all())
        ->toBe(['media/mail/1/a.pdf', 'media/mail/1/b.pdf'])
        ->and(app(GalleryStorage::class)->usedBytes($tenant))->toBe(strlen('first-attachment') + strlen('second-attachment-longer'));
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

it('deletes a linked file for real, so its space comes back', function () {
    $tenant = GalleryFixtures::tenant(planGb: 1);
    Sanctum::actingAs($tenant->user);

    $this->post('/api/uploads', ['file' => UploadedFile::fake()->image('menu.png')->size(20), 'purpose' => 'flow'])->assertOk();
    $asset = GalleryAsset::first();

    $this->deleteJson("/api/gallery/{$asset->id}")
        ->assertOk()
        ->assertJsonPath('storage.used_bytes', 0);

    expect(GalleryAsset::count())->toBe(0);
    Storage::disk('public')->assertMissing($asset->path);
});

it('refuses to delete a file a flow, a live campaign or a product still sends, and names where', function () {
    $tenant = GalleryFixtures::tenant(planGb: 1);
    Sanctum::actingAs($tenant->user);

    $this->post('/api/uploads', ['file' => UploadedFile::fake()->image('Promo Natal.png'), 'purpose' => 'flow'])->assertOk();
    $asset = GalleryAsset::first();
    $url = $asset->publicUrl();

    $flow = Flow::create(['tenant_id' => $tenant->id, 'name' => 'Boas-vindas']);
    FlowNode::create([
        'flow_id' => $flow->id,
        'type' => 'message',
        'data' => ['messages' => [['message_type' => 'image', 'attachment_url' => $url]]],
    ]);

    $this->deleteJson("/api/gallery/{$asset->id}")
        ->assertStatus(409)
        ->assertJsonPath('code', 'file_in_use')
        ->assertJsonPath('used_in.0.type', 'flow')
        ->assertJsonPath('used_in.0.name', 'Boas-vindas');

    Storage::disk('public')->assertExists($asset->path);

    // Swapped out in the flow: now it goes.
    FlowNode::where('flow_id', $flow->id)->update(['data' => json_encode(['messages' => []])]);

    $this->deleteJson("/api/gallery/{$asset->id}")->assertOk();
    Storage::disk('public')->assertMissing($asset->path);
});

it('refuses to delete a library file a live campaign still sends, but not one a finished campaign sent', function () {
    $tenant = GalleryFixtures::tenant(planGb: 1);
    Sanctum::actingAs($tenant->user);

    $asset = GalleryAsset::find($this->post('/api/gallery', ['file' => UploadedFile::fake()->image('banner.png')])->json('data.id'));
    $connection = linkedConversation($tenant)->connection;

    $campaign = \App\Models\Broadcast::query()->forceCreate([
        'tenant_id' => $tenant->id,
        'connection_id' => $connection->id,
        'name' => 'Black Friday',
        'status' => 'scheduled',
        'content_type' => 'media',
        'rate_per_minute' => 10,
        'payload' => ['media_url' => $asset->publicUrl()],
    ]);

    $this->deleteJson("/api/gallery/{$asset->id}")
        ->assertStatus(409)
        ->assertJsonPath('used_in.0.type', 'campaign')
        ->assertJsonPath('used_in.0.name', 'Black Friday');

    $campaign->forceFill(['status' => 'completed'])->save();

    $this->deleteJson("/api/gallery/{$asset->id}")->assertOk();
});

it('deletes a sent attachment early, and the message says its media is gone', function () {
    $tenant = GalleryFixtures::tenant(planGb: 1);
    $message = linkedAttachment(linkedConversation($tenant), ['sent_by_user_id' => $tenant->user->id]);
    $asset = GalleryAsset::where('message_id', $message->id)->first();
    Sanctum::actingAs($tenant->user);

    $this->deleteJson("/api/gallery/{$asset->id}")->assertOk()->assertJsonPath('storage.used_bytes', 0);

    $message->refresh();

    expect($message->attachment)->toBeNull()
        ->and($message->attachment_status)->toBe(\App\Enums\Message\AttachmentStatus::Expired)
        ->and(GalleryAsset::count())->toBe(0);
    Storage::disk('local')->assertMissing($asset->path);
});

it('keeps an attachment even in a workspace that is exactly full, because the meter does not grow', function () {
    config(['services.billing.enforce' => true]);
    $tenant = GalleryFixtures::tenant(planGb: 0);
    $message = linkedAttachment(linkedConversation($tenant), ['sent_by_user_id' => $tenant->user->id]);
    $linked = GalleryAsset::where('message_id', $message->id)->first();
    $before = app(GalleryStorage::class)->usedBytes($tenant);

    Sanctum::actingAs($tenant->user);

    $response = $this->postJson("/api/gallery/{$linked->id}/keep")->assertCreated();

    expect($response->json('data.origin'))->toBe('gallery')
        ->and($response->json('data.counts_toward_quota'))->toBeTrue()
        ->and(GalleryAsset::find($linked->id))->toBeNull()
        ->and(app(GalleryStorage::class)->usedBytes($tenant))->toBe($before);
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
        ->and($row['counts_toward_quota'])->toBeTrue();
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

it('keeps a temporary attachment in the folder it was filed in', function () {
    $tenant = GalleryFixtures::tenant(planGb: 1);
    $message = linkedAttachment(linkedConversation($tenant), ['sent_by_user_id' => $tenant->user->id]);
    $linked = GalleryAsset::where('message_id', $message->id)->first();
    $folder = GalleryFolder::create(['tenant_id' => $tenant->id, 'name' => 'Enviados']);
    $linked->update(['folder_id' => $folder->id]);

    Sanctum::actingAs($tenant->user);

    // The tile turns from temporary to kept where it is, rather than jumping
    // back to the top level.
    $this->postJson("/api/gallery/{$linked->id}/keep")
        ->assertCreated()
        ->assertJsonPath('data.folder_id', $folder->id);
});

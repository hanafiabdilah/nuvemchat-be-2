<?php

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status as ConnectionStatus;
use App\Enums\Conversation\Status as ConversationStatus;
use App\Enums\Conversation\Type as ConversationType;
use App\Enums\Message\AttachmentStatus;
use App\Enums\Message\MessageType;
use App\Enums\Message\SenderType;
use App\Enums\Notification\NotificationType;
use App\Models\AiMediaGeneration;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\GalleryAsset;
use App\Models\Tenant;
use App\Services\Billing\BillingNotifier;
use App\Services\Gallery\GalleryLibrary;
use App\Services\Gallery\GalleryStorage;
use App\Services\Media\MediaStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\GalleryFixtures;

/**
 * Files the AI media node makes: counted against the storage, never refused,
 * deleted after `media.retention.ai_days` — and the WhatsApp warnings that tell
 * an owner the storage is filling up (80%, 95%, full).
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    Storage::fake('public');
});

function aiStorageConversation(Tenant $tenant): Conversation
{
    $connection = Connection::create([
        'tenant_id' => $tenant->id,
        'channel' => Channel::WhatsappApiway,
        'name' => 'WhatsApp',
        'status' => ConnectionStatus::Active,
    ]);

    $contact = Contact::create([
        'tenant_id' => $tenant->id,
        'external_id' => '5511988887777',
        'name' => 'Bia',
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

/** A finished AI media generation whose file is on the published disk. */
function aiStorageGeneration(Tenant $tenant, ?Conversation $conversation = null, int $bytes = 2048, ?\DateTimeInterface $at = null): AiMediaGeneration
{
    $path = "uploads/{$tenant->id}/ai-media/".Str::lower(Str::random(12)).'/image.png';
    Storage::disk('public')->put($path, str_repeat('x', $bytes));

    $generation = AiMediaGeneration::create([
        'tenant_id' => $tenant->id,
        'conversation_id' => $conversation?->id,
        'external_id' => (string) Str::uuid(),
        'type' => 'image',
        'status' => AiMediaGeneration::STATUS_COMPLETED,
        'path' => $path,
        'mime_type' => 'image/png',
        'size_bytes' => $bytes,
        'completed_at' => $at ?? now(),
    ]);

    if ($at !== null) {
        AiMediaGeneration::whereKey($generation->id)->toBase()->update(['created_at' => $at, 'updated_at' => $at]);
        $generation->refresh();
    }

    return $generation;
}

// ───────────────────────── AI files ─────────────────────────

it('counts an AI file against the storage without ever refusing it', function () {
    config(['services.billing.enforce' => true]);
    $tenant = GalleryFixtures::tenant(planGb: 0);
    $path = "uploads/{$tenant->id}/ai-media/abc/image.png";

    $asset = app(GalleryLibrary::class)->registerGenerated($tenant, $path, str_repeat('x', 5000), 'image/png', 'ai-7.png');

    expect($asset?->origin->value)->toBe('ai')
        ->and($asset->name)->toBe('ai-7.png')
        ->and(app(GalleryStorage::class)->usedBytes($tenant))->toBe(5000)
        // Over a 0 GB allowance, and that is allowed.
        ->and(app(GalleryStorage::class)->summary($tenant)['over_quota'])->toBeTrue();
});

it('shows when an AI file will disappear, and keeps it out of pickers that save the url', function () {
    $tenant = GalleryFixtures::tenant(planGb: 1);
    $path = "uploads/{$tenant->id}/ai-media/abc/image.png";
    app(GalleryLibrary::class)->registerGenerated($tenant, $path, 'png-bytes', 'image/png', 'ai-1.png');
    Sanctum::actingAs($tenant->user);

    $row = $this->getJson('/api/gallery')->assertOk()->json('data.0');

    expect($row['origin'])->toBe('ai')
        ->and($row['expires_at'])->not->toBeNull()
        ->and($row['counts_toward_quota'])->toBeTrue();

    $this->getJson('/api/gallery?permanent=1')->assertJsonCount(0, 'data');
});

it('deletes AI files past their retention, frees their space, and marks the messages that sent them', function () {
    config(['media.retention.enabled' => true, 'media.retention.ai_days' => 30]);
    $tenant = GalleryFixtures::tenant(planGb: 1);
    $conversation = aiStorageConversation($tenant);

    $old = aiStorageGeneration($tenant, $conversation, at: now()->subDays(31));
    $fresh = aiStorageGeneration($tenant, $conversation, at: now()->subDays(2));
    $this->artisan('gallery:index-ai-media')->assertSuccessful();

    $sent = $conversation->messages()->create([
        'external_id' => (string) Str::uuid(),
        'sender_type' => SenderType::Outgoing,
        'message_type' => MessageType::Image,
        'attachment' => MediaStorage::publishedUrl($old->path),
        'sent_at' => now()->subDays(31),
    ]);

    expect(app(GalleryStorage::class)->usedBytes($tenant))->toBe(2048);

    $oldPath = $old->path;
    $this->artisan('media:purge')->assertSuccessful();

    Storage::disk('public')->assertMissing($oldPath);
    Storage::disk('public')->assertExists($fresh->path);

    expect($old->fresh()->path)->toBeNull()
        ->and($fresh->fresh()->path)->not->toBeNull()
        ->and(GalleryAsset::where('path', $oldPath)->exists())->toBeFalse()
        ->and($sent->fresh()->attachment)->toBeNull()
        ->and($sent->fresh()->attachment_status)->toBe(AttachmentStatus::Expired);
});

it('backfills AI files still inside their retention, once, without reading them back', function () {
    config(['media.retention.enabled' => true, 'media.retention.ai_days' => 30]);
    $tenant = GalleryFixtures::tenant(planGb: 1);

    aiStorageGeneration($tenant, bytes: 1000, at: now()->subDays(3));
    aiStorageGeneration($tenant, bytes: 3000, at: now()->subDays(10));
    // The next purge's, not the meter's.
    aiStorageGeneration($tenant, bytes: 9000, at: now()->subDays(40));

    $this->artisan('gallery:index-ai-media')->assertSuccessful();
    $this->artisan('gallery:index-ai-media')->assertSuccessful();

    expect(GalleryAsset::where('origin', 'ai')->count())->toBe(2)
        ->and(app(GalleryStorage::class)->usedBytes($tenant))->toBe(4000);
});

it('treats a deleted AI file like an expired one', function () {
    $tenant = GalleryFixtures::tenant(planGb: 1);
    $conversation = aiStorageConversation($tenant);
    $generation = aiStorageGeneration($tenant, $conversation);
    $asset = app(GalleryLibrary::class)->registerGenerated($tenant, $generation->path, str_repeat('x', 2048), 'image/png');

    $sent = $conversation->messages()->create([
        'external_id' => (string) Str::uuid(),
        'sender_type' => SenderType::Outgoing,
        'message_type' => MessageType::Image,
        'attachment' => MediaStorage::publishedUrl($generation->path),
        'sent_at' => now(),
    ]);

    Sanctum::actingAs($tenant->user);
    $this->deleteJson("/api/gallery/{$asset->id}")->assertOk();

    Storage::disk('public')->assertMissing($generation->path);
    expect($generation->fresh()->path)->toBeNull()
        ->and($sent->fresh()->attachment_status)->toBe(AttachmentStatus::Expired);
});

// ───────────────────────── Warnings ─────────────────────────

it('warns the owner once at 80%, again at 95%, and when full', function () {
    config(['services.billing.enforce' => true]);
    $tenant = GalleryFixtures::tenant(planGb: 1);
    $gb = GalleryFixtures::gb(1);

    $notifier = Mockery::mock(BillingNotifier::class);
    $notifier->shouldReceive('notifyTenant')->once()->with(NotificationType::StorageAlmostFull, Mockery::any(), Mockery::on(fn ($c) => $c['percent'] === 80))->ordered();
    $notifier->shouldReceive('notifyTenant')->once()->with(NotificationType::StorageAlmostFull, Mockery::any(), Mockery::on(fn ($c) => $c['percent'] === 96))->ordered();
    $notifier->shouldReceive('notifyTenant')->once()->with(NotificationType::StorageFull, Mockery::any(), Mockery::any())->ordered();
    app()->instance(BillingNotifier::class, $notifier);

    GalleryFixtures::asset($tenant, intdiv($gb * 81, 100));
    $this->artisan('storage:check-alerts')->assertSuccessful();
    // Same level an hour later: nothing new to say.
    $this->artisan('storage:check-alerts')->assertSuccessful();

    GalleryFixtures::asset($tenant, intdiv($gb * 15, 100) + 1024);
    $this->artisan('storage:check-alerts')->assertSuccessful();

    GalleryFixtures::asset($tenant, intdiv($gb * 10, 100));
    $this->artisan('storage:check-alerts')->assertSuccessful();

    expect($tenant->fresh()->storage_alert_level)->toBe(100);
});

it('warns again only after usage really came down, not when it hovers at a threshold', function () {
    config(['services.billing.enforce' => true]);
    $tenant = GalleryFixtures::tenant(planGb: 1);
    $gb = GalleryFixtures::gb(1);

    $notifier = Mockery::mock(BillingNotifier::class);
    $notifier->shouldReceive('notifyTenant')->twice();
    app()->instance(BillingNotifier::class, $notifier);

    $big = GalleryFixtures::asset($tenant, intdiv($gb * 81, 100));
    $this->artisan('storage:check-alerts')->assertSuccessful();

    // Hovering just under the line keeps the warning armed as given.
    $big->update(['size_bytes' => intdiv($gb * 79, 100)]);
    $this->artisan('storage:check-alerts')->assertSuccessful();
    expect($tenant->fresh()->storage_alert_level)->toBe(80);

    // Well under it clears the level, so the next climb is announced again.
    $big->update(['size_bytes' => intdiv($gb * 50, 100)]);
    $this->artisan('storage:check-alerts')->assertSuccessful();
    expect($tenant->fresh()->storage_alert_level)->toBe(0);

    $big->update(['size_bytes' => intdiv($gb * 85, 100)]);
    $this->artisan('storage:check-alerts')->assertSuccessful();
});

it('says nothing where billing is not enforced', function () {
    config(['services.billing.enforce' => false]);
    $tenant = GalleryFixtures::tenant(planGb: 0);
    GalleryFixtures::asset($tenant, 1000);

    $notifier = Mockery::mock(BillingNotifier::class);
    $notifier->shouldNotReceive('notifyTenant');
    app()->instance(BillingNotifier::class, $notifier);

    $this->artisan('storage:check-alerts')->assertSuccessful();

    Sanctum::actingAs($tenant->user);
    expect($this->getJson('/api/user')->json('data.billing.storage'))->toBeNull();
});

it('tells the dashboard how full the storage is, for the banner', function () {
    config(['services.billing.enforce' => true]);
    $tenant = GalleryFixtures::tenant(planGb: 1);
    GalleryFixtures::asset($tenant, intdiv(GalleryFixtures::gb(1) * 96, 100));
    Sanctum::actingAs($tenant->user);

    $this->getJson('/api/user')
        ->assertOk()
        ->assertJsonPath('data.billing.storage.level', 95)
        ->assertJsonPath('data.billing.storage.limit_bytes', GalleryFixtures::gb(1));
});

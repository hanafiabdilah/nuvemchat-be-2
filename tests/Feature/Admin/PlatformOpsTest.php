<?php

use App\Enums\Broadcast\Status as BroadcastStatus;
use App\Enums\Message\AttachmentStatus;
use App\Models\Broadcast;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/** @param list<string> $permissions */
function opsAdmin(array $permissions): User
{
    $role = Role::findOrCreate('super-admin', 'web');
    $role->forceFill(['is_platform' => true])->save();

    foreach ($permissions as $permission) {
        $role->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }

    $user = User::factory()->create(['tenant_id' => null]);
    $user->assignRole($role);

    return $user;
}

function opsTenant(): Tenant
{
    $owner = User::factory()->create();
    $tenant = Tenant::create(['user_id' => $owner->id]);
    $owner->update(['tenant_id' => $tenant->id]);

    return $tenant->fresh();
}

function opsConversation(Tenant $tenant, array $conversationAttributes = []): Conversation
{
    $connection = Connection::create([
        'tenant_id' => $tenant->id,
        'name' => 'WA',
        'channel' => 'whatsapp_official',
        'status' => 'active',
    ]);

    $contact = Contact::create([
        'tenant_id' => $tenant->id,
        'external_id' => 'c-'.uniqid(),
        'name' => 'Customer',
    ]);

    return Conversation::create(array_merge([
        'connection_id' => $connection->id,
        'contact_id' => $contact->id,
        'external_id' => 'conv-'.uniqid(),
        'status' => 'active',
    ], $conversationAttributes));
}

/**
 * Messages require `sent_at` — the model's created hook stamps the
 * conversation's last_message_at from it.
 */
function opsMessage(Conversation $conversation, array $attributes = []): Message
{
    return Message::create(array_merge([
        'conversation_id' => $conversation->id,
        'sender_type' => 'incoming',
        'message_type' => 'text',
        'sent_at' => now()->timestamp,
    ], $attributes));
}

/* -------------------------------------------------------------------------
 | Broadcasts
 * ------------------------------------------------------------------------- */

function opsBroadcast(Tenant $tenant, array $attributes = []): Broadcast
{
    $conversation = opsConversation($tenant);

    return Broadcast::create(array_merge([
        'tenant_id' => $tenant->id,
        'connection_id' => $conversation->connection_id,
        'created_by' => $tenant->user_id,
        'name' => 'Black Friday',
        'status' => BroadcastStatus::Running->value,
        'content_type' => 'text',
        'payload' => ['body' => 'hi'],
        'rate_per_minute' => 60,
        'total_recipients' => 100,
        'sent_count' => 40,
        'last_tick_at' => now(),
    ], $attributes));
}

test('a campaign whose pump stopped ticking is flagged as stalled', function () {
    $tenant = opsTenant();
    opsBroadcast($tenant, ['last_tick_at' => now()->subMinutes(10)]);
    opsBroadcast($tenant, ['name' => 'Healthy', 'last_tick_at' => now()]);

    $res = $this->actingAs(opsAdmin(['bo.broadcasts.manage']), 'sanctum')
        ->getJson('/api/admin/broadcasts')
        ->assertOk();

    expect($res->json('summary.running'))->toBe(2);
    expect($res->json('summary.stalled'))->toBe(1);

    // Per row too — knowing one exists is useless without knowing which.
    $stalled = collect($res->json('data'))->firstWhere('name', 'Black Friday');
    expect($stalled['is_stalled'])->toBeTrue();
    expect(collect($res->json('data'))->firstWhere('name', 'Healthy')['is_stalled'])->toBeFalse();
});

test('an operator can pause a running campaign and it stays resumable', function () {
    $tenant = opsTenant();
    $broadcast = opsBroadcast($tenant);

    $this->actingAs(opsAdmin(['bo.broadcasts.manage']), 'sanctum')
        ->postJson("/api/admin/broadcasts/{$broadcast->id}/pause")
        ->assertOk()
        ->assertJsonPath('data.status', BroadcastStatus::Paused->value);

    expect($broadcast->fresh()->status)->toBe(BroadcastStatus::Paused);
});

test('a finished campaign cannot be cancelled again', function () {
    $tenant = opsTenant();
    $broadcast = opsBroadcast($tenant, ['status' => BroadcastStatus::Completed->value]);

    $this->actingAs(opsAdmin(['bo.broadcasts.manage']), 'sanctum')
        ->postJson("/api/admin/broadcasts/{$broadcast->id}/cancel")
        ->assertStatus(422);
});

test('campaign progress is reported across sent, failed and skipped', function () {
    $tenant = opsTenant();
    opsBroadcast($tenant, ['sent_count' => 20, 'failed_count' => 5, 'skipped_count' => 25]);

    $res = $this->actingAs(opsAdmin(['bo.broadcasts.manage']), 'sanctum')
        ->getJson('/api/admin/broadcasts')
        ->assertOk();

    // A recipient that was skipped is dealt with, not pending.
    expect((float) $res->json('data.0.progress_pct'))->toBe(50.0);
});

/* -------------------------------------------------------------------------
 | Storage
 * ------------------------------------------------------------------------- */

test('an attachment is measured as it lands, without touching the handlers', function () {
    Storage::fake('local');
    Storage::disk('local')->put('media/photo.jpg', str_repeat('x', 2048));

    $conversation = opsConversation(opsTenant());

    $message = opsMessage($conversation, [
        'message_type' => 'image',
        'attachment' => 'media/photo.jpg',
    ]);

    expect($message->fresh()->attachment_size)->toBe(2048);
});

test('media stored in someone else absolute URL is not counted as our disk', function () {
    Storage::fake('local');
    $conversation = opsConversation(opsTenant());

    $message = opsMessage($conversation, [
        'message_type' => 'image',
        'attachment' => 'https://cdn.example.com/photo.jpg',
    ]);

    expect($message->fresh()->attachment_size)->toBeNull();
});

test('the storage page reports how much of the total it could actually measure', function () {
    Storage::fake('local');
    Storage::disk('local')->put('media/a.jpg', str_repeat('x', 1000));

    $tenant = opsTenant();
    $conversation = opsConversation($tenant);

    opsMessage($conversation, [
        'message_type' => 'image',
        'attachment' => 'media/a.jpg',
    ]);

    // A row from before the size column existed.
    $old = opsMessage($conversation, [
        'message_type' => 'image',
        'attachment' => 'media/gone.jpg',
    ]);
    Message::whereKey($old->id)->toBase()->update(['attachment_size' => null]);

    $res = $this->actingAs(opsAdmin(['bo.storage.view']), 'sanctum')
        ->getJson('/api/admin/storage')
        ->assertOk();

    expect($res->json('data.totals.files'))->toBe(2);
    expect($res->json('data.totals.bytes'))->toBe(1000);
    expect($res->json('data.totals.unmeasured'))->toBe(1);
    expect((float) $res->json('data.totals.measured_pct'))->toBe(50.0);
    expect($res->json('data.by_tenant.0.tenant_id'))->toBe($tenant->id);
});

test('purged media stops counting against the customer', function () {
    Storage::fake('local');
    $conversation = opsConversation(opsTenant());

    $message = opsMessage($conversation, [
        'message_type' => 'image',
        'attachment' => 'media/gone.jpg',
        'attachment_status' => AttachmentStatus::Expired->value,
    ]);
    Message::whereKey($message->id)->toBase()->update(['attachment_size' => 5000]);

    $res = $this->actingAs(opsAdmin(['bo.storage.view']), 'sanctum')
        ->getJson('/api/admin/storage')
        ->assertOk();

    expect($res->json('data.totals.files'))->toBe(0);
    expect($res->json('data.retention.expired_files'))->toBe(1);
});

/* -------------------------------------------------------------------------
 | Conversation overview
 * ------------------------------------------------------------------------- */

test('the overview counts volume and backlog without exposing message bodies', function () {
    $tenant = opsTenant();
    $conversation = opsConversation($tenant);

    opsMessage($conversation, ['body' => 'a customer secret']);
    opsMessage($conversation, [
        'sender_type' => 'outgoing',
        'body' => 'reply',
        'error' => 'channel rejected',
    ]);

    $res = $this->actingAs(opsAdmin(['bo.conversations.view']), 'sanctum')
        ->getJson('/api/admin/conversations-overview?days=7')
        ->assertOk();

    expect($res->json('data.totals.inbound'))->toBe(1);
    expect($res->json('data.totals.outbound'))->toBe(1);
    expect($res->json('data.totals.failed_sends'))->toBe(1);

    // The whole design point: operations gets volume, not other companies' chats.
    expect(json_encode($res->json()))->not->toContain('a customer secret');
});

test('a conversation left pending for hours shows up as backlog', function () {
    $tenant = opsTenant();
    $stale = opsConversation($tenant, ['status' => 'pending']);
    opsMessage($stale);
    Conversation::whereKey($stale->id)->toBase()->update(['updated_at' => now()->subHours(9)]);

    opsMessage(opsConversation($tenant, ['status' => 'pending']));

    $res = $this->actingAs(opsAdmin(['bo.conversations.view']), 'sanctum')
        ->getJson('/api/admin/conversations-overview')
        ->assertOk();

    expect($res->json('data.totals.pending'))->toBe(2);
    expect($res->json('data.totals.stale_pending'))->toBe(1);
});

/*
 * What this page is for is telling operations which workspaces are stuck, so
 * a backlog figure nobody can act on is worse than none. A real tenant read
 * 4,594 "Waiting" here against three queued threads: the Live Chat Widget
 * opens a Pending conversation the moment a visitor loads the page, and
 * nothing ever drains those.
 */
test('the backlog leaves out threads nobody can act on', function () {
    $tenant = opsTenant();

    opsMessage(opsConversation($tenant, ['status' => 'pending']));

    // A visitor who loaded a page carrying the widget and never wrote.
    opsConversation($tenant, ['status' => 'pending']);

    // A group the workspace removed — gone from the panel by definition.
    $removed = opsConversation($tenant, ['status' => 'pending']);
    opsMessage($removed);
    $removed->contact->update(['is_group' => true, 'group_removed_at' => now()]);

    $res = $this->actingAs(opsAdmin(['bo.conversations.view']), 'sanctum')
        ->getJson('/api/admin/conversations-overview')
        ->assertOk();

    expect($res->json('data.totals.pending'))->toBe(1);
    expect($res->json('data.totals.new_conversations'))->toBe(1);
});

/* -------------------------------------------------------------------------
 | Reports
 * ------------------------------------------------------------------------- */

test('a report streams a CSV with a BOM so Excel reads accented names', function () {
    opsTenant();

    $res = $this->actingAs(opsAdmin(['bo.reports.export']), 'sanctum')
        ->get('/api/admin/reports/customers')
        ->assertOk();

    $body = $res->streamedContent();

    expect(substr($body, 0, 3))->toBe("\xEF\xBB\xBF");
    expect($body)->toContain('tenant_id,owner,email');
});

test('an unknown report is a 404 rather than an empty file', function () {
    $this->actingAs(opsAdmin(['bo.reports.export']), 'sanctum')
        ->get('/api/admin/reports/everything')
        ->assertNotFound();
});

test('exporting is written to the audit log', function () {
    $admin = opsAdmin(['bo.reports.export']);

    $this->actingAs($admin, 'sanctum')->get('/api/admin/reports/invoices')->assertOk();

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'report.export',
        'actor_id' => $admin->id,
    ]);
});

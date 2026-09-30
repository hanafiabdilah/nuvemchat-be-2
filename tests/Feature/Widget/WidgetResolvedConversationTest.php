<?php

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status as ConnectionStatus;
use App\Enums\Conversation\Status as ConversationStatus;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;

/**
 * A resolved widget conversation is read-only to the visitor. The widget
 * enforces it when it knows the thread closed; the server has to enforce it for
 * the tabs that did not — before this, their messages landed in the closed
 * thread, out of every queue.
 */
uses(RefreshDatabase::class);

const RESOLVED_WIDGET_APP_ID = 'app-resolved-test';

beforeEach(function () {
    RateLimiter::clear('widget:127.0.0.1|'.RESOLVED_WIDGET_APP_ID);
    RateLimiter::clear('widget-app:'.RESOLVED_WIDGET_APP_ID);
});

/** Opens a widget session and returns its token + conversation. */
function resolvedWidgetSession(): array
{
    $user = User::factory()->create();
    $tenant = Tenant::create(['user_id' => $user->id]);
    $user->forceFill(['tenant_id' => $tenant->id])->save();

    Connection::create([
        'tenant_id' => $tenant->id,
        'channel' => Channel::LiveChatWidget,
        'name' => 'Site',
        'status' => ConnectionStatus::Active,
        'credentials' => ['app_id' => RESOLVED_WIDGET_APP_ID],
    ]);

    $response = test()->postJson('/widget-api/session/'.RESOLVED_WIDGET_APP_ID, ['visitor_id' => 'visitor-1'])->assertOk();

    return [$response->json('session_token'), Conversation::findOrFail($response->json('conversation_id'))];
}

it('refuses a message to a resolved conversation without writing it', function () {
    [$token, $conversation] = resolvedWidgetSession();

    test()->postJson("/widget-api/session/{$token}/messages", ['message' => 'oi'])->assertOk();

    $conversation->markResolved();
    $conversation->forceFill(['status' => ConversationStatus::Resolved])->save();

    test()->postJson("/widget-api/session/{$token}/messages", ['message' => 'ainda está aí?'])
        ->assertStatus(409)
        ->assertJsonPath('code', 'conversation_resolved')
        ->assertJsonPath('conversation.status', 'resolved');

    expect(Message::where('conversation_id', $conversation->id)->where('body', 'ainda está aí?')->exists())->toBeFalse()
        ->and($conversation->fresh()->status)->toBe(ConversationStatus::Resolved);
});

it('refuses an upload to a resolved conversation', function () {
    Storage::fake('local');
    [$token, $conversation] = resolvedWidgetSession();
    $conversation->forceFill(['status' => ConversationStatus::Resolved])->save();

    test()->post("/widget-api/session/{$token}/uploads", [
        'file' => UploadedFile::fake()->create('foto.png', 10, 'image/png'),
    ], ['Accept' => 'application/json'])
        ->assertStatus(409)
        ->assertJsonPath('code', 'conversation_resolved');
});

it('still accepts messages while the conversation is open', function () {
    [$token, $conversation] = resolvedWidgetSession();
    $conversation->forceFill(['status' => ConversationStatus::Active])->save();

    test()->postJson("/widget-api/session/{$token}/messages", ['message' => 'olá'])->assertOk();

    expect(Message::where('conversation_id', $conversation->id)->where('body', 'olá')->exists())->toBeTrue();
});

it('keeps a resolved conversation readable', function () {
    [$token, $conversation] = resolvedWidgetSession();
    test()->postJson("/widget-api/session/{$token}/messages", ['message' => 'oi'])->assertOk();
    $conversation->forceFill(['status' => ConversationStatus::Resolved])->save();

    test()->getJson("/widget-api/session/{$token}/messages")->assertOk()->assertJsonCount(1, 'messages');
    test()->getJson("/widget-api/session/{$token}")->assertOk()->assertJsonPath('conversation.status', 'resolved');
});

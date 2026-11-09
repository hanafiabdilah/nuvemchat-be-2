<?php

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status;
use App\Enums\Conversation\Status as ConversationStatus;
use App\Enums\Message\MessageType;
use App\Enums\Message\SenderType;
use App\Jobs\SendFlowFailureAlert;
use App\Jobs\SendWhatsappMessageJob;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Otp;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Connection\Channels\WhatsappApiwayChannel;
use App\Services\Notification\NotificationConfig;
use App\Services\Notification\WhatsappAlerts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    // NotificationService queues with dispatchAfterResponse, which only the bus sees.
    Bus::fake([SendWhatsappMessageJob::class, SendFlowFailureAlert::class]);
    Setting::set(NotificationConfig::KEY_ENABLED, '1');
    Role::findOrCreate('owner', 'web');
});

/** A workspace owner with a verified WhatsApp who opted into $alerts. */
function alertsOwner(array $alerts = [], array $overrides = []): User
{
    $user = User::factory()->create(array_merge([
        'name' => 'Ana',
        'password' => 'secret-password',
        'whatsapp_number' => '5511999998888',
        'whatsapp_verified_at' => now(),
        'notification_preferences' => ['whatsapp_alerts' => $alerts],
    ], $overrides));

    $tenant = Tenant::create(['user_id' => $user->id]);
    $user->forceFill(['tenant_id' => $tenant->id])->save();
    $user->assignRole('owner');

    return $user;
}

/** Another person in the same workspace, with access to the given connections only. */
function alertsAgent(User $owner, array $alerts, array $connections = [], string $number = '5521988887777'): User
{
    $agent = User::factory()->create([
        'name' => 'Bruno',
        'tenant_id' => $owner->tenant_id,
        'whatsapp_number' => $number,
        'whatsapp_verified_at' => now(),
        'notification_preferences' => ['whatsapp_alerts' => $alerts],
    ]);
    $agent->connections()->sync(collect($connections)->pluck('id'));

    return $agent;
}

function alertsConnection(User $owner, Channel $channel = Channel::WhatsappApiway, string $name = 'Vendas'): Connection
{
    return Connection::create([
        'tenant_id' => $owner->tenant_id,
        'channel' => $channel,
        'name' => $name,
        'color' => '#22c55e',
        'status' => Status::Active,
        'credentials' => ['instance_id' => 'inst-'.uniqid(), 'token' => 'tok'],
    ]);
}

function alertsConversation(Connection $connection, string $phone = '5511977776666', string $name = 'Maria'): Conversation
{
    $contact = Contact::create([
        'connection_id' => $connection->id,
        'tenant_id' => $connection->tenant_id,
        'channel' => $connection->channel,
        'external_id' => $phone,
        'name' => $name,
        'username' => $phone,
    ]);

    return Conversation::create([
        'contact_id' => $contact->id,
        'connection_id' => $connection->id,
        'external_id' => $phone,
        'status' => ConversationStatus::Pending,
    ]);
}

/** @return list<SendWhatsappMessageJob> */
function alertsSent(string $type): array
{
    return Bus::dispatchedAfterResponse(SendWhatsappMessageJob::class, fn ($job) => $job->type === 'notification:'.$type)->all();
}

test('every alert is off until the person turns it on, and turning one on leaves the others alone', function () {
    $owner = alertsOwner([], ['notification_preferences' => null]);

    expect($owner->notificationSettings()['whatsapp_alerts'])->toBe([
        'connection_down' => false,
        'flow_failed' => false,
        'receipt_approved' => false,
        'receipt_rejected' => false,
        'lead_summary' => 'off',
        'ads_summary' => 'off',
    ]);

    $this->actingAs($owner, 'sanctum')
        ->putJson('/api/user/notification-preferences', ['whatsapp_alerts' => ['connection_down' => true]])
        ->assertOk();

    $this->actingAs($owner, 'sanctum')
        ->putJson('/api/user/notification-preferences', ['whatsapp_alerts' => ['lead_summary' => 'daily']])
        ->assertOk()
        ->assertJsonPath('notification_preferences.whatsapp_alerts.connection_down', true)
        ->assertJsonPath('notification_preferences.whatsapp_alerts.lead_summary', 'daily');

    $this->actingAs($owner, 'sanctum')
        ->putJson('/api/user/notification-preferences', ['whatsapp_alerts' => ['lead_summary' => 'weekly']])
        ->assertUnprocessable();

    // The ads summary has a weekly mode and no hourly one.
    $this->actingAs($owner, 'sanctum')
        ->putJson('/api/user/notification-preferences', ['whatsapp_alerts' => ['ads_summary' => 'weekly']])
        ->assertOk()
        ->assertJsonPath('notification_preferences.whatsapp_alerts.ads_summary', 'weekly')
        ->assertJsonPath('notification_preferences.whatsapp_alerts.lead_summary', 'daily');

    $this->actingAs($owner, 'sanctum')
        ->putJson('/api/user/notification-preferences', ['whatsapp_alerts' => ['ads_summary' => 'hourly']])
        ->assertUnprocessable();
});

test('a new number is only saved once the code sent to it is confirmed', function () {
    $owner = alertsOwner();

    $this->actingAs($owner, 'sanctum')
        ->postJson('/api/user/whatsapp-number', ['whatsapp_number' => '+55 21 97777-1234', 'current_password' => 'wrong'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('current_password');

    $this->actingAs($owner, 'sanctum')
        ->postJson('/api/user/whatsapp-number', ['whatsapp_number' => '+55 21 97777-1234', 'current_password' => 'secret-password'])
        ->assertOk();

    $otp = Otp::where('user_id', $owner->id)->latest('id')->first();

    // The code went to the new number; the account still has the old one.
    expect($otp->whatsapp_number)->toBe('5521977771234')
        ->and($owner->fresh()->whatsapp_number)->toBe('5511999998888')
        ->and($owner->fresh()->whatsapp_verified_at)->not->toBeNull();

    $this->actingAs($owner, 'sanctum')
        ->postJson('/api/user/whatsapp-number/verify', ['code' => $otp->code])
        ->assertOk()
        ->assertJsonPath('whatsapp_number', '5521977771234');

    expect($owner->fresh()->whatsapp_number)->toBe('5521977771234');
});

test('the test message needs a confirmed number', function () {
    // No number at all — an agent the owner created, say. (An unverified one
    // never gets this far: EnsureWhatsAppVerified keeps it out of the app.)
    $owner = alertsOwner([], ['whatsapp_number' => null, 'whatsapp_verified_at' => null]);

    $this->actingAs($owner, 'sanctum')->postJson('/api/user/whatsapp-alerts/test')
        ->assertUnprocessable()
        ->assertJsonPath('code', 'whatsapp_number_missing');

    $owner->forceFill(['whatsapp_number' => '5511999998888', 'whatsapp_verified_at' => now()])->save();

    $this->actingAs($owner, 'sanctum')->postJson('/api/user/whatsapp-alerts/test')->assertOk();

    expect(alertsSent('alert_test'))->toHaveCount(1);
});

test('a number logged out from the phone alerts the people who opted in and can see that connection', function () {
    Http::fake(['*/v1/instance/status-instance*' => Http::response(['success' => true, 'data' => ['connected' => false, 'loggedIn' => false]])]);

    $owner = alertsOwner(['connection_down' => true]);
    $connection = alertsConnection($owner);
    $other = alertsConnection($owner, name: 'Suporte');
    $withAccess = alertsAgent($owner, ['connection_down' => true], [$connection], '5521911110000');
    alertsAgent($owner, ['connection_down' => true], [$other], '5521922220000');
    alertsAgent($owner, ['connection_down' => false], [$connection], '5521933330000');

    (new WhatsappApiwayChannel)->syncStatus($connection);

    $sent = alertsSent('alert_connection_down');

    expect(collect($sent)->pluck('to')->sort()->values()->all())->toBe(['5511999998888', '5521911110000'])
        ->and($sent[0]->message)->toContain('Vendas')
        ->and($sent[0]->message)->toContain('desvinculado do celular');

    // Flapping does not repeat the alert.
    $connection->update(['status' => Status::Active]);
    (new WhatsappApiwayChannel)->syncStatus($connection->fresh());

    expect(alertsSent('alert_connection_down'))->toHaveCount(2);
});

test('flows that stop in a burst are reported once, with how many stopped', function () {
    $owner = alertsOwner(['flow_failed' => true]);
    $connection = alertsConnection($owner);

    WhatsappAlerts::flowFailed(alertsConversation($connection, '5511900000001'), 'Funil A', 'O WhatsApp recusou a mensagem.');
    WhatsappAlerts::flowFailed(alertsConversation($connection, '5511900000002'), 'Funil A', null);
    WhatsappAlerts::flowFailed(alertsConversation($connection, '5511900000003'), 'Funil B', null);

    Bus::assertDispatchedTimes(SendFlowFailureAlert::class, 1);

    WhatsappAlerts::sendFlowFailures($connection->id);

    $sent = alertsSent('alert_flow_failed');

    expect($sent)->toHaveCount(1)
        ->and($sent[0]->message)->toContain('3 conversa')
        ->and($sent[0]->message)->toContain('Funil A, Funil B')
        ->and($sent[0]->message)->toContain('O WhatsApp recusou a mensagem.');
});

test('receipts the AI read are reported to whoever asked for them', function () {
    $owner = alertsOwner(['receipt_approved' => true, 'receipt_rejected' => false]);
    $conversation = alertsConversation(alertsConnection($owner));

    WhatsappAlerts::receiptApproved($conversation, 4990, 'BRL');
    WhatsappAlerts::receiptRejected($conversation, 'amount_mismatch');

    $approved = alertsSent('alert_receipt_approved');

    expect($approved)->toHaveCount(1)
        ->and($approved[0]->message)->toContain('Maria')
        ->and($approved[0]->message)->toContain('49,90')
        ->and(alertsSent('alert_receipt_rejected'))->toHaveCount(0);
});

test('the hourly summary counts new leads, not returning customers, and stays quiet when there were none', function () {
    Carbon::setTestNow('2026-11-03 15:05:00');
    $owner = alertsOwner(['lead_summary' => 'hourly']);
    $owner->tenant->forceFill(['timezone' => 'UTC'])->save();
    $connection = alertsConnection($owner);

    $wrote = function (Conversation $conversation, string $at) {
        $conversation->forceFill(['created_at' => $at])->save();
        Message::create([
            'conversation_id' => $conversation->id,
            'sender_type' => SenderType::Incoming,
            'message_type' => MessageType::Text,
            'body' => 'oi',
            'sent_at' => $at,
        ]);
    };

    $wrote(alertsConversation($connection, '5511900000001'), '2026-11-03 14:10:00');
    $wrote(alertsConversation($connection, '5511900000002'), '2026-11-03 14:40:00');

    // A returning customer: their first conversation was last week.
    $returning = alertsConversation($connection, '5511900000003');
    $wrote($returning, '2026-10-27 10:00:00');
    $again = Conversation::create(['contact_id' => $returning->contact_id, 'connection_id' => $connection->id, 'external_id' => '5511900000003', 'status' => ConversationStatus::Pending]);
    $wrote($again, '2026-11-03 14:50:00');

    // Opened but never written in: not a lead.
    alertsConversation($connection, '5511900000004')->forceFill(['created_at' => '2026-11-03 14:20:00'])->save();

    $this->artisan('alerts:lead-summary')->assertSuccessful();

    $sent = alertsSent('alert_lead_summary');

    expect($sent)->toHaveCount(1)
        ->and($sent[0]->message)->toContain('*2* novos leads')
        ->and($sent[0]->message)->toContain('entre 14:00 e 15:00');

    // The next hour had nothing in it.
    Carbon::setTestNow('2026-11-03 16:05:00');
    $this->artisan('alerts:lead-summary')->assertSuccessful();

    expect(alertsSent('alert_lead_summary'))->toHaveCount(1);

    Carbon::setTestNow();
});

test('the daily summary goes out at 8 in the workspace\'s timezone, even when the day was quiet', function () {
    $owner = alertsOwner(['lead_summary' => 'daily']);
    $owner->tenant->forceFill(['timezone' => 'America/Sao_Paulo'])->save();
    alertsConnection($owner);

    Carbon::setTestNow('2026-11-03 10:00:00'); // 07:00 in São Paulo
    $this->artisan('alerts:lead-summary')->assertSuccessful();
    expect(alertsSent('alert_lead_summary'))->toHaveCount(0);

    Carbon::setTestNow('2026-11-03 11:00:00'); // 08:00 in São Paulo
    $this->artisan('alerts:lead-summary')->assertSuccessful();
    $this->artisan('alerts:lead-summary')->assertSuccessful();

    $sent = alertsSent('alert_lead_summary');

    expect($sent)->toHaveCount(1)
        ->and($sent[0]->message)->toContain('*0* novos leads')
        ->and($sent[0]->message)->toContain('ontem');

    Carbon::setTestNow();
});

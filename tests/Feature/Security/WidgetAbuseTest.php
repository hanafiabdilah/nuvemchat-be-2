<?php

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status as ConnectionStatus;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Contact\WidgetVisitorId;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The widget API is reachable by anyone who reads a customer's page source for
 * its `app_id`. Two things followed from that and neither needed an account:
 * a visitor could name themselves after a real customer's phone number and
 * take over that contact, and nothing at all bounded how often any of it could
 * be called.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    // The limiters are keyed on address + app id, and the cache survives
    // between tests in a single process.
    RateLimiter::clear('widget:127.0.0.1|'.WIDGET_APP_ID);
    RateLimiter::clear('widget-app:'.WIDGET_APP_ID);
});

const WIDGET_APP_ID = 'app-under-test';

function widgetConnection(): Connection
{
    $user = User::factory()->create(['email' => 'widget-'.uniqid().'@example.test']);
    $tenant = Tenant::create(['user_id' => $user->id]);
    $user->forceFill(['tenant_id' => $tenant->id])->save();

    return Connection::create([
        'tenant_id' => $tenant->id,
        'channel' => Channel::LiveChatWidget,
        'name' => 'Site',
        'status' => ConnectionStatus::Active,
        'credentials' => ['app_id' => WIDGET_APP_ID],
    ]);
}

// ── Contact takeover ──

it('cannot reach a customer contact by naming itself after their phone number', function () {
    $connection = widgetConnection();

    // The workspace's real WhatsApp customer, created the ordinary way.
    $victim = Contact::create([
        'tenant_id' => $connection->tenant_id,
        'external_id' => '5511999998888',
        'name' => 'Maria Souza',
        'channel' => Channel::WhatsappOfficial,
    ]);

    $this->postJson('/widget-api/session/'.WIDGET_APP_ID, [
        'visitor_id' => '5511999998888',
        'name' => 'Suporte Banco do Brasil',
        'email' => 'attacker@example.test',
    ])->assertOk();

    // Untouched: not renamed, and no e-mail written over theirs.
    expect($victim->fresh()->name)->toBe('Maria Souza')
        ->and($victim->fresh()->username)->toBeNull();

    // The visitor got a contact of their own, in the widget's own id space.
    $visitor = Contact::where('external_id', WidgetVisitorId::scoped($connection, '5511999998888'))->first();

    expect($visitor)->not->toBeNull()
        ->and($visitor->id)->not->toBe($victim->id);
});

it('does not let one anonymous visitor rename another', function () {
    $connection = widgetConnection();

    $this->postJson('/widget-api/session/'.WIDGET_APP_ID, [
        'visitor_id' => 'visitor-one',
        'name' => 'Ana',
    ])->assertOk();

    // Same id, different name. Nothing arriving here is attributable to
    // anyone, so it may open a session but never rewrite who the contact is.
    $this->postJson('/widget-api/session/'.WIDGET_APP_ID, [
        'visitor_id' => 'visitor-one',
        'name' => 'Ana (Suporte Oficial)',
    ])->assertOk();

    $contact = Contact::where('external_id', WidgetVisitorId::scoped($connection, 'visitor-one'))->sole();

    expect($contact->name)->toBe('Ana');
});

it('keeps two connections’ visitors apart even when they choose the same id', function () {
    $first = widgetConnection();
    $second = widgetConnection();
    $second->update(['credentials' => ['app_id' => 'second-app']]);

    $this->postJson('/widget-api/session/'.WIDGET_APP_ID, ['visitor_id' => 'shared', 'name' => 'A'])->assertOk();
    $this->postJson('/widget-api/session/second-app', ['visitor_id' => 'shared', 'name' => 'B'])->assertOk();

    expect(WidgetVisitorId::scoped($first, 'shared'))
        ->not->toBe(WidgetVisitorId::scoped($second, 'shared'))
        ->and(Contact::whereIn('external_id', [
            WidgetVisitorId::scoped($first, 'shared'),
            WidgetVisitorId::scoped($second, 'shared'),
        ])->count())->toBe(2);
});

// ── Rate limiting ──

it('stops a session flood before it fills the database', function () {
    widgetConnection();

    // Five is the per-address budget: enough for a person reloading a page,
    // nowhere near enough for a script. Each call that got through used to
    // write three permanent rows.
    foreach (range(1, 5) as $i) {
        $this->postJson('/widget-api/session/'.WIDGET_APP_ID, ['visitor_id' => "v{$i}"])->assertOk();
    }

    $this->postJson('/widget-api/session/'.WIDGET_APP_ID, ['visitor_id' => 'v6'])
        ->assertStatus(429);

    expect(Contact::count())->toBe(5);
});

it('bounds uploads, which cost disk and money', function () {
    $connection = widgetConnection();

    $token = $this->postJson('/widget-api/session/'.WIDGET_APP_ID, ['visitor_id' => 'uploader'])
        ->json('session_token');

    foreach (range(1, 5) as $i) {
        $this->post("/widget-api/session/{$token}/uploads", [
            'file' => Illuminate\Http\UploadedFile::fake()->image("a{$i}.jpg")->size(10),
        ], ['Accept' => 'application/json'])->assertOk();
    }

    $this->post("/widget-api/session/{$token}/uploads", [
        'file' => Illuminate\Http\UploadedFile::fake()->image('a6.jpg')->size(10),
    ], ['Accept' => 'application/json'])->assertStatus(429);
});

it('refuses a meta blob large enough to be a storage attack', function () {
    widgetConnection();

    $this->postJson('/widget-api/session/'.WIDGET_APP_ID, [
        'visitor_id' => 'bulky',
        'meta' => array_fill(0, 200, str_repeat('x', 2000)),
    ])->assertStatus(422);
});

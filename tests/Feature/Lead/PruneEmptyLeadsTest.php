<?php

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status as ConnectionStatus;
use App\Enums\Lead\LeadSource;
use App\Events\LeadUpdated;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\User;
use App\Services\Lead\LeadResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Support\LeadAttendanceFixtures as F;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Only the broadcast: the model observers under test must still run.
    Event::fake([LeadUpdated::class]);
});

/** A live chat widget connection an embedded SDK can talk to. */
function pruneWidget(User $owner, string $appId = 'app-123'): Connection
{
    return Connection::create([
        'tenant_id' => $owner->tenant_id,
        'channel' => Channel::LiveChatWidget,
        'name' => 'Site',
        'color' => '#22c55e',
        'status' => ConnectionStatus::Active,
        'credentials' => ['app_id' => $appId],
    ]);
}

/**
 * A card the way production holds them: opened for a conversation that never
 * carried a message, back when a page view was enough to open one.
 */
function pruneGhostLead(User $owner, Connection $connection, string $externalId): Lead
{
    $contact = F::contact($owner, $externalId, Channel::LiveChatWidget);
    $conversation = F::conversation($connection, $contact);

    $lead = app(LeadResolver::class)->open($contact, $conversation, LeadSource::Inbound, $owner->tenant_id);
    $conversation->forceFill(['lead_id' => $lead->id])->saveQuietly();

    return $lead;
}

it('does not open a card when a visitor only loads the page', function () {
    $owner = F::owner();
    pruneWidget($owner);

    $this->postJson('/widget-api/session/app-123', ['name' => 'Visitante'])
        ->assertOk();

    expect(Conversation::count())->toBe(1)
        ->and(Lead::count())->toBe(0);
});

it('opens the card on the visitor first message', function () {
    $owner = F::owner();
    pruneWidget($owner);

    $token = $this->postJson('/widget-api/session/app-123', ['name' => 'Visitante'])
        ->json('session_token');

    $this->postJson("/widget-api/session/{$token}/messages", ['message' => 'Oi, tenho uma dúvida'])
        ->assertOk();

    $lead = Lead::first();

    expect($lead)->not->toBeNull()
        ->and($lead->stage->position)->toBe(0);
});

it('still opens the card immediately for channels that only create a thread when someone writes', function () {
    $owner = F::owner();
    $connection = F::connection($owner);
    $contact = F::contact($owner, '5511999999999');

    F::conversation($connection, $contact);

    expect(F::lead($contact))->not->toBeNull();
});

it('removes cards whose conversations never carried a message', function () {
    $owner = F::owner();
    $widget = pruneWidget($owner);

    pruneGhostLead($owner, $widget, 'visitor-1');
    pruneGhostLead($owner, $widget, 'visitor-2');

    // A real one: same channel, but this visitor actually wrote.
    $spoke = pruneGhostLead($owner, $widget, 'visitor-3');
    F::inbound($spoke->conversations()->first());

    $this->artisan('leads:prune-empty')->assertSuccessful();

    expect(Lead::pluck('id')->all())->toBe([$spoke->id]);
});

it('removes cards left holding no conversation at all', function () {
    $owner = F::owner();
    $widget = pruneWidget($owner);

    $orphan = pruneGhostLead($owner, $widget, 'visitor-1');
    $orphan->conversations()->first()->delete();

    $this->artisan('leads:prune-empty')->assertSuccessful();

    expect(Lead::count())->toBe(0);
});

it('keeps cards a person has worked', function () {
    $owner = F::owner();
    $widget = pruneWidget($owner);

    $titled = pruneGhostLead($owner, $widget, 'visitor-1');
    $titled->update(['title' => 'Orçamento site']);

    $valued = pruneGhostLead($owner, $widget, 'visitor-2');
    $valued->update(['value' => 1500]);

    $owned = pruneGhostLead($owner, $widget, 'visitor-3');
    $owned->update(['owner_id' => $owner->id]);

    $moved = pruneGhostLead($owner, $widget, 'visitor-4');
    $moved->moveToStage(F::stages($owner->tenant_id)['Qualificação']);

    $ghost = pruneGhostLead($owner, $widget, 'visitor-5');

    $this->artisan('leads:prune-empty')->assertSuccessful();

    expect(Lead::pluck('id')->all())
        ->toEqualCanonicalizing([$titled->id, $valued->id, $owned->id, $moved->id])
        ->not->toContain($ghost->id);
});

it('keeps cards that were asked for rather than opened by a thread', function () {
    $owner = F::owner();
    $contact = F::contact($owner, 'visitor-1', Channel::LiveChatWidget);

    $manual = app(LeadResolver::class)->open($contact, null, LeadSource::Manual, $owner->tenant_id);

    $this->artisan('leads:prune-empty')->assertSuccessful();

    expect(Lead::pluck('id')->all())->toBe([$manual->id]);
});

it('changes nothing on a dry run', function () {
    $owner = F::owner();
    $widget = pruneWidget($owner);

    pruneGhostLead($owner, $widget, 'visitor-1');

    $this->artisan('leads:prune-empty', ['--dry-run' => true])->assertSuccessful();

    expect(Lead::count())->toBe(1);
});

it('only touches the workspace it was pointed at', function () {
    $first = F::owner();
    $second = F::owner();

    pruneGhostLead($first, pruneWidget($first, 'app-first'), 'visitor-1');
    $untouched = pruneGhostLead($second, pruneWidget($second, 'app-second'), 'visitor-1');

    $this->artisan('leads:prune-empty', ['--tenant' => $first->tenant_id])->assertSuccessful();

    expect(Lead::pluck('id')->all())->toBe([$untouched->id]);
});

it('finds nothing left to do on a second run', function () {
    $owner = F::owner();
    $widget = pruneWidget($owner);

    pruneGhostLead($owner, $widget, 'visitor-1');

    $this->artisan('leads:prune-empty')->assertSuccessful();
    $this->artisan('leads:prune-empty')->expectsOutputToContain('Nothing to do.')->assertSuccessful();
});

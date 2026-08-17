<?php

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status as ConnectionStatus;
use App\Enums\Conversation\Status as ConversationStatus;
use App\Enums\Conversation\Type as ConversationType;
use App\Enums\Lead\LeadStatus;
use App\Enums\Lead\StageKind;
use App\Enums\Lead\Temperature;
use App\Enums\Message\MessageType;
use App\Enums\Message\SenderType;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\User;
use App\Events\LeadUpdated;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use App\Services\Lead\LeadResolver;
use App\Services\Lead\TemperatureScorer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

/**
 * Fake only the broadcast event.
 *
 * A bare Event::fake() also silences Eloquent's model events, which would stop
 * ConversationObserver::created from ever running — and that observer is the
 * thing under test in half this file.
 */
function leadTestFakeEvents(): void
{
    Event::fake([LeadUpdated::class]);
}

function leadTestUser(): User
{
    $user = User::factory()->create();
    $tenant = Tenant::create(['user_id' => $user->id]);
    $user->forceFill(['tenant_id' => $tenant->id])->save();

    $role = Role::findOrCreate('owner', 'web');
    foreach (['leads.view', 'leads.create', 'leads.update', 'leads.delete', 'lead-pipelines.manage'] as $permission) {
        $role->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }
    $user->assignRole($role);

    return $user->fresh();
}

function leadTestConnection(User $user): Connection
{
    $connection = Connection::create([
        'tenant_id' => $user->tenant_id,
        'channel' => Channel::WhatsappApiway,
        'name' => 'WhatsApp',
        'color' => '#22c55e',
        'status' => ConnectionStatus::Active,
    ]);

    $user->connections()->syncWithoutDetaching([$connection->id]);

    return $connection;
}

function leadTestContact(User $user, string $externalId = '5511999999999', bool $isGroup = false): Contact
{
    return Contact::create([
        'tenant_id' => $user->tenant_id,
        'external_id' => $externalId,
        'name' => $isGroup ? 'Equipe' : 'Maria',
        'channel' => Channel::WhatsappApiway,
        'is_group' => $isGroup,
    ]);
}

function leadTestConversation(Connection $connection, Contact $contact): Conversation
{
    return Conversation::create([
        'contact_id' => $contact->id,
        'connection_id' => $connection->id,
        'external_id' => $contact->external_id,
        'type' => $contact->is_group ? ConversationType::Group : ConversationType::Private,
        'status' => ConversationStatus::Pending,
    ]);
}

test('a new conversation opens a lead in the first stage', function () {
    leadTestFakeEvents();
    $user = leadTestUser();
    $connection = leadTestConnection($user);
    $contact = leadTestContact($user);

    // The observer dispatches the job; the sync queue runs it inline.
    $conversation = leadTestConversation($connection, $contact);

    $lead = Lead::where('contact_id', $contact->id)->first();

    expect($lead)->not->toBeNull()
        ->and($lead->status)->toBe(LeadStatus::Open)
        ->and($lead->stage->name)->toBe('Novo contato')
        ->and($conversation->fresh()->lead_id)->toBe($lead->id);

    // The lead's own birth is a stage event, so the funnel report reads the
    // first column the same way it reads every other move.
    expect($lead->stageEvents()->count())->toBe(1)
        ->and($lead->stageEvents()->first()->from_stage_id)->toBeNull();
});

test('a group never becomes a lead', function () {
    leadTestFakeEvents();
    $user = leadTestUser();
    $connection = leadTestConnection($user);
    $group = leadTestContact($user, '120363419920035031@g.us', isGroup: true);

    leadTestConversation($connection, $group);

    expect(Lead::where('contact_id', $group->id)->exists())->toBeFalse();
});

test('a second conversation attaches to the same open lead', function () {
    leadTestFakeEvents();
    $user = leadTestUser();
    $connection = leadTestConnection($user);
    $contact = leadTestContact($user);

    $first = leadTestConversation($connection, $contact);

    // Pingly opens a fresh conversation row whenever a message lands on a
    // resolved thread — the lead has to survive that, or the funnel would
    // restart every time an agent tidies up.
    $first->update(['status' => ConversationStatus::Resolved]);
    $second = leadTestConversation($connection, $contact);

    expect(Lead::where('contact_id', $contact->id)->count())->toBe(1)
        ->and($second->fresh()->lead_id)->toBe($first->fresh()->lead_id);
});

test('a contact can never hold two open leads', function () {
    leadTestFakeEvents();
    $user = leadTestUser();
    $connection = leadTestConnection($user);
    $contact = leadTestContact($user);

    leadTestConversation($connection, $contact);
    $lead = Lead::where('contact_id', $contact->id)->firstOrFail();

    // The generated open_contact_id column carries a unique index, so a second
    // open card for the same person is refused by the database rather than by
    // whichever writer happened to remember to check.
    expect(fn () => Lead::create([
        'tenant_id' => $lead->tenant_id,
        'contact_id' => $contact->id,
        'pipeline_id' => $lead->pipeline_id,
        'stage_id' => $lead->stage_id,
    ]))->toThrow(Illuminate\Database\UniqueConstraintViolationException::class);
});

test('winning a lead frees the contact for a future one', function () {
    leadTestFakeEvents();
    $user = leadTestUser();
    $connection = leadTestConnection($user);
    $contact = leadTestContact($user);

    leadTestConversation($connection, $contact);
    $lead = Lead::where('contact_id', $contact->id)->firstOrFail();

    $won = $lead->pipeline->stages()->where('kind', StageKind::Won)->firstOrFail();
    $lead->moveToStage($won, $user);

    expect($lead->fresh()->status)->toBe(LeadStatus::Won)
        ->and($lead->fresh()->closed_at)->not->toBeNull();

    // Six months later the same customer comes back: a new card, with the old
    // sale still on record.
    $second = app(LeadResolver::class)->open($contact->fresh());

    expect($second->id)->not->toBe($lead->id)
        ->and(Lead::where('contact_id', $contact->id)->count())->toBe(2);
});

test('moving a card records who moved it and when', function () {
    leadTestFakeEvents();
    $user = leadTestUser();
    $connection = leadTestConnection($user);
    $contact = leadTestContact($user);
    leadTestConversation($connection, $contact);

    $lead = Lead::where('contact_id', $contact->id)->firstOrFail();
    $proposta = $lead->pipeline->stages()->where('name', 'Proposta')->firstOrFail();

    $this->actingAs($user, 'sanctum')
        ->patchJson("/api/leads/{$lead->id}/stage", ['stage_id' => $proposta->id])
        ->assertOk()
        ->assertJsonPath('data.stage_id', $proposta->id);

    // stageEvents() is ordered ascending on purpose — it is a timeline — so
    // reaching for the newest row has to reorder rather than append a sort.
    $event = $lead->stageEvents()->reorder()->latest('id')->first();

    expect($event->to_stage_id)->toBe($proposta->id)
        ->and($event->to_stage_name)->toBe('Proposta')
        ->and($event->user_id)->toBe($user->id)
        ->and($lead->fresh()->stage_changed_at)->not->toBeNull();
});

test('losing a lead keeps the reason, and moving on clears it', function () {
    leadTestFakeEvents();
    $user = leadTestUser();
    $connection = leadTestConnection($user);
    $contact = leadTestContact($user);
    leadTestConversation($connection, $contact);

    $lead = Lead::where('contact_id', $contact->id)->firstOrFail();
    $lost = $lead->pipeline->stages()->where('kind', StageKind::Lost)->firstOrFail();
    $open = $lead->pipeline->stages()->where('kind', StageKind::Open)->firstOrFail();

    $lead->moveToStage($lost, $user, 'Achou caro');
    expect($lead->fresh()->lost_reason)->toBe('Achou caro');

    // A stale reason must not follow a card that was put back to work.
    $lead->moveToStage($open, $user);
    expect($lead->fresh()->lost_reason)->toBeNull()
        ->and($lead->fresh()->closed_at)->toBeNull();
});

test('temperature rises with a recent inbound message and falls with silence', function () {
    leadTestFakeEvents();
    $user = leadTestUser();
    $connection = leadTestConnection($user);
    $contact = leadTestContact($user);
    $conversation = leadTestConversation($connection, $contact);

    $lead = Lead::where('contact_id', $contact->id)->firstOrFail();
    $scorer = app(TemperatureScorer::class);

    Message::create([
        'conversation_id' => $conversation->id,
        'sender_type' => SenderType::Incoming,
        'message_type' => MessageType::Text,
        'body' => 'Oi, quanto custa?',
        // Message::boot() reads sent_at to stamp the conversation preview.
        'sent_at' => now()->timestamp,
    ]);

    $scorer->apply($lead);
    expect($lead->fresh()->temperature)->toBe(Temperature::Hot);

    // Same lead, same messages, three weeks later: recency and the 14-day
    // volume window both fall away, so it cools without anyone touching it.
    Message::where('conversation_id', $conversation->id)
        ->update(['created_at' => now()->subDays(21)]);
    $lead->forceFill(['stage_changed_at' => now()->subDays(21)])->save();

    $scorer->apply($lead->fresh());
    expect($lead->fresh()->temperature)->toBe(Temperature::Cold);
});

test('the board returns every column, including the empty ones', function () {
    leadTestFakeEvents();
    $user = leadTestUser();
    $connection = leadTestConnection($user);
    $contact = leadTestContact($user);
    leadTestConversation($connection, $contact);

    $response = $this->actingAs($user, 'sanctum')
        ->getJson('/api/leads/board')
        ->assertOk();

    // Six columns even though only one holds a card: a board that hides its
    // empty stages gives an agent nowhere to drag to.
    expect($response->json('columns'))->toHaveCount(6);
    expect($response->json('columns.0.total'))->toBe(1);
    expect($response->json('columns.1.total'))->toBe(0);
});

test('a lead created by hand is refused when one is already open', function () {
    leadTestFakeEvents();
    $user = leadTestUser();
    $connection = leadTestConnection($user);
    $contact = leadTestContact($user);
    leadTestConversation($connection, $contact);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/leads', ['contact_id' => $contact->id])
        ->assertStatus(422)
        ->assertJsonValidationErrors('contact_id');
});

test('leads from another tenant are invisible', function () {
    leadTestFakeEvents();
    $mine = leadTestUser();
    $theirs = leadTestUser();

    $connection = leadTestConnection($theirs);
    $contact = leadTestContact($theirs, '5511888888888');
    leadTestConversation($connection, $contact);

    $lead = Lead::where('contact_id', $contact->id)->firstOrFail();

    $this->actingAs($mine, 'sanctum')
        ->getJson("/api/leads/{$lead->id}")
        ->assertNotFound();
});

<?php

use App\Enums\Connection\Channel;
use App\Enums\Lead\StageKind;
use App\Enums\Message\MessageType;
use App\Events\LeadUpdated;
use App\Jobs\EnsureLeadForConversation;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\LeadPipeline;
use App\Models\Tenant;
use App\Services\Lead\LeadAttendance;
use App\Services\Lead\LeadSettings;
use App\Services\Lead\PipelineProvisioner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Support\LeadAttendanceFixtures as F;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Only the broadcast: model events (the observers under test) must run.
    Event::fake([LeadUpdated::class]);
});

/** A funnel the way it was provisioned before Atendidos existed. */
function attendedLegacyFunnel(Tenant $tenant): LeadPipeline
{
    $pipeline = LeadPipeline::create(['tenant_id' => $tenant->id, 'name' => 'Vendas', 'is_default' => true, 'position' => 0]);

    $stages = [
        ['Novo contato', StageKind::Open], ['Qualificação', StageKind::Open], ['Proposta', StageKind::Open],
        ['Negociação', StageKind::Open], ['Cliente', StageKind::Won], ['Perdido', StageKind::Lost],
    ];

    foreach ($stages as $position => [$name, $kind]) {
        $pipeline->stages()->create(['name' => $name, 'color' => 'slate', 'kind' => $kind, 'position' => $position]);
    }

    return $pipeline;
}

it('opens every new funnel with Atendidos right after the first column and aims the rule at it', function () {
    $owner = F::owner();

    $stages = F::stages($owner->tenant_id);

    expect(array_keys($stages))->toBe(['Novo contato', 'Atendidos', 'Qualificação', 'Proposta', 'Negociação', 'Cliente', 'Perdido'])
        ->and(LeadSettings::for($owner->tenant->fresh())->attendedStageId)->toBe($stages['Atendidos']->id);
});

it('moves the card to Atendidos the first time someone from the team answers', function () {
    $owner = F::owner();
    $contact = F::contact($owner, '5511900000001');
    $conversation = F::conversation(F::connection($owner), $contact);
    F::inbound($conversation);

    expect(F::lead($contact)->stage->name)->toBe('Novo contato');

    F::reply($conversation, $owner);

    $lead = F::lead($contact);

    expect($lead->stage->name)->toBe('Atendidos')
        // No actor: the history reads "automático".
        ->and($lead->stageEvents()->latest('id')->first()->user_id)->toBeNull();
});

it('does not count the flow, the AI, platform notes or a campaign as answering', function () {
    $owner = F::owner();
    $contact = F::contact($owner, '5511900000002');
    $conversation = F::conversation(F::connection($owner), $contact);

    F::inbound($conversation);
    F::reply($conversation, null);
    F::reply($conversation, $owner, ['message_type' => MessageType::Info]);
    LeadAttendance::withoutAdvancing(fn () => F::reply($conversation, $owner));

    expect(F::lead($contact)->stage->name)->toBe('Novo contato');
});

it('never moves a card that is already further along back to Atendidos', function () {
    $owner = F::owner();
    $contact = F::contact($owner, '5511900000003');
    $conversation = F::conversation(F::connection($owner), $contact);

    $lead = F::lead($contact);
    $lead->moveToStage(F::stages($owner->tenant_id)['Proposta'], $owner);

    F::reply($conversation, $owner);

    expect(F::lead($contact)->stage->name)->toBe('Proposta');
});

it('leaves cards where they are when the workspace switches the rule off', function () {
    $owner = F::owner();
    $contact = F::contact($owner, '5511900000004');
    $conversation = F::conversation(F::connection($owner), $contact);

    $this->actingAs($owner)
        ->putJson('/api/lead-settings', ['attended_stage_id' => null])
        ->assertOk()
        ->assertJsonPath('data.attended_stage_id', null);

    F::reply($conversation, $owner);

    expect(F::lead($contact)->stage->name)->toBe('Novo contato');
});

it('still moves the card when the reply came before the card was opened', function () {
    $owner = F::owner();
    $contact = F::contact($owner, '5511900000005');
    $connection = F::connection($owner);

    // The lead job has not run yet when the agent answers.
    $conversation = Model::withoutEvents(fn () => F::conversation($connection, $contact));
    F::reply($conversation, $owner);

    expect(Lead::count())->toBe(0);

    EnsureLeadForConversation::dispatchSync($conversation->id);

    expect(F::lead($contact)->stage->name)->toBe('Atendidos');
});

it('opens no lead for an e-mail conversation', function () {
    $owner = F::owner();

    F::conversation(F::connection($owner, Channel::Email), F::contact($owner, 'noreply@cloudflare.com', Channel::Email));

    expect(Lead::count())->toBe(0);
});

it('offers the open stages after the first one, and refuses anything else', function () {
    $owner = F::owner();
    $stages = F::stages($owner->tenant_id);
    $foreign = F::stages(F::owner()->tenant_id);

    $response = $this->actingAs($owner)->getJson('/api/lead-settings')->assertOk();

    expect($response->json('data.attended_stage_id'))->toBe($stages['Atendidos']->id)
        ->and(collect($response->json('data.attended_stage_options'))->pluck('name')->all())
        ->toBe(['Atendidos', 'Qualificação', 'Proposta', 'Negociação']);

    foreach ([$stages['Novo contato'], $stages['Cliente'], $foreign['Atendidos']] as $stage) {
        $this->actingAs($owner)
            ->putJson('/api/lead-settings', ['attended_stage_id' => $stage->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('attended_stage_id');
    }

    $this->actingAs($owner)
        ->putJson('/api/lead-settings', ['attended_stage_id' => $stages['Qualificação']->id])
        ->assertOk()
        ->assertJsonPath('data.attended_stage_id', $stages['Qualificação']->id);
});

it('switches the rule off when its stage is deleted', function () {
    $owner = F::owner();
    $stages = F::stages($owner->tenant_id);
    $atendidos = $stages['Atendidos'];

    $this->actingAs($owner)
        ->deleteJson("/api/lead-pipelines/{$atendidos->pipeline_id}/stages/{$atendidos->id}")
        ->assertOk();

    expect(LeadSettings::for($owner->tenant->fresh())->attendedStageId)->toBeNull();
});

it('gives existing funnels an Atendidos column once, and only switches the rule on where nobody decided', function () {
    $owner = F::owner();
    attendedLegacyFunnel($owner->tenant);
    $owner->tenant->forceFill(['lead_settings' => ['auto_close_days' => 21]])->save();

    $declined = F::owner();
    attendedLegacyFunnel($declined->tenant);
    $declined->tenant->forceFill(['lead_settings' => ['attended_stage_id' => null]])->save();

    $migration = require database_path('migrations/2026_09_15_000100_add_attended_stage_to_lead_pipelines.php');
    $migration->up();
    $migration->up();

    $stages = LeadPipeline::where('tenant_id', $owner->tenant_id)->first()->stages()->orderBy('position')->get();
    $settings = $owner->tenant->fresh()->lead_settings;

    expect($stages->pluck('name')->all())->toBe(['Novo contato', 'Atendidos', 'Qualificação', 'Proposta', 'Negociação', 'Cliente', 'Perdido'])
        ->and($settings['attended_stage_id'])->toBe($stages->firstWhere('name', 'Atendidos')->id)
        ->and($settings['auto_close_days'])->toBe(21)
        ->and($declined->tenant->fresh()->lead_settings['attended_stage_id'])->toBeNull();
});

it('backfills answered leads and removes the e-mail leads nobody touched', function () {
    $owner = F::owner();
    $whatsapp = F::connection($owner);
    $mailbox = F::connection($owner, Channel::Email);

    // Answered before the rule existed.
    $answered = F::contact($owner, '5511900000010');
    $answeredConversation = F::conversation($whatsapp, $answered);
    LeadAttendance::withoutAdvancing(fn () => F::reply($answeredConversation, $owner));

    $waiting = F::contact($owner, '5511900000011');
    F::conversation($whatsapp, $waiting);

    $robot = F::emailLead($owner, $mailbox, 'noreply@cloudflare.com');
    $client = F::emailLead($owner, $mailbox, 'compras@cliente.com');
    $client->update(['owner_id' => $owner->id]);

    $this->artisan('leads:backfill-attended', ['--dry-run' => true])->assertSuccessful();

    expect(F::lead($answered)->stage->name)->toBe('Novo contato')
        ->and(Lead::count())->toBe(4);

    $this->artisan('leads:backfill-attended')->assertSuccessful();

    expect(F::lead($answered)->stage->name)->toBe('Atendidos')
        ->and(F::lead($waiting)->stage->name)->toBe('Novo contato')
        ->and(Lead::find($robot->id))->toBeNull()
        ->and(Lead::find($client->id))->not->toBeNull()
        // Deleting a card never takes the conversation with it.
        ->and(Conversation::where('contact_id', $robot->contact_id)->exists())->toBeTrue();

    // Nothing left to do on a second run.
    $this->artisan('leads:backfill-attended')->assertSuccessful();
    expect(Lead::count())->toBe(3);
});

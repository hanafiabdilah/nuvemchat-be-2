<?php

use App\Enums\Lead\LeadSource;
use App\Enums\Lead\LeadStatus;
use App\Jobs\EnsureLeadForConversation;
use App\Models\Lead;
use App\Models\LeadStage;
use App\Models\User;
use App\Services\Lead\LeadResolver;
use App\Services\Lead\PipelineProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Spatie\Permission\Models\Permission;
use Tests\Support\LeadAttendanceFixtures as F;
use Tests\Support\LeadIntakeFixtures as Intake;

uses(RefreshDatabase::class);

beforeEach(function () {
    Event::fake();
});

function apiOnlyOwner(array $settings = ['api_only' => true]): User
{
    $owner = F::owner();
    $owner->givePermissionTo(Permission::findOrCreate('leads.create', 'web'));
    app(PipelineProvisioner::class)->ensureDefault($owner->tenant_id);
    $owner->tenant->forceFill(['lead_settings' => $settings])->save();

    return $owner->fresh();
}

test('a funnel fed only by the API takes no card from a new conversation', function () {
    $owner = apiOnlyOwner();
    $conversation = F::conversation(F::connection($owner), F::contact($owner, '5511900000001'));
    F::inbound($conversation);

    EnsureLeadForConversation::dispatchSync($conversation->id);

    expect(Lead::count())->toBe(0);
});

test('nor one added by hand, and the refusal says why', function () {
    $owner = apiOnlyOwner();
    $contact = F::contact($owner, '5511900000002');

    $this->actingAs($owner)->postJson('/api/leads', ['contact_id' => $contact->id])
        ->assertStatus(422)
        ->assertJsonPath('code', 'leads_api_only');

    expect(Lead::count())->toBe(0);
});

test('the API still gets its leads in', function () {
    $owner = Intake::owner();
    Intake::connection($owner);
    app(PipelineProvisioner::class)->ensureDefault($owner->tenant_id);
    $owner->tenant->forceFill(['lead_settings' => ['api_only' => true, 'auto_create' => false]])->save();

    $this->withHeaders(['X-Api-Key' => Intake::key($owner)])
        ->postJson('/api/v1/leads', ['name' => 'Maria Souza', 'phone' => '5511987654321'])
        ->assertCreated();

    expect(Lead::sole()->source)->toBe(LeadSource::Api);
});

test('the setting is saved and read back', function () {
    $owner = apiOnlyOwner([]);

    $this->actingAs($owner)->putJson('/api/lead-settings', ['api_only' => true])
        ->assertOk()
        ->assertJsonPath('data.api_only', true);

    $this->actingAs($owner)->getJson('/api/lead-settings')->assertJsonPath('data.api_only', true);
});

test('closing a stage in bulk retires every open card in it and nothing else', function () {
    $owner = apiOnlyOwner([]);
    $resolver = app(LeadResolver::class);

    $leads = collect(['5511900000010', '5511900000011', '5511900000012'])
        ->map(fn (string $phone) => $resolver->open(F::contact($owner, $phone), null, LeadSource::Manual));

    $first = LeadStage::find($leads[0]->stage_id);
    $later = LeadStage::where('pipeline_id', $first->pipeline_id)->where('kind', 'open')->where('position', '>', $first->position)->orderBy('position')->first();
    $leads[2]->moveToStage($later);

    $this->actingAs($owner)->postJson('/api/leads/bulk-close', ['stage_id' => $first->id, 'lost_reason' => 'Limpeza'])
        ->assertOk()
        ->assertJsonPath('closed', 2);

    expect($leads[0]->fresh()->status)->toBe(LeadStatus::Lost)
        ->and($leads[0]->fresh()->lost_reason)->toBe('Limpeza')
        ->and($leads[1]->fresh()->status)->toBe(LeadStatus::Lost)
        ->and($leads[2]->fresh()->status)->toBe(LeadStatus::Open);
});

test('bulk close is refused for another workspace\'s stage and without permission', function () {
    $owner = apiOnlyOwner([]);
    $lead = app(LeadResolver::class)->open(F::contact($owner, '5511900000020'), null, LeadSource::Manual);

    $this->actingAs(apiOnlyOwner([]))->postJson('/api/leads/bulk-close', ['stage_id' => $lead->stage_id])->assertNotFound();

    $viewer = User::factory()->create(['tenant_id' => $owner->tenant_id]);
    $viewer->givePermissionTo(Permission::findOrCreate('leads.view', 'web'));
    $this->actingAs($viewer)->postJson('/api/leads/bulk-close', ['stage_id' => $lead->stage_id])->assertForbidden();

    expect($lead->fresh()->status)->toBe(LeadStatus::Open);
});

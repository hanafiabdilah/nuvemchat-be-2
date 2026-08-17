<?php

use App\Enums\Lead\LeadStatus;
use App\Enums\Lead\StageKind;
use App\Models\Lead;
use App\Services\Lead\LeadSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Put a lead in the world and age it, so the sweep has something to find.
 * Ageing has to touch the three columns the query reads together — created,
 * last heard from, last moved — because "quiet" means all of them.
 */
function ageLead(Lead $lead, int $days): Lead
{
    $when = now()->subDays($days);

    $lead->forceFill([
        'created_at' => $when,
        'last_inbound_at' => $when,
        'stage_changed_at' => $when,
    ])->save();

    return $lead->fresh();
}

function staleTestLead(App\Models\User $user, string $externalId = '5511999999999'): Lead
{
    $connection = leadTestConnection($user);
    $contact = leadTestContact($user, $externalId);
    leadTestConversation($connection, $contact);

    return Lead::where('contact_id', $contact->id)->firstOrFail();
}

test('an untouched lead is closed once past the tenant window', function () {
    leadTestFakeEvents();
    $user = leadTestUser();
    $lead = ageLead(staleTestLead($user), 40);

    $this->artisan('leads:close-stale')->assertSuccessful();

    $lead = $lead->fresh();

    expect($lead->status)->toBe(LeadStatus::Lost)
        ->and($lead->lost_reason)->toBe('Sem resposta')
        ->and($lead->stage->kind)->toBe(StageKind::Lost);

    // Attributed to the system, not to whoever happened to be logged in — an
    // unexplained closure months later has to be explainable.
    $event = $lead->stageEvents()->reorder()->latest('id')->first();
    expect($event->user_id)->toBeNull();
});

test('a lead inside the window is left alone', function () {
    leadTestFakeEvents();
    $user = leadTestUser();
    $lead = ageLead(staleTestLead($user), 10);

    $this->artisan('leads:close-stale')->assertSuccessful();

    expect($lead->fresh()->status)->toBe(LeadStatus::Open);
});

test('the window is the tenant own number', function () {
    leadTestFakeEvents();
    $user = leadTestUser();
    $lead = ageLead(staleTestLead($user), 10);

    $user->tenant->forceFill(['lead_settings' => ['auto_close_days' => 7]])->save();

    $this->artisan('leads:close-stale')->assertSuccessful();

    expect($lead->fresh()->status)->toBe(LeadStatus::Lost);
});

test('a tenant can switch the sweep off entirely', function () {
    leadTestFakeEvents();
    $user = leadTestUser();
    $lead = ageLead(staleTestLead($user), 400);

    $user->tenant->forceFill(['lead_settings' => ['auto_close_enabled' => false]])->save();

    $this->artisan('leads:close-stale')->assertSuccessful();

    expect($lead->fresh()->status)->toBe(LeadStatus::Open);
});

test('a lead an agent has worked is spared by default', function () {
    leadTestFakeEvents();
    $user = leadTestUser();
    $lead = staleTestLead($user);

    // Advanced past the first column: a human decided this one was worth
    // pursuing, so the default sweep must not quietly retire it.
    $proposta = $lead->pipeline->stages()->where('name', 'Proposta')->firstOrFail();
    $lead->moveToStage($proposta, $user);
    $lead = ageLead($lead, 90);

    $this->artisan('leads:close-stale')->assertSuccessful();
    expect($lead->fresh()->status)->toBe(LeadStatus::Open);

    // …unless the tenant asks for it.
    $user->tenant->forceFill(['lead_settings' => ['auto_close_engaged' => true]])->save();

    $this->artisan('leads:close-stale')->assertSuccessful();
    expect($lead->fresh()->status)->toBe(LeadStatus::Lost);
});

test('a lead the customer wrote to recently is not quiet', function () {
    leadTestFakeEvents();
    $user = leadTestUser();
    $lead = ageLead(staleTestLead($user), 90);

    // Old card, old stage move — but they messaged this morning, so the sale
    // is alive whatever the other two columns say.
    $lead->forceFill(['last_inbound_at' => now()->subHours(2)])->save();

    $this->artisan('leads:close-stale')->assertSuccessful();

    expect($lead->fresh()->status)->toBe(LeadStatus::Open);
});

test('dry run reports without changing anything', function () {
    leadTestFakeEvents();
    $user = leadTestUser();
    $lead = ageLead(staleTestLead($user), 40);

    $this->artisan('leads:close-stale --dry-run')->assertSuccessful();

    expect($lead->fresh()->status)->toBe(LeadStatus::Open);
});

test('one tenant window never reaches another tenant leads', function () {
    leadTestFakeEvents();
    $sweeps = leadTestUser();
    $spared = leadTestUser();

    $sweptLead = ageLead(staleTestLead($sweeps), 40);
    $sparedLead = ageLead(staleTestLead($spared, '5511888888888'), 40);

    $spared->tenant->forceFill(['lead_settings' => ['auto_close_enabled' => false]])->save();

    $this->artisan('leads:close-stale')->assertSuccessful();

    expect($sweptLead->fresh()->status)->toBe(LeadStatus::Lost)
        ->and($sparedLead->fresh()->status)->toBe(LeadStatus::Open);
});

test('settings round-trip through the API and clamp out-of-range windows', function () {
    leadTestFakeEvents();
    $user = leadTestUser();

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/lead-settings')
        ->assertOk()
        ->assertJsonPath('data.auto_close_enabled', true)
        ->assertJsonPath('data.auto_close_days', LeadSettings::DEFAULT_AUTO_CLOSE_DAYS);

    // Out of range is refused rather than silently coerced — a workspace that
    // typed 2000 days meant something, and should be told it cannot have it.
    $this->actingAs($user, 'sanctum')
        ->putJson('/api/lead-settings', ['auto_close_days' => 2000])
        ->assertStatus(422);

    $this->actingAs($user, 'sanctum')
        ->putJson('/api/lead-settings', ['auto_close_days' => 14])
        ->assertOk()
        ->assertJsonPath('data.auto_close_days', 14)
        // A partial save must not reset the knobs it did not mention.
        ->assertJsonPath('data.auto_create', true);
});

test('turning auto-create off stops new conversations opening cards', function () {
    leadTestFakeEvents();
    $user = leadTestUser();
    $user->tenant->forceFill(['lead_settings' => ['auto_create' => false]])->save();

    $connection = leadTestConnection($user);
    $contact = leadTestContact($user);
    leadTestConversation($connection, $contact);

    expect(Lead::where('contact_id', $contact->id)->exists())->toBeFalse();
});

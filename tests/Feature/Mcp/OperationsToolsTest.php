<?php

use App\Enums\Broadcast\ContentType;
use App\Enums\Broadcast\RecipientStatus;
use App\Enums\Broadcast\Status;
use App\Enums\Lead\LeadStatus;
use App\Enums\Lead\StageKind;
use App\Jobs\RunBroadcastJob;
use App\Models\AuditLog;
use App\Models\Broadcast;
use App\Models\BroadcastRecipient;
use App\Models\Lead;
use App\Models\LeadStage;
use App\Models\User;
use App\Services\Mcp\Scopes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\BroadcastFixtures;
use Tests\Support\McpFixtures;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['mcp.enabled' => true]);
    // Resuming a campaign dispatches its pump; nothing here should send.
    Queue::fake();
});

// Campaigns, statistics, the live monitor and leads over MCP. Helpers carry an
// `ops` prefix: Pest loads every test file into one process, so a generic name
// here would collide with another file's.
function opsUser(array $permissions, array $features = ['mcp' => true, 'statistics' => true, 'crm' => true]): User
{
    return McpFixtures::user($permissions, $features);
}

function opsCampaign(User $user, Status $status = Status::Running, array $recipients = []): Broadcast
{
    $connection = BroadcastFixtures::connection($user);

    $campaign = Broadcast::create([
        'tenant_id' => $user->tenant_id,
        'connection_id' => $connection->id,
        'created_by' => $user->id,
        'name' => 'Black Friday',
        'status' => $status,
        'content_type' => ContentType::Template,
        'payload' => ['template_name' => 'promo', 'language' => 'pt_BR', 'components' => []],
        'rate_per_minute' => 60,
    ]);

    foreach ($recipients as $i => [$state, $error]) {
        BroadcastRecipient::create([
            'broadcast_id' => $campaign->id,
            'address' => '55119999900'.$i,
            'status' => $state,
            'error' => $error,
        ]);
    }

    $campaign->forceFill([
        'total_recipients' => count($recipients),
        'failed_count' => collect($recipients)->where(0, RecipientStatus::Failed)->count(),
    ])->save();

    return $campaign->fresh();
}

function opsNames($test, string $token): \Illuminate\Support\Collection
{
    return collect(McpFixtures::call($test, $token, 'tools/list')->json('result.tools'))->pluck('name');
}

// ─────────────────────────────── Visibility ───────────────────────────────

it('offers the new tools only to the scopes and permissions that reach them', function () {
    $user = opsUser(['broadcasts.view', 'statistics.tenant.view', 'leads.view']);
    [, $token] = McpFixtures::connect($user, [Scopes::CAMPAIGNS_READ, Scopes::STATISTICS_READ, Scopes::LEADS_READ]);

    $names = opsNames($this, $token);

    expect($names)->toContain('list_campaigns', 'get_campaign', 'get_statistics', 'list_leads', 'find_contacts')
        // No scope for these.
        ->not->toContain('get_live')
        ->not->toContain('pause_campaign')
        ->not->toContain('update_lead')
        // Scope yes, permission no.
        ->not->toContain('get_agent_statistics');
});

it('lets a write scope read, the same way flows.write reads flows', function () {
    $user = opsUser(['broadcasts.view', 'broadcasts.send', 'leads.view', 'leads.update']);
    [, $token] = McpFixtures::connect($user, [Scopes::CAMPAIGNS_WRITE, Scopes::LEADS_WRITE]);

    expect(opsNames($this, $token))->toContain('list_campaigns', 'pause_campaign', 'list_leads', 'update_lead');
});

it('hides lead tools when the plan has no CRM, whatever the scope says', function () {
    $user = opsUser(['leads.view'], ['mcp' => true]);
    config(['services.billing.enforce' => true]);
    [, $token] = McpFixtures::connect($user, [Scopes::LEADS_READ]);

    expect(opsNames($this, $token))->not->toContain('list_leads');
});

it('never offers a tool that creates or starts a campaign, or deletes a lead', function () {
    $user = opsUser(['broadcasts.view', 'broadcasts.create', 'broadcasts.send', 'broadcasts.delete', 'leads.view', 'leads.create', 'leads.update', 'leads.delete']);
    [, $token] = McpFixtures::connect($user, Scopes::all());

    $names = opsNames($this, $token);

    expect($names->filter(fn ($n) => preg_match('/^(create|start|delete)_campaign|^delete_lead/', $n)))->toBeEmpty();
});

// ─────────────────────────────── Campaigns ───────────────────────────────

it('lists only this workspace\'s campaigns', function () {
    $user = opsUser(['broadcasts.view']);
    [, $token] = McpFixtures::connect($user, [Scopes::CAMPAIGNS_READ]);

    opsCampaign($user);
    opsCampaign(opsUser(['broadcasts.view']));

    $result = McpFixtures::tool($this, $token, 'list_campaigns');

    expect($result['structuredContent']['total'])->toBe(1)
        ->and($result['structuredContent']['campaigns'][0]['content']['template_name'])->toBe('promo');
});

it('explains why recipients failed', function () {
    $user = opsUser(['broadcasts.view']);
    [, $token] = McpFixtures::connect($user, [Scopes::CAMPAIGNS_READ]);

    $campaign = opsCampaign($user, Status::Completed, [
        [RecipientStatus::Failed, 'Número inválido'],
        [RecipientStatus::Failed, 'Número inválido'],
        [RecipientStatus::Sent, null],
    ]);

    $result = McpFixtures::tool($this, $token, 'get_campaign', ['campaign_id' => $campaign->id]);

    expect($result['structuredContent']['failure_reasons'])->toBe([['reason' => 'Número inválido', 'count' => 2]]);

    $failed = McpFixtures::tool($this, $token, 'list_campaign_recipients', ['campaign_id' => $campaign->id, 'status' => 'failed']);

    expect($failed['structuredContent']['total'])->toBe(2);
});

it('pauses and resumes a campaign through the same service as the dashboard, and audits it', function () {
    $user = opsUser(['broadcasts.view', 'broadcasts.send']);
    [, $token] = McpFixtures::connect($user, [Scopes::CAMPAIGNS_WRITE]);

    $campaign = opsCampaign($user, Status::Running, [[RecipientStatus::Pending, null]]);

    $paused = McpFixtures::tool($this, $token, 'pause_campaign', ['campaign_id' => $campaign->id]);

    expect($paused['isError'])->toBeFalse()
        ->and($campaign->fresh()->status)->toBe(Status::Paused)
        ->and(AuditLog::where('action', 'mcp.campaign.paused')->exists())->toBeTrue();

    McpFixtures::tool($this, $token, 'resume_campaign', ['campaign_id' => $campaign->id]);

    expect($campaign->fresh()->status)->toBe(Status::Running);
    Queue::assertPushed(RunBroadcastJob::class);
});

it('passes the service\'s refusal to the model instead of failing the call', function () {
    $user = opsUser(['broadcasts.view', 'broadcasts.send']);
    [, $token] = McpFixtures::connect($user, [Scopes::CAMPAIGNS_WRITE]);

    $campaign = opsCampaign($user, Status::Completed);

    $result = McpFixtures::tool($this, $token, 'pause_campaign', ['campaign_id' => $campaign->id]);

    expect($result['isError'])->toBeTrue()
        ->and($result['content'][0]['text'])->toContain('Only a running campaign can be paused.');
});

it('cannot touch another workspace\'s campaign', function () {
    $user = opsUser(['broadcasts.view', 'broadcasts.send']);
    [, $token] = McpFixtures::connect($user, [Scopes::CAMPAIGNS_WRITE]);

    $theirs = opsCampaign(opsUser(['broadcasts.view']));

    $result = McpFixtures::tool($this, $token, 'cancel_campaign', ['campaign_id' => $theirs->id]);

    expect($result['isError'])->toBeTrue()
        ->and($theirs->fresh()->status)->toBe(Status::Running);
});

// ─────────────────────────── Statistics & Live ───────────────────────────

it('reads a statistics section and echoes the window it measured', function () {
    $user = opsUser(['statistics.tenant.view']);
    [, $token] = McpFixtures::connect($user, [Scopes::STATISTICS_READ]);

    $result = McpFixtures::tool($this, $token, 'get_statistics', [
        'section' => 'overview', 'from' => '2026-09-01', 'to' => '2026-09-07', 'timezone' => 'America/Sao_Paulo',
    ]);

    expect($result['isError'])->toBeFalse()
        ->and($result['structuredContent']['range']['timezone'])->toBe('America/Sao_Paulo')
        ->and($result['structuredContent']['range']['from'])->toStartWith('2026-09-01T03:00:00');
});

it('refuses a date expression the same way the dashboard does', function () {
    $user = opsUser(['statistics.tenant.view']);
    [, $token] = McpFixtures::connect($user, [Scopes::STATISTICS_READ]);

    $result = McpFixtures::tool($this, $token, 'get_statistics', ['section' => 'volume', 'from' => '-500 years']);

    expect($result['isError'])->toBeTrue();
});

it('shows the live monitor, and leaves out the roster without the agents permission', function () {
    $user = opsUser(['statistics.tenant.view']);
    [, $token] = McpFixtures::connect($user, [Scopes::LIVE_READ]);

    $result = McpFixtures::tool($this, $token, 'get_live');

    expect($result['isError'])->toBeFalse()
        ->and($result['structuredContent'])->toHaveKeys(['pulse', 'activity', 'events', 'cursor'])
        ->and($result['structuredContent'])->not->toHaveKey('agents');
});

// ───────────────────────────────── Leads ─────────────────────────────────

it('opens a lead, refuses a second one, then moves it to won', function () {
    $user = opsUser(['leads.view', 'leads.create', 'leads.update']);
    [, $token] = McpFixtures::connect($user, [Scopes::LEADS_WRITE]);

    $contact = BroadcastFixtures::contact($user, '5511988887777', 'Maria');

    $found = McpFixtures::tool($this, $token, 'find_contacts', ['search' => 'Mar']);
    expect($found['structuredContent']['contacts'][0]['id'])->toBe($contact->id);

    $created = McpFixtures::tool($this, $token, 'create_lead', ['contact_id' => $contact->id, 'title' => 'Plano anual', 'value' => 1500]);
    expect($created['isError'])->toBeFalse();
    $leadId = $created['structuredContent']['id'];

    $again = McpFixtures::tool($this, $token, 'create_lead', ['contact_id' => $contact->id]);
    expect($again['isError'])->toBeTrue()
        ->and($again['content'][0]['text'])->toContain("#{$leadId}");

    $pipelines = McpFixtures::tool($this, $token, 'list_lead_pipelines');
    $won = collect($pipelines['structuredContent']['pipelines'][0]['stages'])->firstWhere('kind', 'won');

    $moved = McpFixtures::tool($this, $token, 'update_lead', ['lead_id' => $leadId, 'stage_id' => $won['id'], 'value' => 3000]);

    $lead = Lead::find($leadId);

    expect($moved['isError'])->toBeFalse()
        ->and($lead->status)->toBe(LeadStatus::Won)
        ->and((float) $lead->value)->toBe(3000.0)
        // The move leaves the same trace as the drag, with the person as actor.
        ->and($lead->stageEvents()->where('user_id', $user->id)->exists())->toBeTrue();
});

it('refuses a lost reason on a stage that is not lost, and writes nothing', function () {
    $user = opsUser(['leads.view', 'leads.create', 'leads.update']);
    [, $token] = McpFixtures::connect($user, [Scopes::LEADS_WRITE]);

    $contact = BroadcastFixtures::contact($user, '5511977776666', 'João');
    $leadId = McpFixtures::tool($this, $token, 'create_lead', ['contact_id' => $contact->id])['structuredContent']['id'];

    $won = LeadStage::whereHas('pipeline', fn ($q) => $q->where('tenant_id', $user->tenant_id))
        ->where('kind', StageKind::Won)->first();

    $result = McpFixtures::tool($this, $token, 'update_lead', [
        'lead_id' => $leadId, 'title' => 'Mudou', 'stage_id' => $won->id, 'lost_reason' => 'Preço',
    ]);

    expect($result['isError'])->toBeTrue()
        ->and(Lead::find($leadId)->title)->toBeNull();
});

it('will not hand a lead to someone from another workspace', function () {
    $user = opsUser(['leads.view', 'leads.create', 'leads.update']);
    [, $token] = McpFixtures::connect($user, [Scopes::LEADS_WRITE]);
    $stranger = opsUser(['leads.view']);

    $contact = BroadcastFixtures::contact($user, '5511966665555', 'Ana');

    $result = McpFixtures::tool($this, $token, 'create_lead', ['contact_id' => $contact->id, 'owner_id' => $stranger->id]);

    expect($result['isError'])->toBeTrue()
        ->and(Lead::where('tenant_id', $user->tenant_id)->count())->toBe(0);
});

it('does not see another workspace\'s leads', function () {
    $user = opsUser(['leads.view']);
    [, $token] = McpFixtures::connect($user, [Scopes::LEADS_READ]);

    $other = opsUser(['leads.view', 'leads.create']);
    [, $otherToken] = McpFixtures::connect($other, [Scopes::LEADS_WRITE]);
    $contact = BroadcastFixtures::contact($other, '5511955554444', 'Pedro');
    $theirs = McpFixtures::tool($this, $otherToken, 'create_lead', ['contact_id' => $contact->id])['structuredContent']['id'];

    expect(McpFixtures::tool($this, $token, 'list_leads')['structuredContent']['total'])->toBe(0)
        ->and(McpFixtures::tool($this, $token, 'get_lead', ['lead_id' => $theirs])['isError'])->toBeTrue();
});

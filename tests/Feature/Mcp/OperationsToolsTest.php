<?php

use App\Enums\Broadcast\ContentType;
use App\Enums\Broadcast\RecipientStatus;
use App\Enums\Broadcast\Status;
use App\Jobs\RunBroadcastJob;
use App\Models\AuditLog;
use App\Models\Broadcast;
use App\Models\BroadcastRecipient;
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

// Campaigns, statistics and the live monitor over MCP. Helpers carry an
// `ops` prefix: Pest loads every test file into one process, so a generic name
// here would collide with another file's.
function opsUser(array $permissions, array $features = ['mcp' => true, 'statistics' => true]): User
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
    $user = opsUser(['broadcasts.view', 'statistics.tenant.view']);
    [, $token] = McpFixtures::connect($user, [Scopes::CAMPAIGNS_READ, Scopes::STATISTICS_READ]);

    $names = opsNames($this, $token);

    expect($names)->toContain('list_campaigns', 'get_campaign', 'get_statistics')
        // No scope for these.
        ->not->toContain('get_live')
        ->not->toContain('pause_campaign')
        // Scope yes, permission no.
        ->not->toContain('get_agent_statistics');
});

it('lets a write scope read, the same way flows.write reads flows', function () {
    $user = opsUser(['broadcasts.view', 'broadcasts.send']);
    [, $token] = McpFixtures::connect($user, [Scopes::CAMPAIGNS_WRITE]);

    expect(opsNames($this, $token))->toContain('list_campaigns', 'pause_campaign');
});

it('never offers a tool that creates or starts a campaign', function () {
    $user = opsUser(['broadcasts.view', 'broadcasts.create', 'broadcasts.send', 'broadcasts.delete']);
    [, $token] = McpFixtures::connect($user, Scopes::all());

    $names = opsNames($this, $token);

    expect($names->filter(fn ($n) => preg_match('/^(create|start|delete)_campaign/', $n)))->toBeEmpty();
});

it('hides campaign tools when the plan has no campaigns, whatever the scope says', function () {
    // Campaigns were split out of `chat` into a plan feature of their own; MCP
    // must not stay a side door to them.
    $user = opsUser(['broadcasts.view', 'broadcasts.send'], ['mcp' => true, 'chat' => true, 'crm' => true]);
    config(['services.billing.enforce' => true]);
    [, $token] = McpFixtures::connect($user, [Scopes::CAMPAIGNS_WRITE]);

    expect(opsNames($this, $token))->not->toContain('list_campaigns')->not->toContain('pause_campaign');
});

it('no longer offers any lead tool, and no longer issues the lead scopes', function () {
    $user = opsUser(['broadcasts.view', 'statistics.tenant.view']);
    [, $token] = McpFixtures::connect($user, Scopes::all());

    expect(opsNames($this, $token)->filter(fn ($n) => str_contains($n, 'lead') || $n === 'find_contacts'))->toBeEmpty()
        ->and(Scopes::parse('mcp:leads.read mcp:leads.write'))->toBe(Scopes::fallback());
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

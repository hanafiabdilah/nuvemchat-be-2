<?php

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status as ConnectionStatus;
use App\Enums\Conversation\Status as ConversationStatus;
use App\Enums\Message\MessageType;
use App\Enums\Message\SenderType;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Flow;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Support\McpFixtures;

/*
 * The Leads module and the outbound webhooks are gone. What matters is what the
 * removal did NOT touch: conversations, contacts and messages stay; flows keep
 * routing where they did; JSON settings lose one key and nothing else.
 *
 * The test database is migrated already, so the lead-era schema is rebuilt
 * here first — just enough of it for the migration to have something to undo.
 *
 * ⚠️ Every test here runs inside RefreshDatabase's transaction, which is
 * exactly where dropping `conversations.lead_id` on SQLite would cascade into
 * the messages. So the main test leaves that column out, and the last test
 * checks that the migration refuses rather than doing it.
 */

uses(RefreshDatabase::class);

function leadsRemovalMigration(): object
{
    return require database_path('migrations/2026_10_29_100100_remove_lead_funnel_and_outbound_webhooks.php');
}

function leadsRemovalRebuildSchema(): void
{
    Schema::create('lead_pipelines', function (Blueprint $table) {
        $table->id();
        $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
    });
    Schema::create('lead_stages', function (Blueprint $table) {
        $table->id();
        $table->foreignId('pipeline_id')->constrained('lead_pipelines')->cascadeOnDelete();
    });
    Schema::create('leads', function (Blueprint $table) {
        $table->id();
        $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
        $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
        $table->foreignId('stage_id')->constrained('lead_stages')->restrictOnDelete();
    });
    Schema::create('lead_stage_events', function (Blueprint $table) {
        $table->id();
        $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
    });
    Schema::create('lead_intakes', function (Blueprint $table) {
        $table->id();
        $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
        $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();
    });
    Schema::create('webhook_endpoints', function (Blueprint $table) {
        $table->id();
        $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
    });
    Schema::create('webhook_deliveries', function (Blueprint $table) {
        $table->id();
        $table->foreignId('webhook_endpoint_id')->constrained()->cascadeOnDelete();
    });
    Schema::table('tenants', function (Blueprint $table) {
        $table->json('lead_settings')->nullable();
    });
}

/** A flow node written the way the database holds it, type and all. */
function leadsRemovalNode(int $flowId, string $type, int $x = 0): int
{
    return DB::table('flow_nodes')->insertGetId([
        'flow_id' => $flowId,
        'type' => $type,
        'data' => json_encode($type === 'message' ? ['messages' => [['message_type' => 'text', 'body' => 'Oi']]] : []),
        'position_x' => $x,
        'position_y' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function leadsRemovalEdge(int $from, int $to, ?string $value = null): int
{
    return DB::table('flow_edges')->insertGetId([
        'source_node_id' => $from,
        'target_node_id' => $to,
        'condition_value' => $value,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function leadsRemovalJob(string $class): void
{
    DB::table('jobs')->insert([
        'queue' => 'default',
        'payload' => json_encode(['displayName' => "App\\Jobs\\{$class}", 'job' => 'Illuminate\\Queue\\CallQueuedHandler@call']),
        'attempts' => 0,
        'available_at' => now()->timestamp,
        'created_at' => now()->timestamp,
    ]);
}

it('removes the funnel and the webhooks without touching anything else', function () {
    leadsRemovalRebuildSchema();

    $user = User::factory()->create();
    $tenant = Tenant::create(['user_id' => $user->id]);
    $user->forceFill(['tenant_id' => $tenant->id])->save();
    DB::table('tenants')->where('id', $tenant->id)->update([
        'lead_settings' => json_encode(['auto_create' => true]),
        'entitlement_overrides' => json_encode([
            'features' => ['crm' => true, 'catalog' => true],
            'quotas' => ['max_agents' => 3],
            'note' => 'Teste do funil',
        ]),
    ]);

    $connection = Connection::create([
        'tenant_id' => $tenant->id, 'channel' => Channel::WhatsappApiway, 'name' => 'WA',
        'color' => '#22c55e', 'status' => ConnectionStatus::Active,
    ]);
    $contact = Contact::create(['connection_id' => $connection->id, 'external_id' => '5511999990000', 'name' => 'Ana']);
    $conversations = collect(['5511999990000', '5511999990001'])->map(fn (string $phone) => Conversation::create([
        'contact_id' => $contact->id, 'connection_id' => $connection->id,
        'external_id' => $phone, 'status' => ConversationStatus::Active,
    ]));
    $conversations[0]->messages()->create([
        'external_id' => 'm1', 'sender_type' => SenderType::Incoming,
        'message_type' => MessageType::Text, 'body' => 'Olá', 'sent_at' => now(),
    ]);

    $pipelineId = DB::table('lead_pipelines')->insertGetId(['tenant_id' => $tenant->id]);
    $stageId = DB::table('lead_stages')->insertGetId(['pipeline_id' => $pipelineId]);
    $leadId = DB::table('leads')->insertGetId(['tenant_id' => $tenant->id, 'contact_id' => $contact->id, 'stage_id' => $stageId]);
    DB::table('lead_stage_events')->insert(['lead_id' => $leadId]);
    DB::table('lead_intakes')->insert(['lead_id' => $leadId, 'conversation_id' => $conversations[0]->id]);
    $endpointId = DB::table('webhook_endpoints')->insertGetId(['tenant_id' => $tenant->id]);
    DB::table('webhook_deliveries')->insert(['webhook_endpoint_id' => $endpointId]);

    // A flow with a lead node in the middle, and two chained before the end.
    $flow = Flow::create(['tenant_id' => $tenant->id, 'name' => 'Vendas']);
    $start = leadsRemovalNode($flow->id, 'start');
    $ask = leadsRemovalNode($flow->id, 'message', 300);
    $lead1 = leadsRemovalNode($flow->id, 'lead', 600);
    $thanks = leadsRemovalNode($flow->id, 'message', 900);
    $lead2 = leadsRemovalNode($flow->id, 'lead', 1200);
    $lead3 = leadsRemovalNode($flow->id, 'lead', 1500);
    $end = leadsRemovalNode($flow->id, 'status', 1800);
    leadsRemovalEdge($start, $ask);
    $askToLead = leadsRemovalEdge($ask, $lead1);
    leadsRemovalEdge($lead1, $thanks);
    leadsRemovalEdge($thanks, $lead2);
    leadsRemovalEdge($lead2, $lead3);
    leadsRemovalEdge($lead3, $end);

    // A flow that ended on a lead node.
    $other = Flow::create(['tenant_id' => $tenant->id, 'name' => 'Captura']);
    $otherStart = leadsRemovalNode($other->id, 'start');
    $otherAsk = leadsRemovalNode($other->id, 'message', 300);
    $otherLead = leadsRemovalNode($other->id, 'lead', 600);
    leadsRemovalEdge($otherStart, $otherAsk);
    leadsRemovalEdge($otherAsk, $otherLead);

    $parked = DB::table('flow_states')->insertGetId([
        'conversation_id' => $conversations[0]->id, 'flow_id' => $flow->id, 'current_node_id' => $lead1,
        'state_data' => json_encode([]), 'status' => 'running', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $stranded = DB::table('flow_states')->insertGetId([
        'conversation_id' => $conversations[1]->id, 'flow_id' => $other->id, 'current_node_id' => $otherLead,
        'state_data' => json_encode([]), 'status' => 'running', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $plan = Plan::create([
        'name' => 'Pro', 'slug' => 'pro-'.uniqid(), 'price_cents' => 4900, 'billing_cycle' => 'monthly',
        'features' => ['chat' => true, 'crm' => true, 'flow' => false], 'quotas' => ['max_connections' => 2],
    ]);
    $subscription = Subscription::create([
        'tenant_id' => $tenant->id, 'plan_id' => $plan->id, 'status' => 'active',
        'features_snapshot' => ['chat' => true, 'crm' => true], 'quotas_snapshot' => ['max_connections' => 2],
        'current_period_start' => now(), 'current_period_end' => now()->addMonth(),
    ]);

    $role = Role::findOrCreate('Vendas', 'web');
    $role->givePermissionTo([
        Permission::findOrCreate('leads.view', 'web'),
        Permission::findOrCreate('webhooks.manage', 'web'),
        Permission::findOrCreate('tags.create', 'web'),
    ]);

    [$mcp] = McpFixtures::connect($user, ['mcp:flows.read', 'mcp:leads.read', 'mcp:leads.write']);

    leadsRemovalJob('EnsureLeadForConversation');
    leadsRemovalJob('DeliverWebhook');
    leadsRemovalJob('RunAiAgentTurn');

    $before = [
        'conversations' => DB::table('conversations')->count(),
        'contacts' => DB::table('contacts')->count(),
        'messages' => DB::table('messages')->count(),
        'flows' => DB::table('flows')->count(),
    ];

    leadsRemovalMigration()->up();

    // Nothing outside the funnel went with it.
    expect([
        'conversations' => DB::table('conversations')->count(),
        'contacts' => DB::table('contacts')->count(),
        'messages' => DB::table('messages')->count(),
        'flows' => DB::table('flows')->count(),
    ])->toBe($before)
        ->and(Schema::hasColumn('tenants', 'lead_settings'))->toBeFalse()
        ->and(Schema::hasTable('webhook_events'))->toBeTrue();

    foreach (['lead_pipelines', 'lead_stages', 'leads', 'lead_stage_events', 'lead_intakes', 'webhook_endpoints', 'webhook_deliveries'] as $table) {
        expect(Schema::hasTable($table))->toBeFalse();
    }

    // Flows route where they did, minus the lead nodes.
    expect(DB::table('flow_nodes')->where('type', 'lead')->count())->toBe(0)
        ->and(DB::table('flow_edges')->where('id', $askToLead)->value('target_node_id'))->toBe($thanks)
        ->and(DB::table('flow_edges')->where('source_node_id', $thanks)->pluck('target_node_id')->all())->toBe([$end])
        ->and(DB::table('flow_edges')->where('source_node_id', $otherAsk)->count())->toBe(0)
        ->and(DB::table('flow_states')->where('id', $parked)->value('current_node_id'))->toBe($thanks)
        ->and(DB::table('flow_states')->where('id', $stranded)->value('current_node_id'))->toBeNull()
        // Readable through the model again: no row holds a type the enum lacks.
        ->and($flow->nodes()->get())->toHaveCount(4);

    // The `crm` key stays: the CRM is sold under it. Only the lead scopes go.
    expect($plan->fresh()->features)->toBe(['chat' => true, 'crm' => true, 'flow' => false])
        ->and($subscription->fresh()->features_snapshot)->toBe(['chat' => true, 'crm' => true])
        ->and($tenant->fresh()->entitlement_overrides)->toBe([
            'features' => ['crm' => true, 'catalog' => true],
            'quotas' => ['max_agents' => 3],
            'note' => 'Teste do funil',
        ])
        ->and($mcp->fresh()->scopes)->toBe(['mcp:flows.read']);

    // The permissions are gone; the role and its other permission stay.
    expect(Permission::whereIn('name', ['leads.view', 'webhooks.manage'])->count())->toBe(0)
        ->and($role->fresh()->permissions->pluck('name')->all())->toBe(['tags.create']);

    // Only jobs of deleted classes were dropped from the queue.
    expect(DB::table('jobs')->pluck('payload')->map(fn ($p) => json_decode($p, true)['displayName'])->all())
        ->toBe(['App\\Jobs\\RunAiAgentTurn']);
});

it('runs on a database that never had the funnel', function () {
    // A fresh install: the tables are already gone by the time it runs.
    leadsRemovalMigration()->up();

    expect(Schema::hasTable('leads'))->toBeFalse();
});

it('imports an old export, lead nodes cut out and the route kept', function () {
    $user = User::factory()->create();
    $tenant = Tenant::create(['user_id' => $user->id]);
    $user->forceFill(['tenant_id' => $tenant->id])->save();
    $role = Role::findOrCreate('importer-'.$tenant->id, 'web');
    $role->givePermissionTo(Permission::findOrCreate('flows.create', 'web'));
    $user->assignRole($role);

    $text = fn (string $body) => ['body' => $body, 'message_type' => 'text'];

    $this->actingAs($user->fresh(), 'sanctum')->postJson('/api/flows/import', [
        'format' => 'nuvemchat.flow',
        'version' => 1,
        'flow' => [
            'name' => 'Old export',
            'nodes' => [
                ['key' => 'n1', 'type' => 'start', 'data' => null, 'position_x' => 0, 'position_y' => 0],
                // `wait_for_reply: false` keeps the older wait upgrade out of it.
                ['key' => 'n2', 'type' => 'message', 'data' => $text('Oi') + ['wait_for_reply' => false], 'position_x' => 300, 'position_y' => 0],
                ['key' => 'n3', 'type' => 'lead', 'data' => ['stage_id' => 99], 'position_x' => 600, 'position_y' => 0],
                ['key' => 'n4', 'type' => 'message', 'data' => $text('Obrigado'), 'position_x' => 900, 'position_y' => 0],
            ],
            'edges' => [
                ['source_key' => 'n1', 'target_key' => 'n2', 'condition_value' => null],
                ['source_key' => 'n2', 'target_key' => 'n3', 'condition_value' => null],
                ['source_key' => 'n3', 'target_key' => 'n4', 'condition_value' => null],
            ],
        ],
    ])->assertCreated();

    $flow = Flow::where('name', 'Old export')->firstOrFail();
    $nodes = $flow->nodes()->get()->keyBy(fn ($node) => $node->data['body'] ?? $node->type->value);

    expect($nodes)->toHaveCount(3)
        ->and(DB::table('flow_edges')->where('source_node_id', $nodes['Oi']->id)->value('target_node_id'))
        ->toBe($nodes['Obrigado']->id);
});

it('refuses to rebuild conversations on SQLite inside a transaction, losing nothing', function () {
    leadsRemovalRebuildSchema();
    Schema::table('conversations', function (Blueprint $table) {
        $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
    });

    $user = User::factory()->create();
    $tenant = Tenant::create(['user_id' => $user->id]);
    $connection = Connection::create([
        'tenant_id' => $tenant->id, 'channel' => Channel::WhatsappApiway, 'name' => 'WA',
        'color' => '#22c55e', 'status' => ConnectionStatus::Active,
    ]);
    $contact = Contact::create(['connection_id' => $connection->id, 'external_id' => '5511999990009', 'name' => 'Bia']);
    $conversation = Conversation::create([
        'contact_id' => $contact->id, 'connection_id' => $connection->id,
        'external_id' => '5511999990009', 'status' => ConversationStatus::Active,
    ]);
    $conversation->messages()->create([
        'external_id' => 'm9', 'sender_type' => SenderType::Incoming,
        'message_type' => MessageType::Text, 'body' => 'Oi', 'sent_at' => now(),
    ]);

    expect(fn () => leadsRemovalMigration()->up())->toThrow(RuntimeException::class, 'inside a transaction');

    expect(DB::table('messages')->count())->toBe(1)
        ->and(Schema::hasColumn('conversations', 'lead_id'))->toBeTrue();
});

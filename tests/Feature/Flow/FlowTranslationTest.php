<?php

use App\Enums\Billing\BillingCycle;
use App\Enums\Billing\PaymentMethod;
use App\Enums\Billing\SubscriptionStatus;
use App\Enums\Flow\NodeType;
use App\Models\Flow;
use App\Models\FlowEdge;
use App\Models\Plan;
use App\Models\Setting;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AiAgentHub\AiAgentHubConfig;
use App\Services\Flow\FlowGraph;
use App\Services\FlowAssistant\FlowAssistantConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HubRequest;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function translationUser(array $features = ['flow' => true, 'flow_assistant' => true], array $permissions = ['flows.create']): User
{
    $user = User::factory()->create();
    $tenant = Tenant::create(['user_id' => $user->id]);
    $user->forceFill(['tenant_id' => $tenant->id])->save();

    $plan = Plan::create([
        'name' => 'Pro', 'slug' => 'pro-'.uniqid(), 'price_cents' => 9990,
        'currency' => 'BRL', 'billing_cycle' => BillingCycle::Monthly, 'is_active' => true,
        'features' => $features, 'quotas' => [],
    ]);

    $subscription = Subscription::create([
        'tenant_id' => $tenant->id, 'plan_id' => $plan->id,
        'status' => SubscriptionStatus::Active, 'payment_method' => PaymentMethod::Pix,
        'billing_cycle' => BillingCycle::Monthly, 'price_cents' => 9990, 'quantity' => 1,
        'current_period_start' => now(), 'current_period_end' => now()->addMonth(),
        'quotas_snapshot' => [], 'features_snapshot' => $features,
    ]);
    $tenant->forceFill(['current_subscription_id' => $subscription->id])->save();

    $role = Role::findOrCreate('flow-translation-'.$tenant->id, 'web');

    foreach ($permissions as $permission) {
        $role->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }

    $user->assignRole($role);

    Setting::set(AiAgentHubConfig::KEY_TENANT_TOKEN, 'hub-tenant-token');
    Setting::set(FlowAssistantConfig::KEY_ENABLED, '1');
    Setting::set(FlowAssistantConfig::KEY_API_KEY, 'sk-platform-key');
    Setting::set(FlowAssistantConfig::KEY_AGENT_EXTERNAL_ID, FlowAssistantConfig::AGENT_EXTERNAL_ID);
    Setting::set(FlowAssistantConfig::KEY_HUB_AGENT_ID, 'hub-agent-1');
    Setting::set(FlowAssistantConfig::KEY_HUB_CREDENTIAL_ID, 'hub-cred-1');

    return $user->fresh();
}

/** start → message (two bubbles) → interactive (two buttons) → condition. */
function translationFlow(User $user): Flow
{
    $flow = Flow::create(['tenant_id' => $user->tenant_id, 'name' => 'Vendas']);
    $at = fn (int $x) => ['position_x' => $x, 'position_y' => 0];

    $start = $flow->nodes()->create(['type' => NodeType::Start, 'data' => null] + $at(0));
    $hello = $flow->nodes()->create(['type' => NodeType::Message, 'data' => ['messages' => [
        ['message_type' => 'text', 'body' => 'Olá {{contact.name}}!', 'delay' => 0],
        ['message_type' => 'audio', 'body' => '', 'attachment_url' => 'https://cdn.example.com/a.ogg', 'delay' => 3],
    ]]] + $at(280));
    $menu = $flow->nodes()->create(['type' => NodeType::Interactive, 'data' => [
        'interactive_type' => 'button',
        'body' => 'Posso enviar?',
        'buttons' => [['id' => 'yes', 'title' => 'Sim'], ['id' => 'no', 'title' => 'Não']],
    ]] + $at(560));
    $check = $flow->nodes()->create(['type' => NodeType::Condition, 'data' => [
        'field' => 'resposta', 'operator' => 'contains', 'value' => 'sim',
    ]] + $at(840));

    FlowEdge::create(['source_node_id' => $start->id, 'target_node_id' => $hello->id, 'condition_value' => null]);
    FlowEdge::create(['source_node_id' => $hello->id, 'target_node_id' => $menu->id, 'condition_value' => null]);
    FlowEdge::create(['source_node_id' => $menu->id, 'target_node_id' => $check->id, 'condition_value' => 'yes']);

    return $flow;
}

/**
 * A hub that "translates" each string it is handed through `$translate`
 * (returning null leaves that id out of the answer).
 */
function fakeTranslatingHub(callable $translate): void
{
    Http::fake(function (HubRequest $request) use ($translate) {
        if (! str_ends_with($request->url(), '/runs')) {
            return Http::response([]);
        }

        $content = $request->data()['message']['content'];
        $strings = json_decode(trim(substr($content, strrpos($content, 'Strings:') + 8)), true);
        $translations = [];

        foreach ($strings as $string) {
            if (($answer = $translate($string['text'], $string)) !== null) {
                $translations[$string['id']] = $answer;
            }
        }

        return Http::response([
            'id' => 'run-'.uniqid(),
            'status' => 'COMPLETED',
            'output' => ['message' => json_encode(['reply' => 'ok', 'flow' => null, 'translations' => $translations])],
        ]);
    });
}

test('translating creates a copy in the other language and leaves the flow alone', function () {
    $user = translationUser();
    $flow = translationFlow($user);
    $before = FlowGraph::export($flow);

    fakeTranslatingHub(fn (string $text) => [
        'Olá {{contact.name}}!' => 'Hello {{contact.name}}!',
        'Posso enviar?' => 'May I send it?',
        'Sim' => 'Yes',
        'Não' => 'No',
    ][$text] ?? null);

    Sanctum::actingAs($user);

    $response = $this->postJson("/api/flows/{$flow->id}/translate", ['language' => 'en'])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Vendas (English)')
        ->assertJsonPath('translated', 4)
        ->assertJsonPath('kept', 0)
        ->assertJsonPath('notes.media', 1)
        ->assertJsonPath('notes.conditions', 1);

    $copy = FlowGraph::export(Flow::findOrFail($response->json('data.id')));
    $data = collect($copy['nodes'])->pluck('data', 'type');

    expect(FlowGraph::export($flow->fresh()))->toBe($before)
        ->and($data['message']['messages'][0]['body'])->toBe('Hello {{contact.name}}!')
        ->and($data['message']['messages'][1]['attachment_url'])->toBe('https://cdn.example.com/a.ogg')
        ->and($data['interactive']['buttons'])->toBe([['id' => 'yes', 'title' => 'Yes'], ['id' => 'no', 'title' => 'No']])
        ->and($data['condition']['value'])->toBe('sim')
        ->and(collect($copy['edges'])->pluck('condition_value')->all())->toBe([null, null, 'yes']);
});

test('a translation that loses a placeholder is discarded, and one too long is cut to fit', function () {
    $user = translationUser();
    $flow = translationFlow($user);

    fakeTranslatingHub(fn (string $text) => [
        'Olá {{contact.name}}!' => 'Hello friend!',
        'Sim' => 'Yes, please send it to me now',
    ][$text] ?? null);

    Sanctum::actingAs($user);

    $response = $this->postJson("/api/flows/{$flow->id}/translate", ['language' => 'en'])
        ->assertCreated()
        ->assertJsonPath('translated', 1)
        ->assertJsonPath('kept', 3);

    $data = collect(FlowGraph::export(Flow::findOrFail($response->json('data.id')))['nodes'])->pluck('data', 'type');

    expect($data['message']['messages'][0]['body'])->toBe('Olá {{contact.name}}!')
        ->and($data['interactive']['buttons'][0]['title'])->toBe('Yes, please send it')
        ->and($data['interactive']['body'])->toBe('Posso enviar?');
});

test('nothing is created when the hub fails', function () {
    $user = translationUser();
    $flow = translationFlow($user);

    Http::fake(['*' => Http::response(['status' => 'FAILED', 'output' => null, 'error' => ['message' => 'boom']])]);
    Sanctum::actingAs($user);

    $this->postJson("/api/flows/{$flow->id}/translate", ['language' => 'en'])->assertStatus(502);

    expect(Flow::count())->toBe(1);
});

test('translation is refused without the plan feature, the permission, a known language or the flow', function () {
    $user = translationUser();
    $flow = translationFlow($user);
    fakeTranslatingHub(fn (string $text) => $text);

    Sanctum::actingAs($user);
    $this->postJson("/api/flows/{$flow->id}/translate", ['language' => 'klingon'])->assertStatus(422);

    $stranger = translationUser();
    Sanctum::actingAs($stranger);
    $this->postJson("/api/flows/{$flow->id}/translate", ['language' => 'en'])->assertNotFound();

    Sanctum::actingAs(translationUser(permissions: ['flows.update']));
    $this->postJson("/api/flows/{$flow->id}/translate", ['language' => 'en'])->assertForbidden();

    config(['services.billing.enforce' => true]);
    $plain = translationUser(features: ['flow' => true]);
    $own = translationFlow($plain);
    Sanctum::actingAs($plain);
    $this->postJson("/api/flows/{$own->id}/translate", ['language' => 'en'])->assertForbidden();

    expect(Flow::count())->toBe(2);
});

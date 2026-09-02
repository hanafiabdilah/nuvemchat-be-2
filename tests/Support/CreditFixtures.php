<?php

namespace Tests\Support;

use App\Enums\AiToken\TokenPoolKeyStatus;
use App\Enums\Billing\BillingCycle;
use App\Enums\Billing\PaymentMethod;
use App\Enums\Billing\SubscriptionStatus;
use App\Models\AiHubAgent;
use App\Models\Setting;
use App\Services\AiAgentHub\AiAgentHubConfig;
use App\Models\AiHubTenant;
use App\Models\AiTokenPoolKey;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Billing\SubscriptionGate;
use Illuminate\Support\Facades\Http;

/**
 * Shared setup for the rented-token and prepaid-credit suites.
 *
 * Lives outside tests/Feature so Pest does not try to run it, and as a class
 * rather than global functions in one of the files so neither suite depends on
 * the other having been loaded first.
 */
class CreditFixtures
{
    /**
     * A workspace with the AI hub already provisioned, so renting exercises the
     * rental path rather than the provisioning it happens to trigger.
     *
     * @return array{0: Tenant, 1: User, 2: AiHubTenant}
     */
    public static function workspace(): array
    {
        $user = User::factory()->create(['email' => 'rent-'.uniqid().'@example.test']);
        $tenant = Tenant::create(['user_id' => $user->id]);
        $user->forceFill(['tenant_id' => $tenant->id])->save();

        $plan = Plan::create([
            'name' => 'Pro', 'slug' => 'pro-'.uniqid(), 'price_cents' => 9990,
            'currency' => 'BRL', 'billing_cycle' => BillingCycle::Monthly, 'is_active' => true,
            'features' => ['ai_agent_hub' => true, 'chat' => true],
            'quotas' => [],
        ]);

        $subscription = Subscription::create([
            'tenant_id' => $tenant->id, 'plan_id' => $plan->id,
            'status' => SubscriptionStatus::Active, 'payment_method' => PaymentMethod::Pix,
            'billing_cycle' => BillingCycle::Monthly, 'price_cents' => 9990, 'quantity' => 1,
            'current_period_start' => now(), 'current_period_end' => now()->addMonth(),
            'quotas_snapshot' => [],
            'features_snapshot' => ['ai_agent_hub' => true, 'chat' => true],
        ]);
        $tenant->forceFill(['current_subscription_id' => $subscription->id])->save();

        $role = \Spatie\Permission\Models\Role::findOrCreate('owner-'.$tenant->id, 'web');

        foreach (['ai-agents.view', 'ai-agents.create', 'ai-agents.update', 'ai-agents.delete', 'billing.view', 'billing.manage'] as $permission) {
            $role->givePermissionTo(\Spatie\Permission\Models\Permission::findOrCreate($permission, 'web'));
        }

        $user->assignRole($role);

        $hubTenant = AiHubTenant::create([
            'tenant_id' => $tenant->id,
            'hub_tenant_id' => 'hub-tenant-'.uniqid(),
            'external_id' => 'Pingly_'.$tenant->id,
            'name' => 'Pingly_'.$tenant->id,
            'status' => 'ACTIVE',
        ]);

        // Auth to the hub is platform-level: Pingly is one tenant there, so a
        // single token stands behind every workspace's calls.
        Setting::set(AiAgentHubConfig::KEY_TENANT_TOKEN, 'platform-hub-token');

        app(SubscriptionGate::class)->forget($tenant->fresh());

        return [$tenant->fresh(), $user->fresh(), $hubTenant];
    }

    /** One platform-owned key in the pool. */
    public static function poolKey(array $overrides = []): AiTokenPoolKey
    {
        return AiTokenPoolKey::create(array_merge([
            'provider' => 'OPENAI',
            'label' => 'OpenAI #'.uniqid(),
            'api_key' => 'sk-platform-'.uniqid(),
            'key_preview' => '••••0000',
            'status' => TokenPoolKeyStatus::Active,
            'weight' => 1,
        ], $overrides));
    }

    /**
     * The hub's credential endpoints, returning a fresh id on every create.
     *
     * A callback rather than `Http::response([...])`: that body is evaluated
     * once, so a literal id would collide on the local unique index the second
     * time a test rents.
     */
    public static function fakeHub(): void
    {
        Http::fake([
            '*/provider-credentials/*' => fn () => Http::response([], 200),
            '*/provider-credentials' => fn () => Http::response([
                'id' => 'hub-cred-'.uniqid(),
                'provider' => 'OPENAI',
                'name' => 'rented',
                'keyPreview' => '••••0000',
                'status' => 'ACTIVE',
            ], 201),
        ]);
    }

    /**
     * A conversation to hang runs off. `ai_hub_runs.conversation_id` is NOT
     * NULL — a run always happened inside a thread — so the ledger tests need
     * one even though they never look at it.
     */
    public static function conversation(Tenant $tenant): \App\Models\Conversation
    {
        $connection = \App\Models\Connection::create([
            'tenant_id' => $tenant->id,
            'channel' => \App\Enums\Connection\Channel::WhatsappOfficial,
            'name' => 'WA',
            'color' => '#22c55e',
            'status' => \App\Enums\Connection\Status::Active,
        ]);

        $contact = \App\Models\Contact::create([
            'tenant_id' => $tenant->id,
            'channel' => \App\Enums\Connection\Channel::WhatsappOfficial,
            'external_id' => '5511999999999',
            'name' => 'Ana',
            'username' => '5511999999999',
        ]);

        return \App\Models\Conversation::create([
            'contact_id' => $contact->id,
            'connection_id' => $connection->id,
            'external_id' => '5511999999999',
            'status' => \App\Enums\Conversation\Status::Pending,
        ]);
    }

    /** An agent pointed at the given credential (or none). */
    public static function agent(AiHubTenant $hubTenant, ?int $credentialId = null, string $name = 'Suporte'): AiHubAgent
    {
        return AiHubAgent::create([
            'ai_hub_tenant_id' => $hubTenant->id,
            'ai_hub_provider_credential_id' => $credentialId,
            'hub_agent_id' => 'hub-agent-'.uniqid(),
            'external_id' => 'Pingly_agent_'.uniqid(),
            'name' => $name,
            'model' => 'gpt-4o-mini',
            'status' => 'ACTIVE',
        ]);
    }
}

<?php

namespace Tests\Support;

use App\Enums\Billing\BillingCycle;
use App\Enums\Billing\PaymentMethod;
use App\Enums\Billing\SubscriptionStatus;
use App\Models\McpClient;
use App\Models\McpConnection;
use App\Models\McpToken;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Mcp\Scopes;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Fixtures for the MCP suite.
 *
 * A class rather than Pest helper functions: Pest loads every test file into
 * one process, so two files declaring `mcpUser()` is a fatal redeclare — the
 * same reason IntegrationFixtures exists.
 */
final class McpFixtures
{
    public const REDIRECT = 'http://127.0.0.1:41234/callback';

    /**
     * A workspace whose plan includes MCP and flows.
     *
     * A real plan and subscription, not an entitlement override: overrides are
     * layered onto a usable plan's entitlements and are never reached without
     * one, so a tenant holding only an override reads as having no features.
     *
     * @param  list<string>  $permissions
     */
    public static function user(array $permissions = ['flows.view', 'flows.update', 'flows.create', 'flows.delete'], array $features = ['mcp' => true, 'flow' => true]): User
    {
        $user = User::factory()->create();
        $tenant = Tenant::create(['user_id' => $user->id]);
        $user->forceFill(['tenant_id' => $tenant->id])->save();

        $plan = Plan::create([
            'name' => 'Pro', 'slug' => 'mcp-'.uniqid(), 'price_cents' => 9990,
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

        $role = Role::findOrCreate('mcp-'.$tenant->id.'-'.uniqid(), 'web');

        foreach ([...$permissions, 'mcp.connect'] as $permission) {
            $role->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }

        $user->assignRole($role);

        return $user->fresh();
    }

    public static function client(array $attributes = []): McpClient
    {
        return McpClient::create($attributes + [
            'client_id' => 'mcpc_'.uniqid(),
            'client_name' => 'Claude Code',
            'source' => McpClient::SOURCE_DCR,
            'redirect_uris' => [self::REDIRECT],
        ]);
    }

    /**
     * A live connection and the plain access token for it.
     *
     * @return array{0: McpConnection, 1: string}
     */
    public static function connect(User $user, array $scopes = [Scopes::FLOWS_READ, Scopes::FLOWS_WRITE], ?McpClient $client = null): array
    {
        $client ??= self::client();

        $connection = McpConnection::create([
            'tenant_id' => $user->tenant_id,
            'user_id' => $user->id,
            'mcp_client_id' => $client->id,
            'client_name' => $client->client_name,
            'scopes' => $scopes,
        ]);

        $plain = McpToken::mint(McpToken::TYPE_ACCESS);

        McpToken::create([
            'token_hash' => McpToken::hash($plain),
            'mcp_connection_id' => $connection->id,
            'type' => McpToken::TYPE_ACCESS,
            'expires_at' => now()->addHour(),
        ]);

        return [$connection, $plain];
    }

    /**
     * A modern (2026-07-28) JSON-RPC call, headers and body kept in step.
     *
     * The mirrored headers are not optional in that revision, and getting them
     * wrong is a -32020 rather than anything to do with the call — so building
     * them by hand in each test would mostly test the test.
     */
    public static function call(TestCase $test, string $token, string $method, array $params = [], int $id = 1): TestResponse
    {
        $version = '2026-07-28';

        $params['_meta'] = [
            'io.modelcontextprotocol/protocolVersion' => $version,
            'io.modelcontextprotocol/clientInfo' => ['name' => 'TestClient', 'version' => '1.0.0'],
            'io.modelcontextprotocol/clientCapabilities' => (object) [],
        ];

        $headers = [
            'Authorization' => 'Bearer '.$token,
            'MCP-Protocol-Version' => $version,
            'Mcp-Method' => $method,
            'Accept' => 'application/json, text/event-stream',
        ];

        if ($method === 'tools/call') {
            $headers['Mcp-Name'] = (string) ($params['name'] ?? '');
        }

        return $test->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => $id,
            'method' => $method,
            'params' => $params,
        ], $headers);
    }

    /** A tool call, returning the decoded structuredContent. */
    public static function tool(TestCase $test, string $token, string $name, array $arguments = []): array
    {
        $response = self::call($test, $token, 'tools/call', ['name' => $name, 'arguments' => $arguments]);

        return $response->json('result') ?? ['_http' => $response->status(), '_body' => $response->json()];
    }

    /** PKCE pair: the verifier to send, and the challenge to register. */
    public static function pkce(): array
    {
        $verifier = str_repeat('a', 43);

        return [$verifier, rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=')];
    }
}

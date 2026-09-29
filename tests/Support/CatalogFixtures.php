<?php

namespace Tests\Support;

use App\Enums\Flow\NodeType;
use App\Enums\Integration\IntegrationProvider;
use App\Models\AiHubAgent;
use App\Models\ApiKey;
use App\Models\Conversation;
use App\Models\FlowNode;
use App\Models\Integration;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AiAgentHub\AiCallbackRef;
use App\Services\Catalog\ProductService;
use App\Services\Flow\AiToolNodes;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Setup for the catalog and "Agente IA com ações" suites: a WhatsApp
 * conversation parked on an ai_tools node, a payment account, products, and
 * the workspace API key the hub would hold.
 *
 * Built on AiAgentFixtures so the flow state is the one the real engine wrote.
 * A class, not Pest helpers — Pest loads every test file into one process.
 */
final class CatalogFixtures
{
    /**
     * What the fakes answer and what they saw. One static bag rather than a
     * variable passed by reference: Http::fake() calls every registered
     * callback and keeps the FIRST answer, so a second fake registered by a
     * test would record requests but never be heard.
     *
     * @var array<string, mixed>
     */
    public static array $state = [];

    /**
     * @param  array<string, mixed>  $capabilities
     * @return array{conversation: Conversation, node: FlowNode, agent: AiHubAgent, tenant: Tenant, integration: Integration, key: string, owner: User, paid: FlowNode, failed: FlowNode, handoff: FlowNode}
     */
    public static function scenario(array $capabilities = [], bool $open = true): array
    {
        [$conversation, $node] = AiAgentFixtures::flow();

        $tenant = $conversation->connection->tenant;
        $integration = IntegrationFixtures::integration($tenant, IntegrationProvider::OpenPix);

        $node->update([
            'type' => NodeType::AiTools,
            'data' => array_merge($node->data, [
                'capabilities' => array_replace_recursive([
                    'catalog' => true,
                    'cart' => true,
                    'payment' => ['enabled' => true, 'integration_id' => $integration->id, 'method' => 'pix', 'expires_in_minutes' => 60],
                ], $capabilities),
            ]),
        ]);

        $flow = $node->flow;
        $paid = IntegrationFixtures::say($flow, 'Pagamento confirmado, obrigado!', 560, -100);
        $failed = IntegrationFixtures::say($flow, 'O Pix expirou.', 560, 100);
        $handoff = IntegrationFixtures::say($flow, 'Vou te passar para a equipe.', 560, 300);

        IntegrationFixtures::edge($node, $paid, AiToolNodes::BRANCH_PAID);
        IntegrationFixtures::edge($node, $failed, AiToolNodes::BRANCH_PAYMENT_FAILED);
        IntegrationFixtures::edge($node, $handoff, AiToolNodes::BRANCH_HANDOFF);

        $owner = User::where('tenant_id', $tenant->id)->firstOrFail();

        if ($open) {
            self::fake(reset: true);
            AiAgentFixtures::openWithWelcome($conversation);
            $conversation->refresh();
        }

        [, $plain] = ApiKey::issue($tenant, 'AI Hub', $owner);

        return [
            'conversation' => $conversation,
            'node' => $node->fresh(),
            'agent' => AiHubAgent::findOrFail($node->data['ai_hub_agent_id']),
            'tenant' => $tenant,
            'integration' => $integration,
            'key' => $plain,
            'owner' => $owner,
            'paid' => $paid,
            'failed' => $failed,
            'handoff' => $handoff,
        ];
    }

    /**
     * A product with the given variants: [name => [price_cents, stock]], or a
     * simple product when the list has one entry keyed by null/''.
     *
     * @param  array<string, array{0: int, 1: int|null}>  $variants
     */
    public static function product(Tenant $tenant, string $name, array $variants): Product
    {
        $hasVariants = count($variants) > 1 || array_key_first($variants) !== '';

        return app(ProductService::class)->create($tenant->id, [
            'name' => $name,
            'has_variants' => $hasVariants,
            'variants' => collect($variants)->map(fn ($v, $variantName) => [
                'name' => $variantName === '' ? null : $variantName,
                'price_cents' => $v[0],
                'stock' => $v[1],
            ])->values()->all(),
        ]);
    }

    /**
     * WhatsApp, OpenPix and the hub all answer, reading and writing
     * self::$state. Registered once per test; later calls only reset.
     */
    public static function fake(bool $reset = false): void
    {
        if ($reset) {
            self::$state = [];
        }

        static $registeredFor = null;
        $app = spl_object_id(app());

        if ($registeredFor === $app) {
            return;
        }

        $registeredFor = $app;

        Http::fake(function (Request $request) {
            $state = &self::$state;
            $url = $request->url();

            if (str_contains($url, 'graph.facebook.com')) {
                return Http::response(['messages' => [['id' => 'wamid.'.Str::random(8)]]]);
            }

            if (str_contains($url, 'api-ia.ipbr.pro')) {
                // The two per-agent registrations the hub's contract asks for.
                if ($request->method() === 'PUT' && str_contains($url, '/pingly-tools')) {
                    $state['catalogs'][] = $request->data();

                    return Http::response(['agentId' => 'hub-agent-1', 'tools' => $request['tools']]);
                }

                if ($request->method() === 'PUT' && str_contains($url, '/pingly-delivery')) {
                    $state['deliveries'][] = $request->data();

                    return Http::response(['agentId' => 'hub-agent-1', 'configured' => true]);
                }

                $state['runs'][] = $request->data();

                if (isset($state['run_response'])) {
                    return Http::response($state['run_response']);
                }

                return Http::response([
                    'id' => 'run_'.Str::random(6),
                    'status' => 'COMPLETED',
                    'output' => ['message' => $state['reply'] ?? 'Posso ajudar!', 'handoff' => $state['handoff'] ?? false],
                ]);
            }

            if (str_contains($url, 'api.openpix.com.br/api/v1/charge')) {
                if ($request->method() === 'POST') {
                    $state['charges'][] = $request->data();

                    return Http::response(['charge' => [
                        'correlationID' => $request['correlationID'],
                        'globalID' => 'Q2hhcmdlOjE=',
                        'status' => 'ACTIVE',
                        'value' => $request['value'],
                        'brCode' => IntegrationFixtures::PIX_CODE,
                        'paymentLinkUrl' => 'https://openpix.com.br/pay/abc123',
                        'expiresDate' => now()->addHour()->toIso8601String(),
                    ]]);
                }

                return Http::response(['charge' => [
                    'status' => $state['openpix_status'] ?? 'ACTIVE',
                    'paidAt' => now()->toIso8601String(),
                ]]);
            }

            return Http::response([], 404);
        });
    }

    /** POST a tool call as the hub would. */
    public static function call(array $scene, string $tool, array $arguments = [], ?string $idempotencyKey = null, ?string $ref = null): \Illuminate\Testing\TestResponse
    {
        return test()->withHeaders([
            'X-Api-Key' => $scene['key'],
            'Idempotency-Key' => $idempotencyKey ?? 'run_1:'.Str::random(8),
        ])->postJson('/api/v1/conversations/tools', [
            'callback_ref' => $ref ?? AiCallbackRef::mint($scene['conversation']->id, $scene['node']->id, $scene['agent']->id),
            'tool' => $tool,
            'arguments' => $arguments,
            'run_id' => 'run_1',
        ]);
    }
}

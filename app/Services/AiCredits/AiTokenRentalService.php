<?php

namespace App\Services\AiCredits;

use App\Enums\Flow\NodeType;
use App\Models\AiHubAgent;
use App\Models\AiHubProviderCredential;
use App\Models\AiTokenPoolKey;
use App\Models\FlowNode;
use App\Models\Tenant;
use App\Services\AiAgentHub\AiAgentHubService;
use App\Services\AiAgentHub\AiAgentHubTenantService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Renting a platform key to a workspace, releasing it, and moving a workspace
 * off a key that has gone bad.
 *
 * The thing to understand before reading any of it: a hub provider credential
 * belongs to a hub *tenant*. There is no shared record several workspaces can
 * point at. So renting means registering the same secret again, inside the
 * renting workspace's own hub scope, and what the workspace ends up holding is
 * an ordinary `ai_hub_provider_credentials` row — one that happens to carry
 * `ai_token_pool_key_id`, which is the whole of what makes it rented.
 *
 * That shape is what keeps the rest of the application unchanged: agents,
 * the trained-agent fork and the flow node's audio settings all select a
 * credential by local id and neither know nor care who pays for the key behind
 * it. The tenant never sees the secret, for the same reason they never saw
 * their own after typing it: only `keyPreview` is ever stored or returned.
 */
class AiTokenRentalService
{
    public function __construct(
        private readonly AiTokenPool $pool,
        private readonly AiAgentHubTenantService $tenantService,
        private readonly AiAgentHubService $hubService,
    ) {}

    /**
     * Rent a key of the given provider for the workspace.
     *
     * Idempotent per (tenant, provider): a workspace that already rents an
     * OpenAI key gets the one it has back, not a second one. Renting twice
     * would give an agent two indistinguishable entries in its credential
     * dropdown and double the platform's exposure on that workspace for no
     * benefit — the pool spreads load across workspaces, not within one.
     *
     * The lock is per (tenant, provider) rather than per tenant: two people in
     * the same workspace clicking "rent" on different providers at once are not
     * in conflict, and serialising them would only be visible as one of the two
     * hanging on a hub round-trip.
     */
    public function rent(Tenant $tenant, string $provider): AiHubProviderCredential
    {
        $provider = strtoupper($provider);

        abort_unless((bool) config('ai.credits.enabled', true), 403, 'Token rental is not available.');

        return Cache::lock("ai-token-rental:{$tenant->id}:{$provider}", 30)->block(10, function () use ($tenant, $provider) {
            $existing = $this->activeRental($tenant, $provider);

            if ($existing !== null) {
                return $existing;
            }

            $key = $this->pool->pick($provider);

            if ($key === null) {
                // Not a server error: the pool is a stock of things an admin
                // adds, and running out is a business state with an action
                // behind it. Saying so is what gets the key added.
                throw new RuntimeException("No {$provider} token is available to rent right now.");
            }

            return $this->materialize($tenant, $key);
        });
    }

    /**
     * The workspace's live rental for a provider, if any.
     */
    public function activeRental(Tenant $tenant, string $provider): ?AiHubProviderCredential
    {
        $aiHubTenant = $tenant->aiHubTenant;

        if ($aiHubTenant === null) {
            return null;
        }

        return $aiHubTenant->providerCredentials()
            ->rented()
            ->where('provider', strtoupper($provider))
            ->first();
    }

    /**
     * All of the workspace's rentals, for the credentials screen.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, AiHubProviderCredential>
     */
    public function rentals(Tenant $tenant)
    {
        $aiHubTenant = $tenant->aiHubTenant;

        if ($aiHubTenant === null) {
            return AiHubProviderCredential::query()->whereRaw('1 = 0')->get();
        }

        return $aiHubTenant->providerCredentials()->rented()->with('poolKey:id,label,provider,status')->get();
    }

    /**
     * Give a rented key back.
     *
     * Refused while anything still points at it. Releasing under an agent would
     * leave that agent referencing a hub credential that no longer exists, and
     * the failure would surface as every reply on that flow dying at run time —
     * far from the button that caused it. Naming the agents is what makes the
     * refusal actionable.
     */
    public function release(AiHubProviderCredential $credential): void
    {
        abort_unless($credential->isRented(), 422, 'This credential is not rented.');

        $agents = AiHubAgent::where('ai_hub_provider_credential_id', $credential->id)
            ->pluck('name')
            ->all();

        if ($agents !== []) {
            abort(422, 'Still used by: ' . implode(', ', $agents) . '. Point them at another credential first.');
        }

        $nodes = $this->nodesUsing($credential);

        if ($nodes->isNotEmpty()) {
            abort(422, 'Still used for audio in ' . $nodes->count() . ' flow node(s). Change them first.');
        }

        $this->tenantService->deleteProviderCredential($credential);
    }

    /**
     * Move every workspace off a key and onto another one.
     *
     * Called when an admin revokes a key: the secret behind it is gone, so
     * every workspace holding it is already broken, and doing nothing is the
     * one option that leaves them that way. Each workspace is moved
     * independently and a failure on one is logged rather than thrown — one
     * unreachable hub tenant must not strand the rest on a dead key.
     *
     * @return array{moved: int, failed: int}
     */
    public function rotateAllFrom(AiTokenPoolKey $key): array
    {
        $moved = 0;
        $failed = 0;

        $key->credentials()->with('aiHubTenant.tenant')->get()->each(function (AiHubProviderCredential $credential) use (&$moved, &$failed) {
            try {
                $this->rotate($credential);
                $moved++;
            } catch (\Throwable $e) {
                $failed++;

                Log::error('AiTokenRentalService: failed to rotate a rental off a revoked key', [
                    'ai_hub_provider_credential_id' => $credential->id,
                    'ai_token_pool_key_id' => $credential->ai_token_pool_key_id,
                    'error' => $e->getMessage(),
                ]);
            }
        });

        return ['moved' => $moved, 'failed' => $failed];
    }

    /**
     * Swap one workspace's rented credential for a fresh one from the pool.
     *
     * Order matters and is the opposite of the obvious one: the replacement is
     * created and everything is re-pointed at it *before* the old credential is
     * deleted. Deleting first would leave a window in which a run picks up an
     * agent whose credential no longer exists — short, but exactly as long as
     * the hub round-trips that follow it.
     */
    public function rotate(AiHubProviderCredential $credential): AiHubProviderCredential
    {
        abort_unless($credential->isRented(), 422, 'This credential is not rented.');

        $tenant = $credential->aiHubTenant?->tenant;

        if ($tenant === null) {
            throw new RuntimeException("Rental {$credential->id} has no tenant behind it.");
        }

        $replacementKey = $this->pool->pick($credential->provider, [$credential->ai_token_pool_key_id]);

        if ($replacementKey === null) {
            throw new RuntimeException("No spare {$credential->provider} token to rotate onto.");
        }

        $replacement = $this->materialize($tenant, $replacementKey);

        $this->repoint($credential, $replacement);

        try {
            $this->tenantService->deleteProviderCredential($credential);
        } catch (\Throwable $e) {
            // The move already succeeded; the leftover is a dead row on the hub
            // and a stale local one, neither of which anything points at. Worth
            // a log, not worth undoing a working rotation for.
            Log::warning('AiTokenRentalService: rotated, but the old credential could not be deleted', [
                'ai_hub_provider_credential_id' => $credential->id,
                'error' => $e->getMessage(),
            ]);
        }

        Log::info('AiTokenRentalService: rental rotated', [
            'tenant_id' => $tenant->id,
            'from_pool_key' => $credential->ai_token_pool_key_id,
            'to_pool_key' => $replacementKey->id,
        ]);

        return $replacement;
    }

    /**
     * Register a pool key inside the workspace's hub scope and record the
     * mirror row that results.
     *
     * The workspace's scope is opened first — renting is a plausible first AI
     * action a workspace ever takes, and without this it would fail on a
     * missing scope row rather than open one.
     */
    protected function materialize(Tenant $tenant, AiTokenPoolKey $key): AiHubProviderCredential
    {
        $aiHubTenant = $this->hubService->createTenant($tenant);

        $credential = $this->tenantService->createProviderCredential($aiHubTenant, [
            'provider' => $key->provider,
            // Named for what it is in the dropdown the customer picks from. The
            // pool key's own label is an internal name ("OpenAI #3") and would
            // only invite questions we do not want to answer.
            'name' => config('app.name') . ' — ' . $key->provider . ' (alugado)',
            'apiKey' => $key->api_key,
            'defaultModel' => $key->default_model,
        ], metadata: [
            'billingMode' => 'rented_platform_token',
            'ownerType' => 'platform',
            'poolKeyId' => $key->id,
        ]);

        $credential->update(['ai_token_pool_key_id' => $key->id]);

        Log::info('AiTokenRentalService: token rented', [
            'tenant_id' => $tenant->id,
            'provider' => $key->provider,
            'ai_token_pool_key_id' => $key->id,
            'ai_hub_provider_credential_id' => $credential->id,
        ]);

        return $credential->fresh();
    }

    /**
     * Move everything that selects the old credential onto the new one.
     *
     * Two kinds of reference, and both have to move or the rotation is a
     * partial one that fails later in a place with no memory of this:
     *
     *  - agents, which hold the local id and must also be updated hub-side;
     *  - AIAgent flow nodes, whose transcription and voice settings hold the
     *    *hub* credential id as a string, chosen in the node editor.
     *
     * The second one is easy to forget precisely because it does not look like
     * a foreign key.
     */
    protected function repoint(AiHubProviderCredential $old, AiHubProviderCredential $new): void
    {
        AiHubAgent::where('ai_hub_provider_credential_id', $old->id)
            ->get()
            ->each(function (AiHubAgent $agent) use ($new) {
                $this->tenantService->updateAgent($agent, [
                    'providerCredentialId' => $new->hub_provider_credential_id,
                ]);
            });

        $this->nodesUsing($old)->each(function (FlowNode $node) use ($old, $new) {
            $data = $node->data ?? [];

            foreach (['input_audio', 'response_audio'] as $block) {
                if (($data[$block]['credential_id'] ?? null) === $old->hub_provider_credential_id) {
                    $data[$block]['credential_id'] = $new->hub_provider_credential_id;
                }
            }

            $node->update(['data' => $data]);
        });
    }

    /**
     * AIAgent nodes in the workspace whose audio settings name this credential.
     *
     * Scanned in PHP rather than with a JSON path query: the two drivers spell
     * that differently, and an AIAgent node count is small enough that the
     * portability is worth more than the index would be.
     *
     * @return \Illuminate\Support\Collection<int, FlowNode>
     */
    protected function nodesUsing(AiHubProviderCredential $credential)
    {
        $tenantId = $credential->aiHubTenant?->tenant_id;

        if ($tenantId === null) {
            return collect();
        }

        return FlowNode::query()
            ->where('type', NodeType::AIAgent->value)
            ->whereHas('flow', fn ($q) => $q->where('tenant_id', $tenantId))
            ->get()
            ->filter(function (FlowNode $node) use ($credential) {
                $data = $node->data ?? [];

                return ($data['input_audio']['credential_id'] ?? null) === $credential->hub_provider_credential_id
                    || ($data['response_audio']['credential_id'] ?? null) === $credential->hub_provider_credential_id;
            });
    }
}

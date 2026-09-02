<?php

namespace App\Services\AiCredits;

use App\Enums\Flow\NodeType;
use App\Models\AiHubAgent;
use App\Models\AiHubProviderCredential;
use App\Models\AiTokenPoolKey;
use App\Models\AiHubTenant;
use App\Models\FlowNode;
use App\Models\Tenant;
use App\Services\AiAgentHub\AiAgentHubService;
use App\Services\AiAgentHub\AiAgentHubTenantService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
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

            // Before minting anything: does the hub already hold a rented
            // credential for this workspace that we have no local row for?
            //
            // That state is reachable — a half-finished attempt, a local
            // database restored from before one — and it used to be terminal:
            // the hub keys credentials on (tenant, provider, name), so every
            // retry answered 409 and the workspace could never rent that
            // provider again. Adopting is also the only outcome that leaves the
            // two sides agreeing; minting a second one would work and quietly
            // leave behind a credential nothing points at and nobody deletes.
            //
            // Deliberately only on this path. A rotation must always mint a
            // fresh credential against the new key's secret — adopting a
            // stranger there would label it with a pool key that did not issue
            // it.
            $adopted = $this->adoptOrphan($tenant->aiHubTenant ?? $this->hubService->createTenant($tenant), $key);

            return $adopted ?? $this->materialize($tenant, $key);
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
     * Make sure a rented credential is one the hub will actually accept, and
     * repair it in place when it is not.
     *
     * A local row can outlive the hub record it names — the hub was reset, the
     * credential was disabled, an adoption took a stale one. For a key of the
     * customer's own there is nothing to be done: we never kept the secret. A
     * rented one is different, and that difference is the whole point of this
     * method — the pool still holds the key, so the credential can simply be
     * minted again and everything re-pointed at it.
     *
     * Without this the failure surfaces as `Provider credential not found or
     * disabled` when an agent is saved: a message about the hub's bookkeeping,
     * shown to somebody who was naming an agent.
     */
    public function ensureUsable(AiHubProviderCredential $credential): AiHubProviderCredential
    {
        if (! $credential->isRented()) {
            return $credential;
        }

        $key = $credential->poolKey;
        $aiHubTenant = $credential->aiHubTenant;

        if ($key === null || $aiHubTenant === null) {
            return $credential;
        }

        try {
            $remote = $this->tenantService->listProviderCredentials($aiHubTenant);
        } catch (\Throwable $e) {
            // Unreachable hub: let the save proceed and fail on its own terms
            // rather than turning a transient outage into a re-mint.
            Log::warning('AiTokenRentalService: could not verify a rental before use', [
                'ai_hub_provider_credential_id' => $credential->id,
                'error' => $e->getMessage(),
            ]);

            return $credential;
        }

        $rows = $remote['data'] ?? $remote;
        $live = null;

        foreach (is_array($rows) ? $rows : [] as $row) {
            if (is_array($row) && ($row['id'] ?? null) === $credential->hub_provider_credential_id) {
                $live = $row;
                break;
            }
        }

        if ($live !== null && strtoupper((string) ($live['status'] ?? 'ACTIVE')) === 'ACTIVE') {
            return $credential;
        }

        Log::warning('AiTokenRentalService: re-minting a rental the hub cannot serve', [
            'ai_hub_provider_credential_id' => $credential->id,
            'hub_provider_credential_id' => $credential->hub_provider_credential_id,
            'reason' => $live === null ? 'missing' : 'disabled',
        ]);

        // Same key, fresh record. Not `rotate()`: nothing is wrong with the key
        // itself, so sending this workspace to a different one would spread it
        // across the pool for a reason that has nothing to do with load.
        $replacement = $this->materialize($aiHubTenant->tenant, $key);

        $this->repoint($credential, $replacement);

        try {
            $this->tenantService->deleteProviderCredential($credential);
        } catch (\Throwable $e) {
            // Already gone, most likely — which is how we got here.
            $credential->delete();
        }

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
            // Unique per credential, and it has to be: the hub keys credentials
            // on (tenant, provider, name), while a rotation deliberately holds
            // the replacement and the outgoing one at the same time. A name
            // derived only from the provider made every rotation collide with
            // 409 — and the rotation reported failure for every workspace on the
            // key it was supposed to be rescuing.
            'name' => $this->hubName($key),
            'apiKey' => $key->api_key,
            'defaultModel' => $key->default_model,
        ], metadata: self::rentedMetadata($key));

        return $this->finishRental($credential, $key, $tenant);
    }

    /**
     * The name the hub stores. Never shown to anyone — `finishRental()`
     * overwrites the local one with something a customer can read.
     *
     * The random tail is the whole point: it is what makes two credentials for
     * the same provider able to coexist during a rotation.
     */
    protected function hubName(AiTokenPoolKey $key): string
    {
        return config('app.name') . ' rented ' . $key->provider . ' ' . Str::lower(Str::random(6));
    }

    /** What the customer sees in the credential dropdown. */
    protected function displayName(AiTokenPoolKey $key): string
    {
        return config('app.name') . ' — ' . $key->provider . ' (alugado)';
    }

    /** @return array<string, mixed> */
    protected static function rentedMetadata(AiTokenPoolKey $key): array
    {
        return [
            'billingMode' => 'rented_platform_token',
            'ownerType' => 'platform',
            'poolKeyId' => $key->id,
        ];
    }

    /**
     * Mark a freshly created mirror as rented and give it a readable name.
     *
     * The local name is deliberately not the hub's: the hub needs uniqueness,
     * the dropdown needs a sentence. Keeping the two apart is what lets the
     * first be ugly.
     */
    protected function finishRental(AiHubProviderCredential $credential, AiTokenPoolKey $key, ?Tenant $tenant = null): AiHubProviderCredential
    {
        $credential->update([
            'ai_token_pool_key_id' => $key->id,
            'name' => $this->displayName($key),
        ]);

        Log::info('AiTokenRentalService: token rented', [
            'tenant_id' => $tenant?->id ?? $credential->aiHubTenant?->tenant_id,
            'provider' => $key->provider,
            'ai_token_pool_key_id' => $key->id,
            'ai_hub_provider_credential_id' => $credential->id,
        ]);

        return $credential->fresh();
    }

    /**
     * Find a rented credential the hub already holds for this workspace and
     * build the missing local mirror for it.
     *
     * Only ever adopts a row that is (a) not already mirrored locally and (b)
     * identifiably ours. That second test is not paranoia: the workspace's own
     * keys are in the same list, and adopting one of those would put a customer's
     * private key under the platform's billing and let us delete it out from
     * under them.
     *
     * Matched on the metadata we write, with the name prefix as a fallback —
     * whether the hub echoes metadata back on its list endpoint is not something
     * this side can guarantee.
     */
    protected function adoptOrphan(AiHubTenant $aiHubTenant, AiTokenPoolKey $key): ?AiHubProviderCredential
    {
        try {
            $remote = $this->tenantService->listProviderCredentials($aiHubTenant);
        } catch (\Throwable $e) {
            Log::warning('AiTokenRentalService: could not list hub credentials to adopt an orphan', [
                'ai_hub_tenant_id' => $aiHubTenant->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        $rows = $remote['data'] ?? $remote;

        if (! is_array($rows)) {
            return null;
        }

        $known = AiHubProviderCredential::query()
            ->pluck('hub_provider_credential_id')
            ->all();

        $prefix = config('app.name') . ' rented ' . $key->provider;
        $legacyName = $this->displayName($key);

        foreach ($rows as $row) {
            if (! is_array($row) || empty($row['id']) || in_array($row['id'], $known, true)) {
                continue;
            }

            if (strtoupper((string) ($row['provider'] ?? '')) !== strtoupper($key->provider)) {
                continue;
            }

            // Adopting a disabled credential is worse than not adopting one:
            // it succeeds here and then fails on every agent built against it,
            // with "provider credential not found or disabled" — a message
            // about the hub, arriving nowhere near the decision that caused it.
            if (strtoupper((string) ($row['status'] ?? 'ACTIVE')) !== 'ACTIVE') {
                continue;
            }

            $name = (string) ($row['name'] ?? '');
            $isOurs = ($row['metadata']['ownerType'] ?? null) === 'platform'
                || str_starts_with($name, $prefix)
                // Credentials minted before the name carried a random tail.
                || $name === $legacyName;

            if (! $isOurs) {
                continue;
            }

            Log::warning('AiTokenRentalService: adopted an orphaned rented credential from the hub', [
                'ai_hub_tenant_id' => $aiHubTenant->id,
                'hub_provider_credential_id' => $row['id'],
                'ai_token_pool_key_id' => $key->id,
            ]);

            /** @var AiHubProviderCredential $credential */
            $credential = $aiHubTenant->providerCredentials()->create([
                'hub_provider_credential_id' => $row['id'],
                'provider' => $row['provider'] ?? $key->provider,
                'name' => $name,
                'key_preview' => $row['keyPreview'] ?? $key->key_preview,
                'default_model' => $row['defaultModel'] ?? $key->default_model,
                'status' => $row['status'] ?? 'ACTIVE',
                'metadata' => $row['metadata'] ?? self::rentedMetadata($key),
            ]);

            return $this->finishRental($credential, $key);
        }

        return null;
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

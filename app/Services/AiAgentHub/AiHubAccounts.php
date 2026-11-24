<?php

namespace App\Services\AiAgentHub;

use App\Models\AiHubAgent;
use App\Models\AiHubApiKey;
use App\Models\AiHubProviderCredential;
use App\Models\AiHubTenant;
use App\Models\FlowAssistantSetting;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One AI hub account per workspace, inside our single hub tenant.
 *
 * Pingly still authenticates with the one platform token (see
 * {@see AiAgentHubConfig}). What changes is that every call made for a
 * workspace names that workspace's hub account in the `X-Hub-Tenant` header,
 * and the hub runs the request inside that account only. Until this, every
 * workspace's agents, provider keys and runs lived in the same hub account,
 * and the hub — picking "the newest OpenAI key in the account" to transcribe a
 * voice note — could spend another customer's key.
 *
 * The account is opened by the hub (`POST /platform/tenants`, idempotent by
 * `externalRef`) and its id is stored on `ai_hub_tenants.hub_tenant_id`.
 *
 * ⚠️ Everything here is behind `ai_agent_hub.per_workspace_accounts`. Off, no
 * `/platform/*` call is made and no header is sent — not even a relationship
 * is resolved, so the path every hub call takes is the one it took before.
 *
 * Two modes, decided once, at creation:
 *  - `isolated` — the workspace has no AI data yet, so it can start in its own
 *    account on day one;
 *  - `shared` — it already has agents, keys or settings in the shared account.
 *    The hub keeps serving it from there until its team moves the data, which
 *    keeps every id, so nothing stored here needs to change.
 */
class AiHubAccounts
{
    public const HEADER = 'X-Hub-Tenant';

    public const MODE_ISOLATED = 'isolated';

    public const MODE_SHARED = 'shared';

    /** What the hub answers (403) for an id that is not an account of ours. */
    public const REFUSAL_CODE = 'hub_tenant_forbidden';

    /** Stable and never reused: the hub dedupes account creation on it. */
    public const EXTERNAL_REF_PREFIX = 'pingly-tenant-';

    /** Seconds before a failed provisioning is tried again on a live request. */
    protected const RETRY_AFTER_SECONDS = 300;

    protected const LOCK_TTL = 30;

    /**
     * Longer than the create call can take: a request that has to wait here
     * is one about to write data, and it must see the account the other
     * request just opened rather than write into the shared one.
     */
    protected const LOCK_WAIT = 15;

    protected const TIMEOUT_SECONDS = 10;

    public static function enabled(): bool
    {
        return AiAgentHubConfig::perWorkspaceAccounts();
    }

    /**
     * The header naming this workspace's hub account, or none.
     *
     * None when the flag is off, when no workspace is behind the call (the
     * model catalogue), or when the account could not be opened — the hub
     * unreachable must not stop a workspace from working; the scheduled
     * `ai-hub:provision-accounts` tries again.
     *
     * @return array<string, string>
     */
    public function headerFor(?AiHubTenant $tenant): array
    {
        if (! self::enabled() || $tenant === null) {
            return [];
        }

        $id = $this->ensure($tenant);

        return $id === null ? [] : [self::HEADER => $id];
    }

    /**
     * Open the workspace's hub account if it has none, and return its id.
     *
     * `$force` skips the back-off a recent failure set — the command uses it,
     * live requests do not, so a hub that is down is asked once every few
     * minutes rather than on every message.
     */
    public function ensure(AiHubTenant $tenant, bool $force = false): ?string
    {
        if (! self::enabled()) {
            return null;
        }

        if (filled($tenant->hub_tenant_id)) {
            return (string) $tenant->hub_tenant_id;
        }

        if (! $force && Cache::has($this->retryKey($tenant))) {
            return null;
        }

        try {
            return Cache::lock($this->lockKey($tenant), self::LOCK_TTL)
                ->block(self::LOCK_WAIT, fn () => $this->provision($tenant));
        } catch (LockTimeoutException) {
            // The other request may have finished in the meantime.
            $tenant->refresh();

            return filled($tenant->hub_tenant_id) ? (string) $tenant->hub_tenant_id : null;
        }
    }

    /**
     * The mode a workspace would be given if its account were opened now:
     * `isolated` only when nothing of it exists at the hub yet.
     *
     * A rented key is a provider credential row (with a pool key), so the
     * credential check covers it.
     */
    public function plannedMode(AiHubTenant $tenant): string
    {
        $hasData = AiHubAgent::query()->where('ai_hub_tenant_id', $tenant->id)->exists()
            || AiHubProviderCredential::query()->where('ai_hub_tenant_id', $tenant->id)->exists()
            || AiHubApiKey::query()->where('ai_hub_tenant_id', $tenant->id)->exists()
            || FlowAssistantSetting::query()->where('tenant_id', $tenant->tenant_id)->exists();

        return $hasData ? self::MODE_SHARED : self::MODE_ISOLATED;
    }

    public static function externalRef(AiHubTenant $tenant): string
    {
        return self::EXTERNAL_REF_PREFIX.$tenant->tenant_id;
    }

    /**
     * What the hub knows of this workspace's account, as recorded here when it
     * was opened. `null` for a row whose `hub_tenant_id` predates this — the
     * ids left from before the hub was rebuilt (see
     * `ai-hub:clear-stale-accounts`).
     *
     * @return array<string, mixed>|null
     */
    public static function recorded(AiHubTenant $tenant): ?array
    {
        $account = ($tenant->metadata ?? [])['hub_account'] ?? null;

        return is_array($account) ? $account : null;
    }

    /**
     * True when the hub refused the account named in the header: 403
     * `hub_tenant_forbidden`, or a 404 about the account rather than about
     * an object inside it. Such a response must never be read as "the agent
     * is gone, rebuild it", and never retried without the header — that would
     * write into the shared account again.
     */
    public static function isAccountRefusal(Response $response): bool
    {
        if (! in_array($response->status(), [403, 404], true)) {
            return false;
        }

        $code = strtolower((string) ($response->json('code') ?? ''));

        return $response->status() === 403
            ? $code === self::REFUSAL_CODE
            : str_starts_with($code, 'hub_tenant');
    }

    protected function provision(AiHubTenant $tenant): ?string
    {
        $tenant->refresh();

        if (filled($tenant->hub_tenant_id)) {
            return (string) $tenant->hub_tenant_id;
        }

        $mode = $this->plannedMode($tenant);
        $externalRef = self::externalRef($tenant);

        $context = [
            'ai_hub_tenant_id' => $tenant->id,
            'tenant_id' => $tenant->tenant_id,
            'external_ref' => $externalRef,
            'mode' => $mode,
        ];

        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->connectTimeout(5)
                ->withHeaders($this->authHeaders())
                ->post(AiAgentHubConfig::baseUrl().'/platform/tenants', [
                    'externalRef' => $externalRef,
                    'name' => $this->displayName($tenant),
                    'mode' => $mode,
                ]);
        } catch (Throwable $e) {
            return $this->backOff($tenant, 'the hub did not answer', $context + ['error' => $e->getMessage()]);
        }

        if (! $response->successful()) {
            return $this->backOff($tenant, 'the hub refused to open the account', $context + [
                'status' => $response->status(),
                'body' => mb_substr($response->body(), 0, 1000),
            ]);
        }

        $id = (string) ($response->json('id') ?? '');
        $isolation = strtolower((string) ($response->json('isolation') ?? ''));
        $returnedRef = (string) ($response->json('externalRef') ?? $externalRef);

        if ($id === '' || $returnedRef !== $externalRef) {
            return $this->backOff($tenant, 'the hub answered with an account we did not ask for', $context + [
                'hub_tenant_id' => $id ?: null,
                'returned_external_ref' => $returnedRef,
            ]);
        }

        // Creation is idempotent and never changes the mode, so a repeat call
        // can return an account opened earlier — e.g. one whose answer was lost
        // while this workspace went on writing into the shared account. An
        // isolated account would not hold that data, and every call naming it
        // would stop finding the workspace's agents. Stored, it would break
        // the workspace; not stored, the workspace keeps working as today and
        // the log says why. (The reverse — asked isolated, told shared — is
        // safe: shared serves from where the data already is.)
        if ($mode === self::MODE_SHARED && $isolation === self::MODE_ISOLATED) {
            Log::error('AiHubAccounts: the hub account is isolated but this workspace has data in the shared account; not using it', $context + [
                'hub_tenant_id' => $id,
            ]);

            $this->remember($tenant, ['error' => [
                'reason' => 'mode_mismatch',
                'hub_tenant_id' => $id,
                'at' => now()->toIso8601String(),
            ]]);
            Cache::put($this->retryKey($tenant), true, self::RETRY_AFTER_SECONDS);

            return null;
        }

        $metadata = $tenant->metadata ?? [];
        unset($metadata['hub_account_attempt']);
        $metadata['hub_account'] = [
            'mode' => $isolation ?: $mode,
            'requested_mode' => $mode,
            'external_ref' => $externalRef,
            'provisioned_at' => now()->toIso8601String(),
        ];

        $tenant->update([
            'hub_tenant_id' => $id,
            'metadata' => $metadata,
        ]);

        Cache::forget($this->retryKey($tenant));

        Log::info('AiHubAccounts: workspace hub account opened', $context + [
            'hub_tenant_id' => $id,
            'isolation' => $isolation ?: null,
        ]);

        return $id;
    }

    /**
     * A failed attempt: logged, remembered on the row for the command to
     * report, and not tried again on a live request for a few minutes.
     *
     * @param  array<string, mixed>  $context
     */
    protected function backOff(AiHubTenant $tenant, string $why, array $context): ?string
    {
        Log::warning("AiHubAccounts: could not open the workspace hub account, {$why}", $context);

        $this->remember($tenant, ['error' => [
            'reason' => $why,
            'status' => $context['status'] ?? null,
            'at' => now()->toIso8601String(),
        ]]);

        Cache::put($this->retryKey($tenant), true, self::RETRY_AFTER_SECONDS);

        return null;
    }

    /** @param  array<string, mixed>  $values */
    protected function remember(AiHubTenant $tenant, array $values): void
    {
        $metadata = $tenant->metadata ?? [];
        $metadata['hub_account_attempt'] = $values;

        $tenant->update(['metadata' => $metadata]);
    }

    /**
     * The workspace's name, as the Back Office shows it: billing name, then
     * the owner's name, then our own label for the scope.
     */
    protected function displayName(AiHubTenant $tenant): string
    {
        $workspace = $tenant->tenant;

        $name = trim((string) ($workspace?->billing_name ?: $workspace?->user?->name ?: ''));

        return $name !== '' ? $name : (string) ($tenant->name ?: self::externalRef($tenant));
    }

    /** @return array<string, string> */
    protected function authHeaders(): array
    {
        return [
            'Authorization' => 'Bearer '.(string) AiAgentHubConfig::tenantToken(),
            'Accept' => 'application/json',
        ];
    }

    protected function lockKey(AiHubTenant $tenant): string
    {
        return "ai_hub:hub_account:{$tenant->id}";
    }

    protected function retryKey(AiHubTenant $tenant): string
    {
        return "ai_hub:hub_account:retry_after:{$tenant->id}";
    }
}

<?php

namespace App\Services\Integrations;

use App\Enums\Flow\NodeType;
use App\Enums\Integration\IntegrationProvider;
use App\Exceptions\UpstreamServiceException;
use App\Models\FlowNode;
use App\Models\Integration;
use Illuminate\Support\Facades\Log;

/**
 * Connecting, editing and removing a workspace's external apps.
 *
 * The one rule that shapes all of it: nothing is stored until the provider has
 * accepted it. create() and update() call verify() on the unsaved values and
 * only write when that passes, so the page can never hold an account that looks
 * connected and fails the first time a bot uses it.
 */
class IntegrationService
{
    /**
     * @param  array<string, mixed>  $input  validated: name, enabled, credentials, settings
     *
     * @throws UpstreamServiceException when the provider refuses the credentials
     */
    public function create(int $tenantId, IntegrationProvider $provider, array $input): Integration
    {
        $integration = new Integration([
            'tenant_id' => $tenantId,
            'provider' => $provider,
            'name' => trim((string) ($input['name'] ?? $provider->label())),
            'enabled' => (bool) ($input['enabled'] ?? true),
            'credentials' => $this->pick($provider->secretKeys(), (array) ($input['credentials'] ?? [])),
            'settings' => $this->pick($provider->settingKeys(), (array) ($input['settings'] ?? [])),
            'meta' => [],
        ]);

        $account = IntegrationDrivers::for($integration)->verify();

        $integration->forceFill([
            'meta' => ['account' => $account],
            'verified_at' => now(),
        ])->save();

        $this->registerWebhook($integration);

        return $integration->fresh();
    }

    /**
     * A blank secret means "keep the one stored" — the form never receives the
     * stored value, so it cannot send it back. Anything that reaches the
     * provider (a new secret, a changed pixel id, the sandbox switch) is
     * re-verified before it replaces what worked.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws UpstreamServiceException
     */
    public function update(Integration $integration, array $input): Integration
    {
        $provider = $integration->provider;
        $previous = clone $integration;

        $credentials = $integration->credentials ?? [];
        $credentialsChanged = false;

        foreach ($provider->secretKeys() as $key) {
            $value = $input['credentials'][$key] ?? null;

            if (is_string($value) && trim($value) !== '' && trim($value) !== ($credentials[$key] ?? null)) {
                $credentials[$key] = trim($value);
                $credentialsChanged = true;
            }
        }

        $settings = $integration->settings ?? [];
        $settingsChanged = false;

        foreach ($provider->settingKeys() as $key) {
            if (! array_key_exists($key, (array) ($input['settings'] ?? []))) {
                continue;
            }

            $value = $input['settings'][$key];

            if (($settings[$key] ?? null) !== $value) {
                $settings[$key] = $value;
                $settingsChanged = true;
            }
        }

        $integration->credentials = $credentials;
        $integration->settings = $settings;

        if (array_key_exists('name', $input) && trim((string) $input['name']) !== '') {
            $integration->name = trim((string) $input['name']);
        }

        if (array_key_exists('enabled', $input)) {
            $integration->enabled = (bool) $input['enabled'];
        }

        $reconnected = $credentialsChanged || $settingsChanged;

        if ($reconnected) {
            $account = IntegrationDrivers::for($integration)->verify();

            $integration->meta = array_merge($integration->meta ?? [], ['account' => $account]);
            $integration->verified_at = now();
            $integration->last_error = null;
            $integration->last_error_at = null;
        }

        $integration->save();

        // A new key (or a switch between sandbox and production) is a
        // different account at the provider, so the webhook it had belongs to
        // the old one. The old registration is removed with the old key.
        if ($reconnected && $previous->credentials !== $integration->credentials || $previous->setting('sandbox') !== $integration->setting('sandbox')) {
            $this->unregisterWebhook($previous);
            $this->registerWebhook($integration);
        }

        return $integration->fresh();
    }

    /**
     * Re-check the stored credentials on request.
     *
     * Also the second chance for a webhook that could not be registered when
     * the account was connected — the usual cause is a URL the provider could
     * not reach at that moment.
     *
     * @throws UpstreamServiceException
     */
    public function test(Integration $integration): Integration
    {
        try {
            $account = IntegrationDrivers::for($integration)->verify();
        } catch (UpstreamServiceException $e) {
            $integration->recordError($e->getMessage());

            throw $e;
        }

        $integration->forceFill([
            'meta' => array_merge($integration->meta ?? [], ['account' => $account]),
            'verified_at' => now(),
            'last_error' => null,
            'last_error_at' => null,
        ])->save();

        if (empty($integration->meta['webhook']['webhook_ids'] ?? null)) {
            $this->registerWebhook($integration);
        }

        return $integration->fresh();
    }

    public function delete(Integration $integration): void
    {
        $this->unregisterWebhook($integration);

        $integration->delete();
    }

    /**
     * Which flows point at each integration, for the "used by" line on a card
     * and the warning before a delete.
     *
     * Read from node data in PHP rather than with a JSON query: the column is
     * searched differently by MySQL and SQLite, and a tenant has tens of
     * payment and pixel nodes, not thousands.
     *
     * @return array<int, list<array{id: int, name: string}>>
     */
    public function usageByIntegration(int $tenantId): array
    {
        $nodes = FlowNode::query()
            ->whereIn('type', [NodeType::Payment->value, NodeType::Pixel->value])
            ->whereHas('flow', fn ($query) => $query->where('tenant_id', $tenantId))
            ->with('flow:id,name')
            ->get(['id', 'flow_id', 'type', 'data']);

        $usage = [];

        foreach ($nodes as $node) {
            $data = $node->data ?? [];
            $ids = $node->type === NodeType::Payment
                ? [(int) ($data['integration_id'] ?? 0)]
                : array_map('intval', (array) ($data['integration_ids'] ?? []));

            foreach ($ids as $id) {
                if ($id > 0 && $node->flow !== null) {
                    $usage[$id][$node->flow_id] = ['id' => (int) $node->flow_id, 'name' => (string) $node->flow->name];
                }
            }
        }

        return array_map('array_values', $usage);
    }

    /**
     * @param  list<string>  $keys
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function pick(array $keys, array $values): array
    {
        $picked = [];

        foreach ($keys as $key) {
            if (! array_key_exists($key, $values) || $values[$key] === null || $values[$key] === '') {
                continue;
            }

            $picked[$key] = is_string($values[$key]) ? trim($values[$key]) : $values[$key];
        }

        return $picked;
    }

    private function registerWebhook(Integration $integration): void
    {
        $driver = IntegrationDrivers::for($integration);
        $url = $integration->webhookUrl();

        if (! $driver instanceof ManagesWebhooks || $url === null) {
            return;
        }

        $meta = $integration->meta ?? [];

        try {
            $meta['webhook'] = $driver->registerWebhook($url, $integration->webhook_token);
            unset($meta['webhook_error']);
        } catch (\Throwable $e) {
            // Not fatal, and deliberately not reported as a failed connection:
            // the key works, charges can be created, and the sweep confirms
            // them within minutes. The page shows this so it can be retried.
            $meta['webhook_error'] = $e instanceof UpstreamServiceException
                ? $e->getMessage()
                : 'Não foi possível registrar a confirmação automática de pagamentos.';

            Log::warning('IntegrationService: webhook registration failed', [
                'integration_id' => $integration->id,
                'provider' => $integration->provider->value,
                'error' => $e->getMessage(),
            ]);
        }

        $integration->forceFill(['meta' => $meta])->saveQuietly();
    }

    private function unregisterWebhook(Integration $integration): void
    {
        $driver = IntegrationDrivers::for($integration);

        if (! $driver instanceof ManagesWebhooks) {
            return;
        }

        try {
            $driver->unregisterWebhook((array) (($integration->meta ?? [])['webhook'] ?? []));
        } catch (\Throwable $e) {
            Log::info('IntegrationService: could not remove a webhook at the provider', [
                'integration_id' => $integration->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}

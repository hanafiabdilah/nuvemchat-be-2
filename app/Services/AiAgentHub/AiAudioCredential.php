<?php

namespace App\Services\AiAgentHub;

use App\Models\AiHubAgent;
use App\Models\AiHubProviderCredential;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Which key transcribes a voice note and which key speaks a reply: always one
 * of the workspace's own.
 *
 * `inputAudio` and `responseAudio` used to travel without a
 * `providerCredentialId` unless a flow node named one (AI-suggest and call
 * summaries never do). The hub then picked a key for that provider by itself
 * — the newest one in the account. Every workspace shares that account, so
 * the newest OpenAI key could be another customer's: when the agent's own
 * model was not OpenAI, voice notes were transcribed on someone else's key,
 * and since 13 Nov 2026, when that key stopped working, "Suggest reply" on an
 * audio message failed for a workspace that had done nothing wrong.
 *
 * So the id is always filled here, from the workspace's own keys:
 *  1. the one the node named, when it is this workspace's and usable;
 *  2. the agent's own key, when it is for the same provider;
 *  3. the newest of the workspace's own keys for that provider;
 *  4. a rented key of the workspace — only when the agent itself runs on a
 *     rented key, so the run is billed to the balance. A workspace paying its
 *     own provider must not start spending the platform's key on audio.
 *
 * None of those = the workspace holds no key that can do this, and the block
 * is dropped. That is not a downgrade: the alternative is the hub choosing a
 * key that is not theirs.
 */
final class AiAudioCredential
{
    /**
     * The audio block with the workspace's own key named, or [] when the
     * workspace has no usable key for that provider.
     *
     * @param  array<string, mixed>  $block  `inputAudio` or `responseAudio`
     * @return array<string, mixed>
     */
    public static function scope(array $block, AiHubAgent $agent): array
    {
        if ($block === []) {
            return [];
        }

        // The hub's own default when no provider is named is OpenAI.
        $provider = strtoupper((string) ($block['provider'] ?? '')) ?: 'OPENAI';
        $requested = isset($block['providerCredentialId']) ? (string) $block['providerCredentialId'] : null;

        $id = self::credentialFor($agent, $provider, $requested);

        if ($id === null) {
            return [];
        }

        $block['providerCredentialId'] = $id;

        return $block;
    }

    /** The hub id of the workspace key to use, or null when there is none. */
    public static function credentialFor(AiHubAgent $agent, string $provider, ?string $requested = null): ?string
    {
        $provider = strtoupper($provider);
        $usable = self::usable($agent, $provider);

        if ($requested !== null && $requested !== '') {
            if ($usable->contains('hub_provider_credential_id', $requested)) {
                return $requested;
            }

            // A key of another workspace, a deleted one, or one still waiting
            // for its real secret. Never forwarded: the hub would find it in
            // the shared account whoever it belongs to.
            Log::warning('AiAudioCredential: the audio key named is not a usable key of this workspace, ignoring it', [
                'ai_hub_agent_id' => $agent->id,
                'ai_hub_tenant_id' => $agent->ai_hub_tenant_id,
                'provider' => $provider,
            ]);
        }

        $own = $agent->providerCredential;

        if ($own && $usable->contains('id', $own->id)) {
            return $own->hub_provider_credential_id;
        }

        $pick = $usable->first(fn (AiHubProviderCredential $credential) => ! $credential->isRented())
            ?? ($own?->isRented() ? $usable->first() : null);

        return $pick?->hub_provider_credential_id;
    }

    /**
     * The workspace's keys for a provider that a run can use, newest first.
     *
     * @return Collection<int, AiHubProviderCredential>
     */
    private static function usable(AiHubAgent $agent, string $provider): Collection
    {
        return AiHubProviderCredential::query()
            ->where('ai_hub_tenant_id', $agent->ai_hub_tenant_id)
            ->where('provider', $provider)
            ->where('status', 'ACTIVE')
            ->whereNotNull('hub_provider_credential_id')
            ->orderByDesc('id')
            ->get()
            // Re-registered after the hub was rebuilt and still waiting for
            // the customer's real key: the provider would refuse it.
            ->reject(fn (AiHubProviderCredential $credential) => (bool) (($credential->metadata ?? [])['needs_key'] ?? false))
            ->values();
    }
}

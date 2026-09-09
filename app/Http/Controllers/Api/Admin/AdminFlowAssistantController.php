<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\FlowAssistant\FlowAssistantConfig;
use App\Services\FlowAssistant\FlowAssistantService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The flow assistant's platform credentials, from the Back Office.
 *
 * ── Why this is not a block in AdminSettingsController ──
 *
 * Everything on that screen is a row write: type a value, it is stored, done.
 * Saving here is a provisioning action — it registers the OpenAI key as a
 * credential at the AI Hub and creates or updates the platform's assistant
 * agent, and either call can fail upstream. Folded into the bulk settings
 * update, one unreachable hub would fail an operator's unrelated edit to, say,
 * the Instagram secret. It reads as its own card and it fails as its own card.
 */
class AdminFlowAssistantController extends Controller
{
    public function __construct(
        private readonly FlowAssistantService $assistant,
    ) {}

    /**
     * Current configuration, with the key masked.
     *
     * `enabled` and `provisioned` are reported separately because they are
     * different problems with different fixes: the first is a decision an
     * operator made, the second is a setup that did not finish. A single
     * "working: false" would send someone to toggle a switch that is already on.
     */
    public function show(): JsonResponse
    {
        $key = FlowAssistantConfig::apiKey();

        return response()->json([
            'data' => [
                'enabled' => FlowAssistantConfig::enabled(),
                'model' => FlowAssistantConfig::model(),
                'api_key_set' => $key !== null,
                'api_key_preview' => $this->mask($key),
                'provisioned' => FlowAssistantConfig::agentExternalId() !== null,
                'hub_credential_id' => FlowAssistantConfig::hubCredentialId(),
                'agent_external_id' => FlowAssistantConfig::agentExternalId(),
                // The customer-facing switch is the plan feature, not this one.
                // Named here so nobody hunts for a second toggle after turning
                // this on and finding a workspace still cannot see the panel.
                'plan_feature' => 'flow_assistant',
            ],
        ]);
    }

    /**
     * Save the credentials and provision the assistant at the hub.
     *
     * The key is optional on purpose: an operator changing only the model must
     * not have to re-paste a secret they cannot read back, and a blank field is
     * how every other integration on this screen says "keep what is stored".
     */
    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'enabled' => ['sometimes', 'boolean'],
            'model' => ['sometimes', 'string', 'max:100'],
            'openai_api_key' => ['nullable', 'string', 'max:512'],
        ]);

        if (array_key_exists('enabled', $validated)) {
            Setting::set(FlowAssistantConfig::KEY_ENABLED, $validated['enabled'] ? '1' : '0');
        }

        // Provision whenever there is anything to provision with. Switching the
        // feature off is the one path that must not call the hub — an operator
        // turning it off is often doing exactly that because the hub is the
        // problem.
        $wantsProvision = ($validated['enabled'] ?? FlowAssistantConfig::enabled())
            && (! empty($validated['openai_api_key']) || FlowAssistantConfig::apiKey());

        if ($wantsProvision) {
            $this->assistant->provision(
                $validated['openai_api_key'] ?? null,
                $validated['model'] ?? null,
            );
        } elseif (array_key_exists('model', $validated)) {
            Setting::set(FlowAssistantConfig::KEY_MODEL, $validated['model']);
        }

        return $this->show();
    }

    /**
     * Prove the whole path end to end: hub reachable, credential valid, agent
     * present, model able to produce the envelope we parse.
     *
     * Deliberately a real turn rather than a ping. Every part of this can be
     * configured correctly and still fail at the one step that matters — a key
     * without quota, a model name the hub does not carry — and an operator who
     * is told "connection OK" and then watches customers get errors has been
     * told nothing.
     */
    public function test(): JsonResponse
    {
        if (! FlowAssistantConfig::ready()) {
            return response()->json([
                'data' => [
                    'ok' => false,
                    'message' => 'The flow assistant is not configured yet. Save an OpenAI key first.',
                ],
            ]);
        }

        try {
            $result = $this->assistant->ask(
                'Crie um fluxo mínimo: uma saudação e depois encerre a conversa.',
                ['flow' => null, 'tags' => [], 'agents' => [], 'ai_agents' => [], 'channels' => []],
            );

            $nodes = count($result['flow']['nodes'] ?? []);

            return response()->json([
                'data' => [
                    'ok' => $result['flow'] !== null,
                    'model' => FlowAssistantConfig::model(),
                    'nodes_generated' => $nodes,
                    'message' => $result['flow'] !== null
                        ? "The assistant answered and produced a valid {$nodes}-node flow."
                        : 'The assistant answered but produced no flow: ' . $result['reply'],
                ],
            ]);
        } catch (\Throwable $e) {
            // Back Office is exempt from the upstream-error sanitiser on
            // purpose: the operator here is the person who fixes the
            // integration, and the vendor's exact words are the only thing
            // that says which part is broken.
            return response()->json([
                'data' => [
                    'ok' => false,
                    'message' => $e->getMessage(),
                ],
            ]);
        }
    }

    private function mask(?string $value): ?string
    {
        if (! $value) {
            return null;
        }

        return strlen($value) <= 8
            ? str_repeat('•', strlen($value))
            : substr($value, 0, 4) . str_repeat('•', 6) . substr($value, -4);
    }
}

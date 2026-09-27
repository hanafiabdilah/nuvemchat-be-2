<?php

namespace App\Services\Flow;

use App\Enums\Integration\IntegrationCategory;
use App\Models\AiHubAgent;
use App\Models\Flow;
use App\Models\Integration;
use App\Models\LeadPipeline;
use App\Models\LeadStage;
use App\Models\Tag;
use App\Models\User;

/**
 * The real ids a flow in this workspace may point at.
 *
 * Half of `FlowBlueprint`'s validation rules are tenant-scoped `exists` checks —
 * a tag, an agent, an AI agent, a payment or invoice or pixel integration, a
 * lead stage, another flow. Anything writing a flow has to know which ids those
 * are, and there is no way to guess one: an invented id is not a wrong answer,
 * it is a save that fails.
 *
 * Extracted from the flow assistant so the assistant and the MCP tools offer
 * the same vocabulary. Two copies of this list would diverge the first time a
 * node type gained a reference, and the symptom would be a model confidently
 * producing flows that will not save.
 *
 * ⚠️ Read, never provisioned. A workspace that has never opened its sales board
 * has no pipeline, and being asked about a flow is not a reason to create one.
 */
final class FlowVocabulary
{
    /**
     * @param  int|null  $excludeFlowId  the flow being edited, which cannot continue into itself
     * @return array<string, list<array<string, mixed>>>
     */
    public static function forTenant(int $tenantId, ?int $excludeFlowId = null): array
    {
        return [
            'tags' => Tag::where('tenant_id', $tenantId)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Tag $tag) => ['id' => $tag->id, 'name' => $tag->name])
                ->all(),

            'agents' => User::where('tenant_id', $tenantId)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (User $user) => ['id' => $user->id, 'name' => $user->name])
                ->all(),

            'ai_agents' => AiHubAgent::query()
                ->whereIn('ai_hub_tenant_id', fn ($query) => $query
                    ->select('id')
                    ->from('ai_hub_tenants')
                    ->where('tenant_id', $tenantId))
                ->where('status', 'ACTIVE')
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (AiHubAgent $agent) => ['id' => $agent->id, 'name' => $agent->name])
                ->all(),

            'payment_integrations' => self::integrations($tenantId, IntegrationCategory::Payment),
            'invoice_integrations' => self::integrations($tenantId, IntegrationCategory::Invoice),
            'pixel_integrations' => self::integrations($tenantId, IntegrationCategory::Pixel),

            'flows' => Flow::where('tenant_id', $tenantId)
                ->when($excludeFlowId !== null, fn ($query) => $query->whereKeyNot($excludeFlowId))
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Flow $other) => ['id' => $other->id, 'name' => $other->name])
                ->all(),

            'lead_stages' => self::leadStages($tenantId),
        ];
    }

    /**
     * Enabled integrations of one kind, named the way the person knows them.
     * `payment_methods` rides along because whether "checkout" exists depends on
     * the provider, and a node asking OpenPix for a card link fails at the
     * moment a customer is waiting to pay.
     *
     * @return list<array<string, mixed>>
     */
    public static function integrations(int $tenantId, IntegrationCategory $category): array
    {
        return Integration::forTenant($tenantId)
            ->inCategory($category)
            ->where('enabled', true)
            ->orderBy('name')
            ->get()
            ->map(fn (Integration $integration) => array_filter([
                'id' => $integration->id,
                'name' => $integration->name,
                'provider' => $integration->provider->label(),
                'payment_methods' => $integration->provider->paymentMethods() ?: null,
            ]))
            ->values()
            ->all();
    }

    /** @return list<array<string, mixed>> */
    public static function leadStages(int $tenantId): array
    {
        return LeadPipeline::where('tenant_id', $tenantId)
            ->with('stages')
            ->orderByDesc('is_default')
            ->orderBy('position')
            ->get()
            ->flatMap(fn (LeadPipeline $pipeline) => $pipeline->stages->map(fn (LeadStage $stage) => [
                'id' => $stage->id,
                'name' => $stage->name,
                'kind' => $stage->kind->value,
                'pipeline' => $pipeline->name,
            ]))
            ->values()
            ->all();
    }
}

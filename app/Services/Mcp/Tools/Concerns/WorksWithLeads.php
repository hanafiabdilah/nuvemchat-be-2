<?php

namespace App\Services\Mcp\Tools\Concerns;

use App\Models\Lead;
use App\Models\LeadStage;
use App\Models\User;
use App\Services\Mcp\Tools\ToolException;

/**
 * The parts every lead tool needs, scoped the way LeadController scopes them:
 * on `leads.tenant_id`, deliberately wider than conversations (see LeadUpdated
 * for why). Anything that belongs to another workspace reads as absent.
 */
trait WorksWithLeads
{
    protected function findLead(mixed $id, User $user): Lead
    {
        $lead = Lead::with(['contact', 'owner', 'stage'])
            ->where('tenant_id', $user->tenant_id)
            ->find((int) $id);

        if (! $lead) {
            throw new ToolException("There is no lead with id {$id} in this workspace. Call list_leads to see what there is.");
        }

        return $lead;
    }

    protected function findStage(mixed $id, User $user): LeadStage
    {
        $stage = LeadStage::whereKey((int) $id)
            ->whereHas('pipeline', fn ($q) => $q->where('tenant_id', $user->tenant_id))
            ->first();

        if (! $stage) {
            throw new ToolException("There is no stage with id {$id} in this workspace. Call list_lead_pipelines to see the stages.");
        }

        return $stage;
    }

    /** Only someone in this workspace may own a card. */
    protected function resolveOwnerId(mixed $ownerId, User $user): ?int
    {
        if ($ownerId === null) {
            return null;
        }

        $owner = User::where('tenant_id', $user->tenant_id)->find((int) $ownerId);

        if (! $owner) {
            throw new ToolException("There is no person with id {$ownerId} in this workspace. Call list_lead_pipelines for the list of people who can own a lead.");
        }

        return $owner->id;
    }

    /** @return array<string, mixed> */
    protected function describeLead(Lead $lead): array
    {
        $contact = $lead->getRelationValue('contact');
        $stage = $lead->getRelationValue('stage');

        return [
            'id' => $lead->id,
            'title' => $lead->displayTitle(),
            'status' => $lead->status->value,
            'source' => $lead->source->value,
            'pipeline_id' => $lead->pipeline_id,
            'stage' => $stage ? ['id' => $stage->id, 'name' => $stage->name, 'kind' => $stage->kind->value] : ['id' => $lead->stage_id],
            'value' => $lead->value !== null ? (float) $lead->value : null,
            'currency' => $lead->currency,
            'temperature' => $lead->temperature->value,
            'owner' => $lead->getRelationValue('owner')
                ? ['id' => $lead->owner->id, 'name' => $lead->owner->name]
                : null,
            'contact' => $contact ? [
                'id' => $contact->id,
                'name' => $contact->name,
                'channel' => $contact->channel instanceof \BackedEnum ? $contact->channel->value : $contact->channel,
            ] : null,
            'last_inbound_at' => $lead->last_inbound_at?->toIso8601String(),
            'stage_changed_at' => $lead->stage_changed_at?->toIso8601String(),
            'lost_reason' => $lead->lost_reason,
            'closed_at' => $lead->closed_at?->toIso8601String(),
            'created_at' => $lead->created_at?->toIso8601String(),
        ];
    }
}

<?php

namespace App\Services\Mcp\Tools;

use App\Enums\Billing\Feature;
use App\Enums\Lead\StageKind;
use App\Events\LeadUpdated;
use App\Models\AuditLog;
use App\Models\McpConnection;
use App\Models\User;
use App\Services\Lead\TemperatureScorer;
use App\Services\Mcp\Scopes;
use App\Services\Mcp\Tools\Concerns\WorksWithLeads;

/**
 * Edit a lead and/or move it to another stage.
 *
 * One tool for both because a model asked "mark Maria's deal as won at R$ 3k"
 * wants one call, not two. The move still goes through Lead::moveToStage() —
 * the same path as the drag — so it writes the stage history the conversion
 * report is built from, stamps closed_at, and fires the webhook, with the
 * person who authorised this editor as the actor.
 */
class UpdateLeadTool extends Tool
{
    use WorksWithLeads;

    public function __construct(
        private readonly TemperatureScorer $scorer,
    ) {}

    public function name(): string
    {
        return 'update_lead';
    }

    public function title(): string
    {
        return 'Update a lead';
    }

    public function description(): string
    {
        return 'Change a lead\'s title, value or owner, and/or move it to another stage. Moving to a stage of kind "won" or '
            .'"lost" closes the lead; give "lost_reason" when marking it lost. Stage ids come from list_lead_pipelines. '
            .'Send only the fields you want to change; "owner_id": null unassigns. Leads cannot be deleted here.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'lead_id' => ['type' => 'integer', 'description' => 'The lead id, from list_leads.'],
                'title' => ['type' => ['string', 'null'], 'maxLength' => 255, 'description' => 'New title; null clears it (the contact\'s name is shown instead).'],
                'value' => ['type' => ['number', 'null'], 'minimum' => 0, 'description' => 'Expected value in the workspace\'s currency (major units); null clears it.'],
                'owner_id' => ['type' => ['integer', 'null'], 'description' => 'New owner (ids from list_lead_pipelines); null unassigns.'],
                'stage_id' => ['type' => 'integer', 'description' => 'Move the lead to this stage.'],
                'lost_reason' => ['type' => 'string', 'maxLength' => 255, 'description' => 'Why it was lost, when moving to a "lost" stage.'],
            ],
            'required' => ['lead_id'],
            'additionalProperties' => false,
        ];
    }

    public function scope(): string
    {
        return Scopes::LEADS_WRITE;
    }

    public function permission(): string
    {
        return 'leads.update';
    }

    public function feature(): Feature
    {
        return Feature::Crm;
    }

    public function annotations(): array
    {
        return ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true];
    }

    public function run(array $arguments, McpConnection $connection, User $user): ToolResult
    {
        $lead = $this->findLead($arguments['lead_id'] ?? 0, $user);

        // Everything is checked before anything is written, so a refused stage
        // does not leave the title half-changed.
        $changes = [];

        if (array_key_exists('title', $arguments)) {
            $title = $arguments['title'] === null ? null : trim((string) $arguments['title']);
            $changes['title'] = $title === '' ? null : mb_substr((string) $title, 0, 255);
        }

        if (array_key_exists('value', $arguments)) {
            if ($arguments['value'] !== null && (! is_numeric($arguments['value']) || $arguments['value'] < 0)) {
                throw new ToolException('"value" must be a number of zero or more, or null.');
            }

            $changes['value'] = $arguments['value'] === null ? null : (float) $arguments['value'];
        }

        if (array_key_exists('owner_id', $arguments)) {
            $changes['owner_id'] = $this->resolveOwnerId($arguments['owner_id'], $user);
        }

        $stage = isset($arguments['stage_id']) ? $this->findStage($arguments['stage_id'], $user) : null;
        $lostReason = isset($arguments['lost_reason']) ? mb_substr(trim((string) $arguments['lost_reason']), 0, 255) : null;

        if ($lostReason && $stage && $stage->kind !== StageKind::Lost) {
            throw new ToolException('"lost_reason" only applies when moving the lead to a stage of kind "lost".');
        }

        if ($changes === [] && $stage === null) {
            throw new ToolException('Nothing to change: send at least one of title, value, owner_id or stage_id.');
        }

        $fromStage = $lead->stage_id;

        if ($changes !== []) {
            $lead->update($changes);
        }

        $moved = $stage !== null && $stage->id !== $lead->stage_id;

        if ($moved) {
            $lead->moveToStage($stage, $user, $lostReason ?: null);
            // Moving a card is itself a signal of life — rescore now, as the
            // dashboard does, rather than at the top of the next hour.
            $this->scorer->apply($lead);
        }

        broadcast(new LeadUpdated($lead, moved: $moved));

        AuditLog::record(
            'mcp.lead.updated',
            "Updated lead #{$lead->id} from {$connection->client_name}",
            [
                'tenant_id' => $user->tenant_id,
                'lead_id' => $lead->id,
                'fields' => array_keys($changes),
                'from_stage_id' => $moved ? $fromStage : null,
                'to_stage_id' => $moved ? $stage->id : null,
                'mcp_connection_id' => $connection->id,
            ],
            $user,
        );

        $lead = $this->findLead($lead->id, $user);

        return ToolResult::data(
            $this->describeLead($lead),
            $moved
                ? "Lead \"{$lead->displayTitle()}\" moved to \"{$stage->name}\"."
                : "Lead \"{$lead->displayTitle()}\" updated.",
        );
    }
}

<?php

namespace App\Services\Mcp\Tools;

use App\Enums\Billing\Feature;
use App\Models\McpConnection;
use App\Models\User;
use App\Services\Mcp\Scopes;
use App\Services\Mcp\Tools\Concerns\WorksWithLeads;

/** One lead, with its stage history and the conversations attached to it. */
class GetLeadTool extends Tool
{
    use WorksWithLeads;

    public function name(): string
    {
        return 'get_lead';
    }

    public function title(): string
    {
        return 'Read a lead';
    }

    public function description(): string
    {
        return 'Read one lead in full: stage, value, owner, contact, every stage it passed through (with who moved it and '
            .'when) and the conversations attached to it. Message contents are not included.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'lead_id' => ['type' => 'integer', 'description' => 'The lead id, from list_leads.'],
            ],
            'required' => ['lead_id'],
            'additionalProperties' => false,
        ];
    }

    public function scope(): string
    {
        return Scopes::LEADS_READ;
    }

    public function permission(): string
    {
        return 'leads.view';
    }

    public function feature(): Feature
    {
        return Feature::Crm;
    }

    public function run(array $arguments, McpConnection $connection, User $user): ToolResult
    {
        $lead = $this->findLead($arguments['lead_id'] ?? 0, $user)
            ->load(['conversations', 'stageEvents.user']);

        return ToolResult::data(
            $this->describeLead($lead) + [
                'stage_history' => $lead->stageEvents->map(fn ($event) => [
                    'to_stage_id' => $event->to_stage_id,
                    'to_stage_name' => $event->to_stage_name,
                    // Null = moved by the platform (a flow, a rule, the API).
                    'moved_by' => $event->getRelationValue('user')?->name,
                    'at' => $event->created_at?->toIso8601String(),
                ])->values()->all(),
                'conversations' => $lead->conversations->map(fn ($conversation) => [
                    'id' => $conversation->id,
                    'status' => $conversation->status->value,
                    'last_message_at' => $conversation->last_message_at?->toIso8601String(),
                ])->values()->all(),
            ],
            "Lead \"{$lead->displayTitle()}\" (#{$lead->id}).",
        );
    }
}

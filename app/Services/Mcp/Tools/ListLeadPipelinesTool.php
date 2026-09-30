<?php

namespace App\Services\Mcp\Tools;

use App\Enums\Billing\Feature;
use App\Models\LeadPipeline;
use App\Models\McpConnection;
use App\Models\User;
use App\Services\Lead\PipelineProvisioner;
use App\Services\Mcp\Scopes;

/**
 * The shape of the funnel — pipelines and their stages — plus who can own a
 * lead. Everything the other lead tools take an id for, in one call.
 */
class ListLeadPipelinesTool extends Tool
{
    public function __construct(
        private readonly PipelineProvisioner $pipelines,
    ) {}

    public function name(): string
    {
        return 'list_lead_pipelines';
    }

    public function title(): string
    {
        return 'List lead pipelines';
    }

    public function description(): string
    {
        return 'The sales funnel of this workspace: each pipeline with its stages in order (id, name, and kind — "open", '
            .'"won" or "lost"), how many open leads sit in each stage, and the people who can own a lead. '
            .'Call it before moving a lead or filtering by stage; refer to stages by id, never by name.';
    }

    public function inputSchema(): array
    {
        return $this->noArguments();
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
        // Same as the dashboard: a workspace that never opened the board still
        // has a funnel the moment anybody asks for it.
        $this->pipelines->ensureDefault((int) $user->tenant_id);

        $pipelines = LeadPipeline::where('tenant_id', $user->tenant_id)
            ->with(['stages' => fn ($q) => $q->withCount(['leads' => fn ($l) => $l->where('status', 'open')])->orderBy('position')])
            ->orderByDesc('is_default')
            ->orderBy('position')
            ->get();

        return ToolResult::data([
            'pipelines' => $pipelines->map(fn (LeadPipeline $pipeline) => [
                'id' => $pipeline->id,
                'name' => $pipeline->name,
                'is_default' => (bool) $pipeline->is_default,
                'stages' => $pipeline->stages->map(fn ($stage) => [
                    'id' => $stage->id,
                    'name' => $stage->name,
                    'kind' => $stage->kind->value,
                    'position' => $stage->position,
                    'open_leads' => (int) $stage->leads_count,
                ])->values()->all(),
            ])->values()->all(),
            'owners' => User::where('tenant_id', $user->tenant_id)->orderBy('name')->get(['id', 'name'])
                ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name])->values()->all(),
        ], $pipelines->count().' pipeline(s).');
    }
}

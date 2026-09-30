<?php

namespace App\Services\Mcp\Tools;

use App\Enums\Billing\Feature;
use App\Enums\Lead\LeadStatus;
use App\Enums\Lead\Temperature;
use App\Models\Lead;
use App\Models\McpConnection;
use App\Models\User;
use App\Services\Mcp\Scopes;
use App\Services\Mcp\Tools\Concerns\WorksWithLeads;

/** Leads, filtered the way the board filters them. */
class ListLeadsTool extends Tool
{
    use WorksWithLeads;

    public function name(): string
    {
        return 'list_leads';
    }

    public function title(): string
    {
        return 'List leads';
    }

    public function description(): string
    {
        return 'List leads in this workspace, most recently moved first, with their stage, value, temperature (cold, warm, '
            .'hot), owner and contact. Filter by pipeline, stage, status, owner ("none" for unassigned), temperature or '
            .'a search on the title and contact.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'pipeline_id' => ['type' => 'integer', 'description' => 'Only this pipeline (from list_lead_pipelines).'],
                'stage_id' => ['type' => 'integer', 'description' => 'Only this stage (from list_lead_pipelines).'],
                'status' => ['type' => 'string', 'enum' => array_column(LeadStatus::cases(), 'value'), 'description' => 'Only open, won or lost leads.'],
                'owner_id' => ['type' => ['integer', 'string'], 'description' => 'Only leads owned by this person id, or "none" for leads nobody owns.'],
                'temperature' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => array_column(Temperature::cases(), 'value')], 'description' => 'Only these temperatures.'],
                'search' => ['type' => 'string', 'maxLength' => 200, 'description' => 'Title, contact name or contact number contains this.'],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'description' => 'How many to return. Default 30.'],
                'page' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Page of results, starting at 1.'],
            ],
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
        $status = $arguments['status'] ?? null;

        if ($status !== null && LeadStatus::tryFrom((string) $status) === null) {
            throw new ToolException('"status" must be one of: '.implode(', ', array_column(LeadStatus::cases(), 'value')).'.');
        }

        $temperatures = array_values(array_filter(
            (array) ($arguments['temperature'] ?? []),
            fn ($band) => Temperature::tryFrom((string) $band) !== null,
        ));

        $owner = $arguments['owner_id'] ?? null;
        $search = trim((string) ($arguments['search'] ?? ''));
        $limit = min(100, max(1, (int) ($arguments['limit'] ?? 30)));
        $page = max(1, (int) ($arguments['page'] ?? 1));

        $leads = Lead::with(['contact', 'owner', 'stage'])
            ->where('tenant_id', $user->tenant_id)
            ->when(isset($arguments['pipeline_id']), fn ($q) => $q->where('pipeline_id', (int) $arguments['pipeline_id']))
            ->when(isset($arguments['stage_id']), fn ($q) => $q->where('stage_id', (int) $arguments['stage_id']))
            ->when($status, fn ($q, $s) => $q->where('status', $s))
            ->when($owner !== null && $owner !== '', fn ($q) => $owner === 'none'
                ? $q->whereNull('owner_id')
                : $q->where('owner_id', (int) $owner))
            ->when($temperatures !== [], fn ($q) => $q->whereIn('temperature', $temperatures))
            ->when($search !== '', function ($q) use ($search) {
                $term = '%'.addcslashes($search, '%_\\').'%';

                $q->where(fn ($inner) => $inner
                    ->where('title', 'like', $term)
                    ->orWhereHas('contact', fn ($c) => $c->where('name', 'like', $term)->orWhere('external_id', 'like', $term)));
            })
            ->orderByDesc('stage_changed_at')
            ->orderByDesc('id')
            ->paginate($limit, ['*'], 'page', $page);

        return ToolResult::data(
            [
                'leads' => collect($leads->items())->map(fn (Lead $lead) => $this->describeLead($lead))->values()->all(),
                'page' => $leads->currentPage(),
                'has_more' => $leads->hasMorePages(),
                'total' => $leads->total(),
            ],
            $leads->total().' lead(s); showing page '.$leads->currentPage().'.',
        );
    }
}

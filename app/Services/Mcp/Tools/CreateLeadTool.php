<?php

namespace App\Services\Mcp\Tools;

use App\Enums\Billing\Feature;
use App\Enums\Lead\LeadSource;
use App\Events\LeadUpdated;
use App\Models\AuditLog;
use App\Models\Contact;
use App\Models\McpConnection;
use App\Models\User;
use App\Services\Lead\LeadResolver;
use App\Services\Lead\LeadSettings;
use App\Services\Lead\TemperatureScorer;
use App\Services\Mcp\Scopes;
use App\Services\Mcp\Tools\Concerns\WorksWithLeads;

/**
 * Open a lead by hand — the same thing the dashboard's "New lead" does, with
 * the same invariant: one open lead per contact, refused with the reason.
 */
class CreateLeadTool extends Tool
{
    use WorksWithLeads;

    public function __construct(
        private readonly LeadResolver $resolver,
        private readonly TemperatureScorer $scorer,
    ) {}

    public function name(): string
    {
        return 'create_lead';
    }

    public function title(): string
    {
        return 'Create a lead';
    }

    public function description(): string
    {
        return 'Open a lead for a contact (find the contact id with find_contacts). It lands in the first stage of the '
            .'default pipeline; move it afterwards with update_lead. Refused when the contact already has an open lead.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'contact_id' => ['type' => 'integer', 'description' => 'The contact id, from find_contacts.'],
                'title' => ['type' => 'string', 'maxLength' => 255, 'description' => 'What the deal is. Defaults to the contact\'s name.'],
                'value' => ['type' => 'number', 'minimum' => 0, 'description' => 'Expected value, in the workspace\'s currency (major units, e.g. 1500.50).'],
                'owner_id' => ['type' => 'integer', 'description' => 'Who owns it (ids from list_lead_pipelines).'],
            ],
            'required' => ['contact_id'],
            'additionalProperties' => false,
        ];
    }

    public function scope(): string
    {
        return Scopes::LEADS_WRITE;
    }

    public function permission(): string
    {
        return 'leads.create';
    }

    public function feature(): Feature
    {
        return Feature::Crm;
    }

    public function annotations(): array
    {
        return ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false];
    }

    public function run(array $arguments, McpConnection $connection, User $user): ToolResult
    {
        $contact = Contact::where('tenant_id', $user->tenant_id)->find((int) ($arguments['contact_id'] ?? 0));

        if (! $contact) {
            throw new ToolException('There is no contact with that id in this workspace. Call find_contacts to look one up.');
        }

        if ($contact->is_group) {
            throw new ToolException('A group cannot become a lead.');
        }

        if ($existing = $this->resolver->openLeadFor($contact)) {
            throw new ToolException("This contact already has an open lead (#{$existing->id}). Use update_lead on it instead.");
        }

        $title = isset($arguments['title']) ? mb_substr(trim((string) $arguments['title']), 0, 255) : null;
        $value = isset($arguments['value']) ? (float) $arguments['value'] : null;

        if ($value !== null && $value < 0) {
            throw new ToolException('"value" cannot be negative.');
        }

        // Resolved before anything is written: a refused owner must not leave
        // a card behind.
        $ownerId = $this->resolveOwnerId($arguments['owner_id'] ?? null, $user);

        if (! LeadSettings::for($user->tenant)->acceptsOwnLeads()) {
            throw new ToolException('This workspace only accepts leads through its public API, so one cannot be added here. The setting is in the funnel settings of the dashboard.');
        }

        $lead = $this->resolver->open($contact, null, LeadSource::Manual);

        $lead->update(array_filter([
            'title' => $title ?: null,
            'value' => $value,
            'owner_id' => $ownerId,
        ], fn ($v) => $v !== null));

        $this->scorer->apply($lead);
        broadcast(new LeadUpdated($lead));

        AuditLog::record(
            'mcp.lead.created',
            "Opened lead #{$lead->id} for contact #{$contact->id} from {$connection->client_name}",
            ['tenant_id' => $user->tenant_id, 'lead_id' => $lead->id, 'mcp_connection_id' => $connection->id],
            $user,
        );

        $lead = $this->findLead($lead->id, $user);

        return ToolResult::data($this->describeLead($lead), "Opened lead \"{$lead->displayTitle()}\" (#{$lead->id}).");
    }
}

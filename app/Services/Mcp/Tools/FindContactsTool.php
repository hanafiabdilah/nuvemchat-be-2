<?php

namespace App\Services\Mcp\Tools;

use App\Enums\Billing\Feature;
use App\Models\Contact;
use App\Models\McpConnection;
use App\Models\User;
use App\Services\Lead\LeadResolver;
use App\Services\Mcp\Scopes;

/**
 * Look a contact up to open a lead for them.
 *
 * Under the leads scope and `leads.view` rather than a contacts surface of its
 * own: it exists so create_lead has an id to take, and the contact book itself
 * is readable by every member of a workspace in the dashboard anyway. Groups
 * are left out — a group cannot become a lead.
 */
class FindContactsTool extends Tool
{
    public function __construct(
        private readonly LeadResolver $resolver,
    ) {}

    public function name(): string
    {
        return 'find_contacts';
    }

    public function title(): string
    {
        return 'Find contacts';
    }

    public function description(): string
    {
        return 'Search this workspace\'s contacts by name or number (phone, username or channel id), to open a lead for '
            .'one with create_lead. Each result says whether the contact already has an open lead — a contact can have '
            .'only one.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'search' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 200, 'description' => 'Name or number contains this.'],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'description' => 'How many to return. Default 20.'],
            ],
            'required' => ['search'],
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
        $search = trim((string) ($arguments['search'] ?? ''));

        if (mb_strlen($search) < 2) {
            throw new ToolException('"search" needs at least two characters.');
        }

        $term = '%'.addcslashes($search, '%_\\').'%';
        $limit = min(50, max(1, (int) ($arguments['limit'] ?? 20)));

        $contacts = Contact::where('tenant_id', $user->tenant_id)
            ->where('is_group', false)
            ->where(fn ($q) => $q->where('name', 'like', $term)
                ->orWhere('external_id', 'like', $term)
                ->orWhere('username', 'like', $term))
            ->orderBy('name')
            ->limit($limit)
            ->get();

        return ToolResult::data([
            'contacts' => $contacts->map(fn (Contact $contact) => [
                'id' => $contact->id,
                'name' => $contact->name,
                'channel' => $contact->channel instanceof \BackedEnum ? $contact->channel->value : $contact->channel,
                'external_id' => $contact->external_id,
                'username' => $contact->username,
                'open_lead_id' => $this->resolver->openLeadFor($contact)?->id,
            ])->values()->all(),
        ], $contacts->count().' contact(s) found.');
    }
}

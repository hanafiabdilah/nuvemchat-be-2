<?php

namespace App\Enums\Billing;

/**
 * Canonical billing feature keys.
 *
 * Every key a plan may carry lives here, and the Back Office plan editor reads
 * the list from this enum (GET /admin/plans/meta) instead of keeping its own
 * copy. That indirection exists because the copy went stale: `crm` shipped with
 * the Leads module but never reached the editor's hardcoded array, and since
 * saving a plan replaces the whole `features` object, editing any plan in the
 * Back Office silently switched the funnel off for that plan's customers.
 *
 * Adding a case here is therefore the whole job — the editor picks it up, and
 * AdminPlanController rejects anything not listed.
 */
enum Feature: string
{
    case Chat = 'chat';
    case WhatsappApi = 'whatsapp_api';

    /**
     * The sales funnel: leads, pipelines, the board. Separate from `chat`
     * because a workspace can run a busy support inbox and never sell anything
     * from it — and because it is the natural thing to put on a higher tier.
     */
    case Crm = 'crm';

    case Flow = 'flow';

    /**
     * The AI that writes flows in the builder. Separate from `flow` because it
     * costs the platform money on every turn — it runs on the platform's own
     * OpenAI key, not the workspace's — and separate from `ai_agent_hub`
     * because that one gates agents the workspace configures and pays for. A
     * plan can sell the builder without selling the assistant, and giving the
     * assistant to a plan that has no `flow` would open a panel onto a page
     * that plan cannot reach.
     */
    case FlowAssistant = 'flow_assistant';

    case AiAgentHub = 'ai_agent_hub';
    case Statistics = 'statistics';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $f) => $f->value, self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::Chat => 'Chat inbox',
            self::WhatsappApi => 'WhatsApp API (API Way)',
            self::Crm => 'CRM / Leads',
            self::Flow => 'Flow automation',
            self::FlowAssistant => 'Flow AI assistant',
            self::AiAgentHub => 'AI Agent Hub',
            self::Statistics => 'Statistics',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Chat => 'The omnichannel inbox itself.',
            self::WhatsappApi => 'Marks an API Way workspace. Granted automatically by owning instances — it does NOT gate buying them, which is open to every tenant.',
            self::Crm => 'Lead board, pipelines and stages.',
            self::Flow => 'The visual automation builder.',
            self::FlowAssistant => 'Builds and edits flows from a description, in the builder. Runs on the platform\'s own OpenAI key, so every turn costs the platform — it does not consume the workspace\'s AI runs. Needs `flow` to be of any use.',
            self::AiAgentHub => 'AI agents, handoff and reply suggestions.',
            self::Statistics => 'The tenant analytics pages.',
        };
    }
}

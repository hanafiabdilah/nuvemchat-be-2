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
     * The CRM: one customer above the channel contacts, companies, custom
     * fields, leads, deals on pipeline boards, tasks with reminders, logged
     * activity, segments, reports and automations. Separate from `chat`
     * because a workspace can run a busy support inbox and never sell anything
     * from it — and because it is the natural thing to put on a higher tier.
     * Everything CRM sits behind it, the customer pages included.
     */
    case Crm = 'crm';

    case Flow = 'flow';

    /**
     * The AI that writes flows in the builder. Separate from `flow` so a plan
     * can sell the builder without the assistant; it runs on the workspace's
     * own provider key or one it rents (billed to the balance), chosen in the
     * builder. Giving it to a plan that has no `flow` would open a panel onto a
     * page that plan cannot reach.
     */
    case FlowAssistant = 'flow_assistant';

    case AiAgentHub = 'ai_agent_hub';
    case Statistics = 'statistics';

    /**
     * Driving Pingly from an LLM client (Claude, Codex) over the MCP endpoint.
     *
     * Separate from `flow` even though flows are all it reaches today: what it
     * sells is the door, not the room behind it, and the rooms will keep being
     * added. Separate from `flow_assistant` for the opposite reason to that
     * one — the assistant costs the platform a model call per turn, this costs
     * nothing per use because the tokens are billed to the customer's own
     * Claude or ChatGPT subscription.
     *
     * A plan with `mcp` but no `flow` connects successfully and finds no tools:
     * every tool re-checks the feature its surface lives behind.
     */
    case Mcp = 'mcp';

    /**
     * The workspace's own product catalog and the orders its AI takes: the
     * Products and Orders pages, and the flow node that sells from them
     * ("Agente IA com ações"). One key for all three because none is useful
     * alone — a catalog nothing sells from is a spreadsheet, and a selling
     * node with no catalog has nothing to offer.
     */
    case Catalog = 'catalog';

    /**
     * Publishing to, and moderating comments on, the workspace's Instagram,
     * TikTok and YouTube accounts. One key per network, so a plan can sell
     * each one on its own — and taken out of `chat`, which used to open all
     * three along with the inbox.
     *
     * Add-ons of `chat`, not replacements for it: their routes still sit inside
     * the inbox's group, and the Instagram and TikTok accounts are inbox
     * connections. A plan with one of these and no `chat` reaches nothing.
     */
    case InstagramManagement = 'instagram_management';
    case TiktokManagement = 'tiktok_management';
    case YoutubeManagement = 'youtube_management';

    /**
     * Broadcast campaigns, and sending one message to threads picked in the
     * inbox (which runs on the same engine). Taken out of `chat` so mass
     * sending can be sold on its own. Meta templates deliberately stay under
     * `chat`: WhatsApp Official cannot answer outside the 24-hour window
     * without one, so they are part of running the inbox at all.
     */
    case Campaigns = 'campaigns';

    /**
     * The add-ons that used to come with `chat` and were split out of it. A
     * plan that has `chat` keeps all of them unless someone turns one off —
     * the migration that introduced them granted them that way.
     *
     * @return list<self>
     */
    public static function splitFromChat(): array
    {
        return [self::InstagramManagement, self::TiktokManagement, self::YoutubeManagement, self::Campaigns];
    }

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
            self::Crm => 'CRM',
            self::Flow => 'Flow automation',
            self::FlowAssistant => 'Flow AI assistant',
            self::AiAgentHub => 'AI Agent Hub',
            self::Statistics => 'Statistics',
            self::Mcp => 'MCP (Claude / Codex)',
            self::Catalog => 'Product catalog & AI sales',
            self::InstagramManagement => 'Instagram management',
            self::TiktokManagement => 'TikTok management',
            self::YoutubeManagement => 'YouTube management',
            self::Campaigns => 'Campaigns',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Chat => 'The omnichannel inbox itself.',
            self::WhatsappApi => 'Marks an API Way workspace. Granted automatically by owning instances — it does NOT gate buying them, which is open to every tenant.',
            self::Crm => 'Customers across channels, companies, leads, deals on pipeline boards, tasks with reminders, logged activity, segments, reports and automations.',
            self::Flow => 'The visual automation builder.',
            self::FlowAssistant => 'Builds and edits flows from a description, in the builder. Runs on the workspace\'s own AI key or one it rents from the platform (charged to its balance), chosen in the builder. Needs `flow` to be of any use.',
            self::AiAgentHub => 'AI agents, handoff and reply suggestions.',
            self::Statistics => 'The tenant analytics pages.',
            self::Mcp => 'Lets people connect Claude, Codex or another MCP client to this workspace and work through it. Costs the platform nothing per use — the model tokens are billed to the customer\'s own AI subscription. Today it reaches the flow builder; each tool still needs the feature and the permission its own surface requires.',
            self::Catalog => 'Products, stock and orders, plus the "AI agent with actions" flow node that looks up the catalog, builds a cart and charges a Pix by itself. The node also needs `flow`.',
            self::InstagramManagement => 'Publish and schedule posts on the workspace\'s Instagram accounts and moderate their comments. Needs `chat`.',
            self::TiktokManagement => 'Publish and schedule videos on the workspace\'s TikTok accounts and moderate their comments. Needs `chat`.',
            self::YoutubeManagement => 'Connect YouTube channels, upload and schedule videos, and moderate their comments. Needs `chat`.',
            self::Campaigns => 'Broadcast campaigns, and sending one message to conversations picked in the inbox. Needs `chat`.',
        };
    }
}

<?php

namespace App\Services\FlowAssistant;

use App\Models\Setting;

/**
 * Credentials and settings for the flow builder's AI assistant, stored in the
 * `settings` table (DB-only) and managed by super-admin in Back Office →
 * Integrations → AI Hub. Same contract as every other platform-level
 * integration config: nothing here comes from `.env`.
 *
 * ── Why the hub ids live here and not in `ai_hub_*` ──
 *
 * The assistant runs on an agent that belongs to the *platform*, not to any
 * workspace. Every row in `ai_hub_tenants` / `ai_hub_agents` hangs off a
 * customer `tenant_id` and is listed to that customer, so parking the
 * assistant there would either need a fake tenant or leak a platform agent
 * into somebody's agent list — where they could edit its prompt or delete it,
 * and take the feature down for everyone. Two settings rows hold the same
 * information with none of that surface.
 *
 * ── Why OpenAI only ──
 *
 * Not a limitation waiting to be lifted. The assistant's whole job is emitting
 * a strict JSON envelope that must survive our validator, and the prompt is
 * tuned against one model family. A provider picker would turn "the assistant
 * is broken" into a question about which model an operator chose last.
 */
class FlowAssistantConfig
{
    public const KEY_ENABLED = 'flow_assistant.enabled';
    public const KEY_MODEL = 'flow_assistant.model';

    /**
     * The platform's own OpenAI key. Held because the hub needs it re-sent to
     * re-register the credential — after a hub rebuild, for instance, when
     * every id we hold stops resolving.
     */
    public const KEY_API_KEY = 'flow_assistant.openai_api_key';

    /** Hub-side ids, written by provisioning and read on every run. */
    public const KEY_HUB_CREDENTIAL_ID = 'flow_assistant.hub_credential_id';
    public const KEY_HUB_AGENT_ID = 'flow_assistant.hub_agent_id';
    public const KEY_AGENT_EXTERNAL_ID = 'flow_assistant.agent_external_id';

    /**
     * Hash of the system prompt last pushed to the hub. The prompt is built
     * from FlowBlueprint, so it changes whenever the flow format does — and a
     * deploy that adds a node type must not leave the assistant explaining the
     * old format until somebody notices and re-saves the settings page. The
     * hash makes the repair automatic: a mismatch re-pushes the prompt on the
     * next run, once.
     */
    public const KEY_PROMPT_HASH = 'flow_assistant.prompt_hash';

    /**
     * Default model. Chosen for instruction-following on a long, rule-dense
     * specification rather than for price: a cheaper model that invents a
     * field costs a repair round-trip, which is not cheaper.
     */
    public const DEFAULT_MODEL = 'gpt-4o';

    /** The agent's identity at the hub. Stable — never rebuild it per run. */
    public const AGENT_EXTERNAL_ID = 'platform_flow_assistant';

    /** How many past turns of the conversation the client may send back. */
    public const MAX_HISTORY_TURNS = 12;

    /** Ceiling on one message's text, so a pasted novel cannot become the prompt. */
    public const MAX_MESSAGE_CHARS = 4000;

    /**
     * How many times a blueprint that fails validation is handed back to the
     * model with its errors.
     *
     * Two, not more. The first repair fixes the ordinary slip (a missing
     * `variable_key`, a branch wired "yes" instead of "true"). A model still
     * wrong after the second is wrong about something the errors are not
     * telling it, and a third attempt spends the customer's time and the
     * platform's money to arrive at the same place.
     */
    public const MAX_REPAIRS = 2;

    public static function enabled(): bool
    {
        return (bool) Setting::get(self::KEY_ENABLED, false);
    }

    public static function model(): string
    {
        return Setting::get(self::KEY_MODEL) ?: self::DEFAULT_MODEL;
    }

    public static function apiKey(): ?string
    {
        return Setting::get(self::KEY_API_KEY) ?: null;
    }

    public static function hubCredentialId(): ?string
    {
        $id = Setting::get(self::KEY_HUB_CREDENTIAL_ID);

        return $id ? (string) $id : null;
    }

    public static function hubAgentId(): ?string
    {
        $id = Setting::get(self::KEY_HUB_AGENT_ID);

        return $id ? (string) $id : null;
    }

    public static function agentExternalId(): ?string
    {
        return Setting::get(self::KEY_AGENT_EXTERNAL_ID) ?: null;
    }

    public static function promptHash(): ?string
    {
        return Setting::get(self::KEY_PROMPT_HASH) ?: null;
    }

    /**
     * Whether the assistant can actually run: switched on, and provisioned.
     *
     * Both halves matter and they fail differently — a switch that is off is a
     * decision, a missing agent is an unfinished setup — so the Back Office
     * reports them separately. Everything else only needs the one answer.
     */
    public static function ready(): bool
    {
        return self::enabled()
            && self::apiKey() !== null
            && self::agentExternalId() !== null;
    }
}

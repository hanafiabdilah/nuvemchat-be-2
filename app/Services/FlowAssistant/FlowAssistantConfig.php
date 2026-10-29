<?php

namespace App\Services\FlowAssistant;

/**
 * The flow builder's AI assistant: the limits it works under.
 *
 * Nothing here is a credential any more. The assistant used to run on one
 * OpenAI key pasted into the Back Office, with the platform paying for every
 * turn of every workspace; now each workspace chooses its own key or rents one
 * — see {@see \App\Models\FlowAssistantSetting} — and what is left in this
 * class is what every workspace shares.
 */
class FlowAssistantConfig
{
    /**
     * The model offered first. Chosen for instruction-following on a long,
     * rule-dense specification rather than for price: a cheaper model that
     * invents a field costs a repair round-trip, which is not cheaper.
     */
    public const DEFAULT_MODEL = 'gpt-4o';

    /**
     * Suffix of each workspace's assistant agent at the hub. The full id is
     * scoped like every other agent we create — `{app}_{scope}_{suffix}` — so
     * two workspaces never reach for the same one.
     */
    public const AGENT_EXTERNAL_ID = 'flow_assistant';

    /** How many past turns of the conversation the client may send back. */
    public const MAX_HISTORY_TURNS = 12;

    /**
     * Ceiling on one message's text.
     *
     * It was 4000, and that was tuned for someone typing a request. People do
     * not only type here: they paste a prompt another AI wrote for them, and
     * those run long — so the ceiling was rejecting the requests the assistant
     * is best at, after the person had already written them.
     *
     * A long message costs one turn, not every turn: {@see FlowAssistantService}
     * clips each replayed history turn to 800 characters, so nothing here
     * accumulates in later prompts.
     *
     * ⚠️ 16000 is the most `flow_assistant_messages.content` can hold: it is a
     * MySQL `text` column (65,535 *bytes*), and 16000 characters is 64,000 bytes
     * even if every one of them is a 4-byte emoji. Raising this further means
     * widening that column to `mediumText` first — otherwise strict mode throws
     * on insert and the person loses the text they just wrote.
     */
    public const MAX_MESSAGE_CHARS = 16000;

    /**
     * How many times a blueprint that fails validation is handed back to the
     * model with its errors.
     *
     * Two, not more. The first repair fixes the ordinary slip (a missing
     * `variable_key`, a branch wired "yes" instead of "true"). A model still
     * wrong after the second is wrong about something the errors are not
     * telling it, and a third attempt spends the customer's time and money to
     * arrive at the same place.
     */
    public const MAX_REPAIRS = 2;
}

<?php

namespace App\Services\Flow;

/**
 * The Action node's vocabulary.
 *
 * Kept in one class rather than spread across the executor and the controller
 * for the same reason InteractiveNodes exists: the set of things this node can
 * do is a product decision, and a decision that lives in three switch
 * statements is a decision that drifts. The frontend mirrors it in
 * `lib/flowActionNodes.ts` — both sides have to agree, because the builder is
 * what decides which fields an author is even shown.
 *
 * What the three actions have in common is the question they answer: who ends
 * up holding this conversation. Assigning names a person, transferring names
 * the queue, and a note names nobody — which is why only the note lets the
 * flow carry on afterwards.
 */
class ActionNodes
{
    /** Hand the conversation to one named agent. */
    public const ASSIGN_AGENT = 'assign_agent';

    /** Drop it in the unassigned queue and ring for whoever is around. */
    public const TRANSFER_HUMAN = 'transfer_human';

    /** Write a line into the thread that the customer never sees. */
    public const INTERNAL_NOTE = 'internal_note';

    public const TYPES = [self::ASSIGN_AGENT, self::TRANSFER_HUMAN, self::INTERNAL_NOTE];

    /**
     * What to do when the agent named on the node cannot take the conversation
     * *right now*. Deliberately only about presence: an agent who was deleted
     * or whose connection access was revoked always falls back to the queue,
     * because that is not a shift the author can overrule.
     */
    public const UNAVAILABLE_QUEUE = 'queue';

    public const UNAVAILABLE_ASSIGN_ANYWAY = 'assign_anyway';

    public const UNAVAILABLE_MODES = [self::UNAVAILABLE_QUEUE, self::UNAVAILABLE_ASSIGN_ANYWAY];

    /**
     * Handoff reasons this node writes to `conversations.handoff_reason`.
     *
     * Codes, never free text: the dashboard renders the reason in the reader's
     * language (`lib/liveActivity.ts`), so a sentence typed into the builder
     * would arrive there as a raw string in whatever language it was typed.
     * An author who needs to explain something has the internal note, and that
     * sentence lands where the agent will actually read it.
     */
    public const REASON_REQUESTED = 'flow_requested';

    public const REASON_AGENT_UNAVAILABLE = 'flow_agent_unavailable';

    public const REASON_AGENT_OFFLINE = 'flow_agent_offline';

    /**
     * Info-note code for an assignment the flow made (`lib/infoMessage.ts`).
     *
     * Not the existing `conversation_assigned`: that one reads "Ana took this
     * conversation", and nobody took anything here. The thread arriving already
     * assigned is exactly the thing that needs explaining.
     */
    public const INFO_ASSIGNED_BY_FLOW = 'conversation_assigned_by_flow';

    /**
     * Whether an action ends the flow.
     *
     * Terminal is not cosmetic: once a person owns the conversation, a bot that
     * keeps sending is talking over them. The builder reads the same answer to
     * decide whether the node is even drawn with an output handle, so a flow
     * cannot be wired into a branch the engine will never run.
     */
    public static function isTerminal(?string $type): bool
    {
        return in_array($type, [self::ASSIGN_AGENT, self::TRANSFER_HUMAN], true);
    }

    /**
     * The configured fallback for an unreachable agent, defaulting to the queue.
     *
     * The queue is the default because it is the option that never leaves a
     * customer waiting on an empty chair.
     *
     * @param  array<string, mixed>  $parameters
     */
    public static function unavailableMode(array $parameters): string
    {
        $mode = $parameters['when_unavailable'] ?? null;

        return in_array($mode, self::UNAVAILABLE_MODES, true) ? $mode : self::UNAVAILABLE_QUEUE;
    }
}

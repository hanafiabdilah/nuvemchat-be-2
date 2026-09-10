<?php

namespace App\Services\Flow;

use App\Models\LeadStage;

/**
 * The Lead node's vocabulary.
 *
 * The node puts the conversation's contact on the sales board — opening a card
 * when they have no open one, through the same LeadResolver the automatic
 * creation uses, so the one-open-lead-per-contact rule holds whichever of the
 * two gets there first — and, when its author named a stage, moves the card
 * there. Mirrored on the frontend in `lib/leadNodes.ts`.
 */
final class LeadNodes
{
    /** Info-note code for a move the flow made (`lib/infoMessage.ts`). */
    public const INFO_STAGE_CHANGED = 'lead_stage_changed_by_flow';

    /** Variables the node writes, for a later condition or message. */
    public const VARIABLES = ['lead_id', 'lead_stage', 'lead_status'];

    /**
     * Whether "only forward" is on — the default.
     *
     * A flow runs for every new conversation, and a returning customer's card is
     * usually further along than wherever the flow's first lead node points.
     * Dragging it back to "Qualificação" because the bot greeted them again
     * would undo an agent's work without anyone noticing.
     *
     * @param  array<string, mixed>  $data
     */
    public static function onlyForward(array $data): bool
    {
        return ($data['only_forward'] ?? true) !== false;
    }

    /**
     * Whether moving from $current to $target is a step back.
     *
     * Only within one pipeline: across pipelines positions mean nothing, and a
     * node naming another pipeline's stage is an explicit decision to move the
     * card there.
     */
    public static function isBackward(?LeadStage $current, LeadStage $target): bool
    {
        return $current !== null
            && $current->pipeline_id === $target->pipeline_id
            && $current->position > $target->position;
    }
}

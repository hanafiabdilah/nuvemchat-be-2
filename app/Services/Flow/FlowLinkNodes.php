<?php

namespace App\Services\Flow;

/**
 * The Go-to-flow node: hand the conversation to another flow's start node.
 *
 * It moves the conversation's one flow state onto the other flow rather than
 * opening a second one. Everything that reads flow state — resumeFlow(),
 * stopFlow(), the live activity bar, the conversation sidebar — assumes one row
 * per conversation, and a second row would leave all of them reading whichever
 * one the database happened to return first.
 *
 * Terminal on the canvas: whatever the other flow does is where this one ends.
 */
final class FlowLinkNodes
{
    /**
     * Jumps in a row with no customer message in between. Past this it is two
     * flows pointing at each other, not a design — and without a cap it is a
     * recursion that ends in a stack overflow inside a webhook request.
     */
    public const MAX_CONSECUTIVE_JUMPS = 10;

    /** Reset by resumeFlow() whenever the customer writes. */
    public const JUMPS_KEY = '_flow_jumps';

    /** The flows this conversation came through, newest last — for debugging. */
    public const TRAIL_KEY = '_flow_trail';

    /**
     * Internal keys that describe the conversation rather than a node, and so
     * cross a jump. Everything else starting with `_` is keyed by a node id of
     * the flow being left and means nothing in the next one.
     */
    private const CONVERSATION_KEYS = ['_away_message_sent'];

    public const INFO_JUMPED = 'flow_jumped';

    public const INFO_TARGET_MISSING = 'flow_jump_failed';

    public const INFO_LOOP = 'flow_jump_loop';

    /** @param  array<string, mixed>  $data */
    public static function targetFlowId(array $data): ?int
    {
        $id = (int) ($data['flow_id'] ?? 0);

        return $id > 0 ? $id : null;
    }

    /**
     * Whether the variables collected so far (answers, API results, payment
     * details) go along. On by default: the usual reason to split a flow is
     * that it got long, and the second half still needs the customer's name.
     *
     * @param  array<string, mixed>  $data
     */
    public static function carriesVariables(array $data): bool
    {
        return ($data['carry_variables'] ?? true) !== false;
    }

    /**
     * The state the next flow starts with.
     *
     * @param  array<string, mixed>  $stateData
     * @return array<string, mixed>
     */
    public static function carriedState(array $stateData, bool $carryVariables): array
    {
        $kept = [];

        foreach ($stateData as $key => $value) {
            $key = (string) $key;

            if (str_starts_with($key, '_')) {
                if (in_array($key, self::CONVERSATION_KEYS, true)) {
                    $kept[$key] = $value;
                }

                continue;
            }

            if ($carryVariables) {
                $kept[$key] = $value;
            }
        }

        return $kept;
    }
}

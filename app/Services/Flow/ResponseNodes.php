<?php

namespace App\Services\Flow;

/**
 * The Response node's two outputs.
 *
 * A Response node asks a question and waits. Waiting was the whole node: if the
 * customer never came back, the flow simply sat there — no branch, no note, no
 * way for the author to say what should happen instead. Most of the time what
 * should happen is obvious (ask again, hand it to a person, close it), and the
 * only reason it was not expressible is that the node had one output.
 *
 * So it has two: `replied` is the path it always had, `timeout` is the one that
 * runs when the silence outlasts the node's own limit. The frontend mirrors
 * these names in `lib/responseNodeBranches.ts` — they are edge condition_values
 * and source handle ids at the same time, the way an interactive option's id is.
 */
class ResponseNodes
{
    /** The customer answered. Edges saved before the split carry no value at all. */
    public const BRANCH_REPLIED = 'replied';

    /** The customer went quiet for longer than `timeout_seconds`. */
    public const BRANCH_TIMEOUT = 'timeout';

    public const BRANCHES = [self::BRANCH_REPLIED, self::BRANCH_TIMEOUT];

    /**
     * Longest silence a node may wait out: 24 hours.
     *
     * Past a day the question has stopped being the same question — whatever
     * the customer was asking about has moved on, and a branch that fires then
     * arrives as a message out of nowhere.
     */
    public const MAX_TIMEOUT_SECONDS = 86400;

    /**
     * The node's no-reply limit in seconds, or 0 when it has none.
     *
     * Zero is the default and the answer for every node saved before this
     * existed: without a limit the node waits forever, which is what it has
     * always done.
     *
     * @param  array<string, mixed>  $data
     */
    public static function timeoutSeconds(array $data): int
    {
        $seconds = (int) ($data['timeout_seconds'] ?? 0);

        if ($seconds <= 0) {
            return 0;
        }

        return min($seconds, self::MAX_TIMEOUT_SECONDS);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function hasTimeout(array $data): bool
    {
        return self::timeoutSeconds($data) > 0;
    }
}

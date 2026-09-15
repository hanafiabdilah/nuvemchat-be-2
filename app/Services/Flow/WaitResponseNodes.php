<?php

namespace App\Services\Flow;

/**
 * The Wait for reply node ("Aguardar resposta").
 *
 * Waiting used to be a property of two other nodes. A Message node had a
 * `wait_for_reply` switch — on by default — that parked the flow on the
 * *following* node until the customer wrote, storing nothing and offering no
 * way out; a Response node had a no-reply limit, but only as part of asking a
 * question. So "stop until the customer says something" existed twice, with
 * different powers, and neither could be used on its own: a pause after a menu
 * or before handing over could not store what was said, or give up after an
 * hour, without also being a question.
 *
 * This node is the wait by itself. It may send a short message first, stores
 * the reply when told where, can collect a burst of messages as one reply, can
 * hold the reply to a format, and — when it has a limit — leaves through
 * `timeout`. Flows saved before it existed are rewritten into it by
 * {@see LegacyWaitUpgrade}, so none of them changes behaviour.
 *
 * The frontend mirrors these names and limits in `lib/waitResponseNodes.ts`:
 * the branch strings are edge condition_values and source handle ids at once.
 */
final class WaitResponseNodes
{
    /** The customer answered (and the answer passed the format check, if any). */
    public const BRANCH_REPLIED = 'replied';

    /** The customer stayed silent for longer than the node's limit. */
    public const BRANCH_TIMEOUT = 'timeout';

    public const BRANCHES = [self::BRANCH_REPLIED, self::BRANCH_TIMEOUT];

    /**
     * Longest silence a node may wait out: 31 days.
     *
     * Longer than the day the Response node used to allow, on purpose: this is
     * also how a flow waits for somebody to come back after a quote or a trial,
     * and those come back in weeks. Past a month the conversation it belongs to
     * has become a different conversation.
     */
    public const MAX_TIMEOUT_SECONDS = 31 * 86400;

    /** The units the builder offers, in seconds. Display only: the engine reads seconds. */
    public const TIMEOUT_UNITS = [
        'seconds' => 1,
        'minutes' => 60,
        'hours' => 3600,
        'days' => 86400,
    ];

    /**
     * Longest a burst window may be.
     *
     * The buffer waits for the customer to stop typing — "oi" / "tenho uma
     * dúvida" / "sobre o pedido 123" is one answer in three messages. Five
     * minutes is far past typing; beyond it the window only holds up a customer
     * who has already finished.
     */
    public const MAX_BUFFER_SECONDS = 300;

    /** The format checks a reply can be held to. `any` is the same as none. */
    public const VALIDATIONS = ['any', 'number', 'email', 'phone'];

    public const MAX_MESSAGE_LENGTH = 4096;

    /**
     * What a new node holds. Waits indefinitely, stores nothing, sends nothing:
     * the plain pause the Message node's switch used to be.
     *
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'message' => '',
            'variable_key' => '',
            'timeout_seconds' => 0,
            'timeout_unit' => 'minutes',
            'buffer_seconds' => 0,
            'validation' => 'any',
            'error_message' => '',
        ];
    }

    /**
     * The node's limit in seconds, or 0 when it waits indefinitely.
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

    /**
     * How long to wait for the customer to stop typing, or 0 when the first
     * message is the reply.
     *
     * @param  array<string, mixed>  $data
     */
    public static function bufferSeconds(array $data): int
    {
        $seconds = (int) ($data['buffer_seconds'] ?? 0);

        return max(0, min(self::MAX_BUFFER_SECONDS, $seconds));
    }

    /**
     * The format the reply must have, or null when anything goes.
     *
     * @param  array<string, mixed>  $data
     */
    public static function validation(array $data): ?string
    {
        $rule = $data['validation'] ?? null;

        if (!is_string($rule) || $rule === 'any' || !in_array($rule, self::VALIDATIONS, true)) {
            return null;
        }

        return $rule;
    }

    /**
     * Where the reply is stored, as the bare key templates read — or null when
     * it is not stored at all.
     *
     * @param  array<string, mixed>  $data
     */
    public static function variableKey(array $data): ?string
    {
        $key = $data['variable_key'] ?? null;

        if (!is_string($key)) {
            return null;
        }

        // The same legacy prefix the Response node strips: {{variable.x}} and
        // {{x}} both read state_data['x'].
        $key = preg_replace('/^(?:variable\.)+/', '', trim($key));

        return $key === '' || $key === null ? null : $key;
    }

    /**
     * The text sent before waiting. Empty when the node only waits.
     *
     * @param  array<string, mixed>  $data
     */
    public static function message(array $data): string
    {
        return is_string($data['message'] ?? null) ? $data['message'] : '';
    }

    /**
     * The largest unit that divides a limit exactly, so a node written as
     * "2 hours" comes back reading 2 hours rather than 7200 seconds.
     */
    public static function unitFor(int $seconds): string
    {
        foreach (['days', 'hours', 'minutes'] as $unit) {
            $size = self::TIMEOUT_UNITS[$unit];

            if ($seconds > 0 && $seconds % $size === 0) {
                return $unit;
            }
        }

        return 'seconds';
    }

    /**
     * State keys, shared with the migration that moves conversations parked on
     * a Response node into this one and with the panel's cold-load reading
     * (`lib/conversationActivity.ts`).
     */
    public static function parkedKey(int $nodeId): string
    {
        // Holds the id of the newest message in the conversation at the moment
        // the node started waiting: what arrived after it is the reply.
        return "_wait_response_{$nodeId}";
    }

    public static function timeoutKey(int $nodeId): string
    {
        return "_wait_timeout_{$nodeId}";
    }

    public static function bufferKey(int $nodeId): string
    {
        return "_wait_buffer_{$nodeId}";
    }
}

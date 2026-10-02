<?php

namespace App\Services\AiAgentHub;

/**
 * Follow-up: the agent writes again when the customer stops answering.
 *
 * Mirror of `lib/aiFollowUp.ts` on the frontend — **both sides must agree** on
 * the limits, because the builder is what keeps an author inside them.
 *
 * A follow-up is a ladder of steps, each "N minutes of silence after our last
 * message → the agent writes one short message to pick the conversation back
 * up". The text is the AI's, not the flow author's: re-engaging somebody is
 * only worth doing when it refers to what was actually being discussed, and a
 * fixed sentence cannot. The author may add an instruction per step ("offer
 * free shipping", "ask if the size was the problem").
 *
 * Four rules shape the executor side (FlowExecutor::runAiFollowUp):
 *
 *  1. **The customer writing cancels everything.** The chain is a token in the
 *     flow state; scheduleAIAgentTurn() drops it, and each job additionally
 *     checks for an incoming message newer than the one it was armed after —
 *     two independent fences, because the job can be in flight while the token
 *     is being cleared.
 *  2. **It never sends outside the messaging window.** On WhatsApp Official a
 *     free-form message after 24h is accepted and then fails, so a closed
 *     window ends the chain instead of producing a message nobody receives.
 *  3. **A failed follow-up stays silent.** No handoff, no retry: it is a nudge,
 *     not an answer the customer is owed, and a run that errored on a synthetic
 *     prompt is not a reason to put the conversation in somebody's queue.
 *  4. **The instruction never reaches the customer and is worded away from the
 *     hub's handoff vocabulary** — it travels in `message.content`, which the
 *     hub scans (the welcome preamble once handed off 53 of 53 runs).
 */
final class AiFollowUp
{
    /** Most steps one node may have. Past three, a nudge becomes spam. */
    public const MAX_STEPS = 3;

    /** Shortest wait before a step: anything less is interrupting somebody who is typing. */
    public const MIN_DELAY_MINUTES = 5;

    /**
     * Longest wait before a step. Just under a day, because WhatsApp Official
     * stops accepting free-form messages 24 hours after the customer's last
     * one — a step scheduled past that could never be delivered.
     */
    public const MAX_DELAY_MINUTES = 1380;

    /** Longest instruction an author may give one step. */
    public const MAX_INSTRUCTION_LENGTH = 500;

    /** Where the sent message is flagged, for the thread and the live board. */
    public const META_FLAG = 'ai_follow_up';

    /**
     * Platform kill switch. Off, no node follows up — the way back from a
     * channel complaining about unsolicited messages without editing flows.
     */
    public static function platformEnabled(): bool
    {
        return (bool) config('ai.follow_up.enabled', true);
    }

    /**
     * The node's setting, normalised. Absent means off — the key is absent on
     * every node built before this existed, and a flow must never start
     * writing to silent customers because the engine was upgraded.
     *
     * @param  array<string, mixed>  $nodeData
     * @return array{enabled: bool, steps: list<array{delay_minutes: int, instruction: string}>}
     */
    public static function config(array $nodeData): array
    {
        $raw = $nodeData['follow_up'] ?? [];
        $raw = is_array($raw) ? $raw : [];

        $steps = [];

        foreach (array_slice(array_values((array) ($raw['steps'] ?? [])), 0, self::MAX_STEPS) as $step) {
            if (! is_array($step) || ! is_numeric($step['delay_minutes'] ?? null)) {
                continue;
            }

            $steps[] = [
                'delay_minutes' => max(self::MIN_DELAY_MINUTES, min(self::MAX_DELAY_MINUTES, (int) $step['delay_minutes'])),
                'instruction' => mb_substr(trim((string) ($step['instruction'] ?? '')), 0, self::MAX_INSTRUCTION_LENGTH),
            ];
        }

        return [
            'enabled' => self::platformEnabled() && ($raw['enabled'] ?? false) === true && $steps !== [],
            'steps' => $steps,
        ];
    }

    /**
     * What the agent is asked to do, in place of a customer message.
     *
     * English, written for the model, and free of the words the hub's handoff
     * detector reacts to. The author's own instruction is appended as given —
     * it is theirs, and it is the point of the field.
     */
    public static function prompt(int $step, int $total, int $silentMinutes, string $instruction): string
    {
        $lines = [
            "[Follow-up {$step} of {$total} — the customer has not replied for ".self::duration($silentMinutes).' since the last message in this conversation.',
            'Write ONE short, friendly message that picks the conversation back up from where it stopped, in the same language the customer has been using.',
            'Refer to what was being discussed; do not greet again, do not repeat an earlier message word for word, and do not pressure the customer.',
        ];

        if ($step === $total) {
            $lines[] = 'This is the last follow-up: let them know they can write back whenever they want.';
        }

        if ($instruction !== '') {
            $lines[] = 'Instruction for this follow-up: '.$instruction;
        }

        return implode(' ', $lines).']';
    }

    private static function duration(int $minutes): string
    {
        if ($minutes < 60) {
            return $minutes === 1 ? '1 minute' : "{$minutes} minutes";
        }

        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;

        return $rest === 0
            ? ($hours === 1 ? '1 hour' : "{$hours} hours")
            : "{$hours}h{$rest}min";
    }
}

<?php

namespace App\Services\AiAgentHub;

use App\Exceptions\PublicApiException;
use Illuminate\Support\Facades\Log;

/**
 * The capability the hub needs to write back into a conversation it is serving.
 *
 * The partner's original ask was for the endpoint to take `conversations.id`.
 * That column is a platform-wide auto-increment, so one workspace API key plus
 * a guessable id means the caller can write into *any* thread the workspace
 * owns — arbitrary text, in a bubble that looks like the official bot, to
 * somebody else's customer. And the text is authored by a model that just read
 * customer-supplied content, so "the caller" includes whoever talked the agent
 * into it.
 *
 * So the id never travels. What travels is a signed handle minted during the
 * run and naming the run's own context: the conversation, the flow node, the
 * agent, and an expiry. Three properties come out of that:
 *
 *  - it cannot be enumerated — there is no "the ref for conversation 12346";
 *  - it scopes itself — a ref only ever addresses the conversation it was
 *    minted for, and only while that agent is still on that node, so an agent
 *    taking the thread over revokes it without anything being revoked;
 *  - it needs no storage and no migration, which is why the whole mechanism is
 *    this one file.
 *
 * The worst case left is a customer getting the bot to say something odd *to
 * themselves*. That is a tolerable outcome; the other one was not.
 *
 * Verification here is only the first half. Whether the thread is still with
 * the AI is live state, and AiProactiveMessageService checks it every time —
 * a valid ref is permission to ask, never permission to send.
 */
final class AiCallbackRef
{
    /**
     * Version tag, so the format can change without every ref in flight
     * becoming "invalid" for a reason nobody can diagnose.
     */
    public const PREFIX = 'cr1';

    /** Domain separation: this key material signs nothing else. */
    private const PURPOSE = 'pingly:ai-callback-ref:v1';

    /**
     * The fallback is false on purpose: a missing config key must not be what
     * starts minting references and adding a field the hub may reject.
     */
    public static function enabled(): bool
    {
        return (bool) config('ai.proactive.enabled', false);
    }

    /**
     * Mint a ref for one AI turn.
     *
     * Deterministic apart from the expiry, so two runs on the same node mint
     * two different-but-equivalent refs; both work until they lapse, and the
     * hub can simply use the newest it has seen.
     */
    public static function mint(int $conversationId, int $flowNodeId, int $agentId): string
    {
        $claims = [
            'c' => $conversationId,
            'n' => $flowNodeId,
            'a' => $agentId,
            'e' => now()->addHours(self::ttlHours())->getTimestamp(),
        ];

        $body = self::encode((string) json_encode($claims));

        return self::PREFIX.'.'.$body.'.'.self::encode(self::signature($body));
    }

    /**
     * The claims inside a ref, or a refusal.
     *
     * Throws rather than returning null so that "invalid" and "expired" reach
     * the caller as the two different things they are: one means the hub is
     * holding something it was never given, the other means it waited too long,
     * and only the second is worth retrying differently.
     *
     * @return array{conversation_id: int, flow_node_id: int, ai_hub_agent_id: int}
     */
    public static function open(string $ref): array
    {
        $parts = explode('.', trim($ref));

        if (count($parts) !== 3 || $parts[0] !== self::PREFIX) {
            throw self::refuse('malformed');
        }

        [, $body, $mac] = $parts;

        // Compared against the encoded body, not the decoded claims: signing
        // the wire form means no canonicalisation question can ever arise
        // between what was signed and what is checked.
        if (! hash_equals(self::signature($body), (string) self::decode($mac))) {
            throw self::refuse('bad_signature');
        }

        $claims = json_decode((string) self::decode($body), true);

        if (! is_array($claims)) {
            throw self::refuse('unreadable_claims');
        }

        foreach (['c', 'n', 'a', 'e'] as $field) {
            if (! isset($claims[$field]) || ! is_int($claims[$field])) {
                throw self::refuse('incomplete_claims');
            }
        }

        if ($claims['e'] < now()->getTimestamp()) {
            throw new PublicApiException(
                'Esta referência de conversa expirou. O hub deve aguardar o próximo run para obter uma nova.',
                'callback_ref_expired',
                403,
            );
        }

        return [
            'conversation_id' => $claims['c'],
            'flow_node_id' => $claims['n'],
            'ai_hub_agent_id' => $claims['a'],
        ];
    }

    private static function ttlHours(): int
    {
        // Bounded either way: a ref that outlives the WhatsApp session window
        // buys nothing (a free-form message is refused there anyway), and one
        // that lapses in minutes loses the very case this exists for — a
        // customer who opens the portal link, gets distracted, and comes back.
        $hours = (int) config('ai.proactive.ref_ttl_hours', 24);

        return max(1, min($hours, 72));
    }

    private static function signature(string $body): string
    {
        return hash_hmac('sha256', self::PURPOSE.'.'.$body, (string) config('app.key'), true);
    }

    private static function encode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function decode(string $encoded): string|false
    {
        return base64_decode(strtr($encoded, '-_', '+/'), true);
    }

    /**
     * One sentence for every way a ref can be wrong, and the real reason in the
     * log: which of them it was tells us whether a partner shipped a bug or
     * somebody is probing, and neither belongs in a response body.
     */
    private static function refuse(string $reason): PublicApiException
    {
        Log::warning('AiCallbackRef: refused a conversation reference', ['reason' => $reason]);

        return new PublicApiException(
            'Referência de conversa inválida.',
            'callback_ref_invalid',
            403,
        );
    }
}

<?php

namespace App\Services\Webhook;

use App\Models\Connection;
use App\Services\Flow\InteractiveNodes;

/**
 * A tap on a button that another system put in front of the customer.
 *
 * Meta delivers a WhatsApp webhook to every app subscribed to the WABA, so when
 * a second system sends buttons on a number Pingly also serves — ProxyBR's hub
 * sends its renewal reminder with "Renovar agora" / "Não vou renovar" — the tap
 * reaches both. The other system answers it; Pingly used to answer it too,
 * with the connection's greeting and menu, and put the conversation in the
 * agents' queue for no reason.
 *
 * The connection names the button ids it does not own by prefix
 * (`connections.ignored_button_prefixes`, e.g. `["rnw:"]`). A matching tap is
 * still stored, so agents see it in the history, but it runs no flow, calls no
 * AI, opens no thread in the queue and notifies nobody. See
 * WhatsappOfficialHandler::handleMessage for the thread it lands in.
 *
 * The message carries `meta.external_automation` — `{prefix, id, kind}` — and
 * everything that reads a customer's messages as *input* (the AI turn, a Wait
 * for reply buffer, the opening-message check) leaves it out through
 * Message::scopeExceptExternalAutomation. It was said to the other system, not
 * to this conversation's bot.
 */
final class ExternalAutomationReply
{
    /** `messages.meta` key marking a reply that belongs to another system. */
    public const META_KEY = 'external_automation';

    public const MAX_PREFIXES = 10;

    /**
     * Short enough to fit any namespace a system would pick, long enough that
     * nobody switches off the flow for every button by typing one letter.
     */
    public const MIN_PREFIX_LENGTH = 3;

    public const MAX_PREFIX_LENGTH = 64;

    /** A template's quick-reply button. */
    public const KIND_BUTTON = 'button';

    /** An interactive message's reply button or list row. */
    public const KIND_INTERACTIVE = 'interactive';

    /** A WhatsApp Flows screen sent back (nfm_reply), matched on its flow_token. */
    public const KIND_FLOW = 'flow';

    /**
     * What to record on the message when this tap belongs to another system,
     * or null when it is the connection's to handle.
     *
     * @param  array<string, mixed>  $message  `entry.changes[0].value.messages[0]` of a Cloud API webhook
     * @return array{prefix: string, id: string, kind: string}|null
     */
    public static function match(Connection $connection, array $message): ?array
    {
        $prefixes = $connection->ignoredButtonPrefixes();

        if ($prefixes === []) {
            return null;
        }

        $reply = self::replyId($message);

        if ($reply === null) {
            return null;
        }

        foreach ($prefixes as $prefix) {
            if (str_starts_with($reply['id'], $prefix)) {
                return ['prefix' => $prefix] + $reply;
            }
        }

        return null;
    }

    /**
     * The id the sender gave the tapped button, wherever the Cloud API put it.
     *
     * @param  array<string, mixed>  $message
     * @return array{id: string, kind: string}|null
     */
    public static function replyId(array $message): ?array
    {
        $type = $message['type'] ?? null;

        if ($type === 'button') {
            $payload = $message['button']['payload'] ?? null;

            return is_string($payload) && $payload !== ''
                ? ['id' => $payload, 'kind' => self::KIND_BUTTON]
                : null;
        }

        if ($type !== 'interactive' || ! is_array($message['interactive'] ?? null)) {
            return null;
        }

        $interactive = $message['interactive'];

        if (($interactive['type'] ?? null) === 'nfm_reply') {
            $token = self::flowToken($interactive['nfm_reply']['response_json'] ?? null);

            return $token !== null ? ['id' => $token, 'kind' => self::KIND_FLOW] : null;
        }

        $id = InteractiveNodes::replyFromWebhook($interactive)['id'] ?? null;

        return $id !== null && $id !== '' ? ['id' => $id, 'kind' => self::KIND_INTERACTIVE] : null;
    }

    /**
     * Whether a stored message is one of these taps.
     *
     * @param  array<string, mixed>|null  $meta
     */
    public static function is(?array $meta): bool
    {
        return is_array($meta[self::META_KEY] ?? null);
    }

    /**
     * Cleans what an operator typed into the list a connection keeps: trimmed,
     * blanks and repeats dropped, in the order given.
     *
     * @param  mixed  $prefixes
     * @return list<string>
     */
    public static function normalizePrefixes($prefixes): array
    {
        if (! is_array($prefixes)) {
            return [];
        }

        $clean = [];

        foreach ($prefixes as $prefix) {
            $prefix = is_string($prefix) ? trim($prefix) : '';

            if ($prefix !== '' && ! in_array($prefix, $clean, true)) {
                $clean[] = $prefix;
            }
        }

        return $clean;
    }

    /**
     * `flow_token` out of the JSON string Meta hands back when a Flows screen
     * is submitted. Anything unreadable is not a match: a reply we cannot read
     * is safer treated as the customer's than silently dropped from the flow.
     */
    private static function flowToken(mixed $responseJson): ?string
    {
        if (! is_string($responseJson) || $responseJson === '') {
            return null;
        }

        $decoded = json_decode($responseJson, true);
        $token = is_array($decoded) ? ($decoded['flow_token'] ?? null) : null;

        return is_string($token) && $token !== '' ? $token : null;
    }
}

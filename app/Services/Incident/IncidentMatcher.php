<?php

namespace App\Services\Incident;

use App\Enums\Incident\MatchMode;
use App\Enums\Message\MessageType;
use App\Models\Incident;
use App\Models\Message;
use App\Services\AiAgentHub\AiFirstMessage;

/**
 * Reads a customer's message against an incident: does it get the notice, does
 * it not, or does it not count yet.
 *
 * "Not counted" is what makes the keyword mode usable. The first thing most
 * people send is "oi", and a rule that judged "oi" against the keywords would
 * decide, on a greeting, that the person whose proxy is down is not affected.
 * So in keyword mode three kinds of message pass through without deciding
 * anything, and the next one is read instead:
 *
 *  - a greeting and nothing else (AiFirstMessage::isGreetingOnly — the list
 *    the AI node already uses, in the four languages the product meets);
 *  - a tap on a button or a menu row: those are the button's words, not the
 *    customer's;
 *  - media with no text to read — a photo without a caption, a voice note.
 *
 * In "all" mode everything counts: the point of that mode is that the first
 * thing anyone sends gets the notice, whatever it is.
 *
 * Matching is on the shape AiFirstMessage::normalize() produces — lowercase,
 * no accents, punctuation as spaces, repeated letters squeezed — so "Não
 * CONECTA!!" and "nao conecta" are the same message. A keyword matches at the
 * start of a word ("conect" finds "conectar"); a phrase must appear in order
 * ("fora do ar"). An exclusion keyword wins over a match: "comprar" keeps
 * "quero comprar mais proxies" from being told the proxies are down.
 */
final class IncidentMatcher
{
    /** Shortest keyword worth having, once normalized: two letters match half the language. */
    public const MIN_KEYWORD_LENGTH = 3;

    public const MAX_KEYWORD_LENGTH = 60;

    /**
     * @param  array<int, string>  $keywords
     * @param  array<int, string>  $exclude
     */
    public static function judgeMessage(Incident $incident, Message $message): IncidentVerdict
    {
        return self::judge(
            $incident->match_mode,
            $incident->keywordList(),
            $incident->excludeKeywordList(),
            $message->message_type,
            $message->body,
        );
    }

    /**
     * @param  array<int, string>  $keywords
     * @param  array<int, string>  $exclude
     */
    public static function judge(MatchMode $mode, array $keywords, array $exclude, MessageType $type, ?string $text): IncidentVerdict
    {
        $readable = self::readableText($type, $text);
        $normalized = $readable !== null ? AiFirstMessage::normalize($readable) : '';

        if ($normalized !== '' && ($excluded = self::find($normalized, $exclude)) !== null) {
            return IncidentVerdict::noMatch(excluded: $excluded);
        }

        if ($mode === MatchMode::All) {
            return IncidentVerdict::match(null);
        }

        $reason = self::notCountedReason($type, $readable);

        if ($reason !== null) {
            return IncidentVerdict::notCounted($reason);
        }

        $keyword = self::find($normalized, $keywords);

        return $keyword !== null ? IncidentVerdict::match($keyword) : IncidentVerdict::noMatch();
    }

    /**
     * The first keyword found in an already-normalized text, as the person
     * wrote it — that is what the incident page and the bubble show.
     *
     * @param  array<int, string>  $keywords
     */
    public static function find(string $normalized, array $keywords): ?string
    {
        $haystack = ' '.$normalized;

        foreach ($keywords as $keyword) {
            $needle = AiFirstMessage::normalize((string) $keyword);

            if ($needle === '') {
                continue;
            }

            if (str_contains($haystack, ' '.$needle)) {
                return $keyword;
            }
        }

        return null;
    }

    /**
     * Trim, drop what normalizes to nothing or too little, and drop repeats —
     * "Caiu" and "caiu" are one keyword. Kept as typed otherwise, because that
     * is how the person will look for it in the list.
     *
     * @param  array<int, mixed>  $raw
     * @return array<int, string>
     */
    public static function cleanKeywords(array $raw): array
    {
        $kept = [];
        $seen = [];

        foreach ($raw as $value) {
            if (! is_string($value)) {
                continue;
            }

            $keyword = trim(preg_replace('/\s+/', ' ', $value) ?? '');
            $normalized = AiFirstMessage::normalize($keyword);

            if ($normalized === '' || isset($seen[$normalized])) {
                continue;
            }

            $seen[$normalized] = true;
            $kept[] = $keyword;
        }

        return $kept;
    }

    /** Whether a keyword is long enough to mean something once normalized. */
    public static function isUsableKeyword(string $keyword): bool
    {
        return mb_strlen(AiFirstMessage::normalize($keyword)) >= self::MIN_KEYWORD_LENGTH;
    }

    /**
     * The words in a message that can be matched: its text, or the caption a
     * file came with. Null when there are none.
     */
    private static function readableText(MessageType $type, ?string $text): ?string
    {
        $text = trim((string) $text);

        if ($text === '') {
            return null;
        }

        return match ($type) {
            MessageType::Text, MessageType::Image, MessageType::Video, MessageType::Document => $text,

            // A button's label, a shared post's caption, a contact card: words,
            // but not the customer's own.
            default => null,
        };
    }

    /** Why a message does not decide anything in keyword mode, or null when it does. */
    private static function notCountedReason(MessageType $type, ?string $readable): ?string
    {
        if ($type === MessageType::Interactive) {
            return IncidentVerdict::REASON_BUTTON;
        }

        if ($readable === null) {
            return IncidentVerdict::REASON_NO_TEXT;
        }

        if (AiFirstMessage::isGreetingOnly($readable)) {
            return IncidentVerdict::REASON_GREETING;
        }

        return null;
    }
}

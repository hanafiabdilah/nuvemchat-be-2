<?php

namespace App\Services\AiAgentHub;

use App\Models\Tenant;

/**
 * The workspace's own words, and the one place that turns them into the shape
 * the hub takes.
 *
 * One list, both directions of a voice conversation:
 *
 * **Listening** (`inputAudio`). A transcription model that has never heard of
 * the business spells its vocabulary phonetically. "SOCKS5" arrives as "socks
 * five", "ProxyBR" as "proxy be erre", and the agent then answers a question
 * nobody asked. Handing the provider the term and its aliases up front is the
 * documented fix — `keyterms` for ElevenLabs, a sentence of `prompt` for OpenAI.
 *
 * **Speaking** (`responseAudio.pronunciationReplacements`). The opposite
 * problem: the agent writes "IPv6" correctly and the voice reads it wrong. The
 * reply's text and its audio come out of the same hub run, so nothing on this
 * side ever holds the sentence in between — which is why this travels as a
 * list the hub applies to the text *before* handing it to ElevenLabs, on top of
 * the fixed normalisation it already does (IPv6, SOCKS5, HTTP, ProxyBR…).
 *
 * Stored on the tenant (`tenants.audio_dictionary`) as:
 *
 *   [{"term": "SOCKS5", "aliases": ["socks 5", "socks five"], "speak_as": "sócs cinco"}, …]
 *
 * `aliases` are the other spellings of the term — the ones the transcription
 * is likely to *produce*, and the variants the reply text may contain. They
 * serve both directions. `speak_as` serves only the voice, and is optional:
 * most words are only a listening problem, and an entry without one never
 * reaches `responseAudio`.
 *
 * ⚠️ `speak_as` is never a keyterm. It is a phonetic spelling ("ipê vê seis"),
 * and biasing the transcription towards it is exactly the mistake the listening
 * half exists to undo.
 */
class AiVocabulary
{
    /**
     * Caps, chosen so the two of them together can never reach the hub's own
     * ceiling of 1000 keyterms: 100 × (1 term + 8 aliases) = 900.
     */
    public const MAX_TERMS = 100;

    public const MAX_ALIASES = 8;

    /** The hub sanitises too, but a list arriving clean is one less thing to guess about. */
    private const MIN_LENGTH = 2;

    private const MAX_LENGTH = 50;

    /**
     * Longer than a term on purpose: spelling a word out phonetically takes
     * more letters than writing it ("CNPJ" → "cê ene pê jota").
     */
    public const MAX_SPEAK_AS_LENGTH = 100;

    private const HUB_LIMIT = 1000;

    /** How many terms fit in an OpenAI transcription prompt without hurting it. */
    private const PROMPT_TERMS = 30;

    /**
     * The flat term list for `inputAudio.keyterms`.
     *
     * Platform terms first and the tenant's after, in the order they were
     * entered. The platform list is a handful of words the hub already knows
     * about, so it never crowds anybody out; keeping the tenant's own order
     * means the list in the form is the list that travels.
     *
     * @return array<int, string>
     */
    public static function keyterms(?Tenant $tenant): array
    {
        $terms = (array) config('ai.audio.keyterms', []);

        foreach (self::dictionary($tenant) as $entry) {
            $terms[] = $entry['term'];

            foreach ($entry['aliases'] as $alias) {
                $terms[] = $alias;
            }
        }

        return self::clean($terms, self::HUB_LIMIT);
    }

    /**
     * The same vocabulary in OpenAI's shape: a sentence of context rather than
     * a list.
     *
     * Not an afterthought — OpenAI is the default provider, so without this a
     * workspace that never switched to ElevenLabs would fill in a dictionary
     * and hear no difference at all, which is the worst way for a setting to
     * behave.
     *
     * Far fewer terms travel here than in `keyterms`, and that is the provider
     * talking: the transcription prompt is capped at a couple of hundred tokens
     * and a long one measurably *degrades* the transcript, whereas ElevenLabs
     * takes a list of a thousand. Aliases are left out for the same reason —
     * spelling out the near-misses is what a list is for.
     */
    public static function prompt(?Tenant $tenant): ?string
    {
        $prompt = trim((string) config('ai.audio.prompt'));
        $terms = array_column(self::dictionary($tenant), 'term');
        $terms = array_slice($terms, 0, self::PROMPT_TERMS);

        if ($terms !== []) {
            // Named as vocabulary rather than dropped in bare: the prompt is
            // read as a continuation of the audio, so a naked list of acronyms
            // can end up transcribed instead of used.
            $prompt = trim($prompt . ' Termos usados nesta conversa: ' . implode(', ', $terms) . '.');
        }

        return $prompt !== '' ? $prompt : null;
    }

    /**
     * The speaking half, for `responseAudio.pronunciationReplacements`.
     *
     * Only entries somebody gave a pronunciation to — the rest of the list is a
     * listening problem, and a replacement that says "IPv6" → "IPv6" is noise
     * the hub would have to skip. `aliases` travel so "IPV6" and "IP v6" in the
     * reply text are read the same way as the term, and are left out when there
     * are none rather than sent empty.
     *
     * @return array<int, array{term: string, speakAs: string, aliases?: array<int, string>}>
     */
    public static function pronunciations(?Tenant $tenant): array
    {
        $replacements = [];

        foreach (self::dictionary($tenant) as $entry) {
            if ($entry['speak_as'] === null) {
                continue;
            }

            $replacements[] = array_filter([
                'term' => $entry['term'],
                'speakAs' => $entry['speak_as'],
                'aliases' => $entry['aliases'],
            ], fn ($value) => $value !== []);
        }

        return $replacements;
    }

    /**
     * The tenant's dictionary, in the stored shape, with anything malformed
     * dropped rather than repaired — this is read on the path of a live run,
     * and a half-understood entry is not worth failing a customer's reply over.
     *
     * @return array<int, array{term: string, aliases: array<int, string>, speak_as: ?string}>
     */
    public static function dictionary(?Tenant $tenant): array
    {
        return self::sanitize($tenant?->audio_dictionary);
    }

    /**
     * Normalise submitted or stored input into the shape above.
     *
     * Shared by the write path (so what is saved is already clean) and the read
     * path (so rows written before a rule existed still behave).
     *
     * @return array<int, array{term: string, aliases: array<int, string>, speak_as: ?string}>
     */
    public static function sanitize(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $entries = [];
        $seen = [];

        foreach ($raw as $item) {
            $term = self::term(is_array($item) ? ($item['term'] ?? null) : $item);

            if ($term === null) {
                continue;
            }

            // Two rows for the same word would send it twice and give the
            // person editing the list two places to fix it.
            $key = mb_strtolower($term);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;

            $aliases = is_array($item) && is_array($item['aliases'] ?? null) ? $item['aliases'] : [];

            $entries[] = [
                'term' => $term,
                // The term itself is not an alias of itself, but a
                // case-variant of it is: "IPV6" is what the model produces for
                // "IPv6", so exact-matching is right here.
                'aliases' => array_values(array_diff(
                    self::clean($aliases, self::MAX_ALIASES),
                    [$term],
                )),
                'speak_as' => self::speakAs(is_array($item) ? ($item['speak_as'] ?? null) : null, $term),
            ];

            if (count($entries) >= self::MAX_TERMS) {
                break;
            }
        }

        return $entries;
    }

    /**
     * Trim, drop the unusable, and dedupe exactly.
     *
     * Exactly, not case-insensitively: for an acronym the casing *is* the
     * variant worth listing, which is why "IPV6" earns its place next to
     * "IPv6".
     *
     * @param  array<int, mixed>  $values
     * @return array<int, string>
     */
    private static function clean(array $values, int $limit): array
    {
        $out = [];

        foreach ($values as $value) {
            $term = self::term($value);

            if ($term === null || in_array($term, $out, true)) {
                continue;
            }

            $out[] = $term;

            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /**
     * One usable pronunciation, or null.
     *
     * Blank means "no opinion", and so does repeating the term exactly: the
     * voice already reads the term as written, so that replacement changes
     * nothing. A case-variant is kept — "ipv6" can be a deliberate instruction.
     * Too long is dropped rather than cut, same as a term: half a phonetic
     * spelling is a different word.
     */
    private static function speakAs(mixed $value, string $term): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $speakAs = trim(preg_replace('/\s+/u', ' ', $value) ?? '');

        if ($speakAs === '' || $speakAs === $term || mb_strlen($speakAs) > self::MAX_SPEAK_AS_LENGTH) {
            return null;
        }

        return $speakAs;
    }

    /**
     * One usable term, or null.
     *
     * Long entries are refused rather than truncated: a keyterm is a word the
     * provider should listen for, and a sentence chopped at 50 characters is
     * not one.
     */
    private static function term(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $term = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
        $length = mb_strlen($term);

        return $length >= self::MIN_LENGTH && $length <= self::MAX_LENGTH ? $term : null;
    }
}

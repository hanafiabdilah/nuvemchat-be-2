<?php

namespace App\Services\AiAgentHub;

use App\Enums\Message\MessageType;
use App\Models\Message;
use Illuminate\Support\Str;

/**
 * Decides whether the message that opened the conversation deserves an answer
 * of its own, or whether the welcoming message already said everything there
 * was to say.
 *
 * The AIAgent node greets before it thinks: the canned welcome goes out on the
 * first interaction and the AI is not called at all. That is right for the
 * "Oi" most conversations open with — a model handed a bare greeting and no
 * context answers it awkwardly — and wrong for everything else. Somebody who
 * opens with "meu pedido não chegou" has already said the thing, and asking
 * them to say it again is the platform asking a question it was just given the
 * answer to. The wait is invisible to them: they see a greeting, then silence,
 * and the ones who do not think to repeat themselves simply leave.
 *
 * So the test here is deliberately one-sided. The default is to answer, and a
 * greeting is the exception looked for. "Is this only a greeting?" is a closed
 * question over a short list of words that barely changes; "is this a real
 * problem?" is open-ended, and being wrong about it costs the customer the
 * round trip this whole thing exists to remove.
 *
 * Deliberately not a model call. It would be a second run billed against the
 * tenant's quota, on every new conversation, standing in front of the welcome
 * — paying latency and money to classify "oi".
 */
class AiFirstMessage
{
    /**
     * Above this many words, stop looking. Nobody greets in a paragraph, and
     * the word lists below are short enough that a long message clearing them
     * all would be a coincidence rather than a greeting.
     */
    private const MAX_GREETING_WORDS = 12;

    /**
     * Ways of saying hello, in the languages this product actually meets:
     * Portuguese first, then the English and Spanish that turn up on the same
     * inboxes, then Indonesian.
     *
     * Multi-word entries are written with single spaces because that is what
     * normalize() leaves behind. Matched with word boundaries, so "oi" does
     * not eat the "oi" in "oito" and "fala" leaves "me fala o preço" alone.
     */
    private const OPENERS = [
        // Portuguese
        'oi', 'oie', 'ola', 'opa', 'alo', 'alow', 'alou', 'salve',
        'e ai', 'e ae', 'eai', 'eae', 'fala', 'fala ai',
        'bom dia', 'boa tarde', 'boa noite', 'bomdia', 'boas',
        'tudo bem', 'tudo bom', 'tudo certo', 'td bem', 'td bom', 'tdb',
        'blz', 'beleza', 'como vai', 'como esta', 'como voce esta',
        'como vc esta', 'como estao', 'prezado', 'prezada', 'prezados',
        'com licenca',

        // English
        'hi', 'hi there', 'hello', 'hey', 'heya', 'hiya', 'howdy', 'yo',
        'sup', 'whats up', 'what s up', 'greetings',
        'good morning', 'good afternoon', 'good evening', 'good day',
        'how are you', 'how are you doing', 'hows it going', 'how s it going',

        // Spanish
        'hola', 'buenos dias', 'buenas tardes', 'buenas noches', 'buenas',
        'que tal', 'como estas',

        // Indonesian
        'halo', 'hallo', 'hai', 'permisi', 'apa kabar', 'salam',
        'assalamualaikum', 'selamat pagi', 'selamat siang', 'selamat sore',
        'selamat malam',
    ];

    /**
     * The words that travel with a greeting without adding anything to it:
     * politeness, forms of address, laughter, the halves of a greeting written
     * on their own ("boa!").
     *
     * Every one of these is safe to strip from a real question — take "dia"
     * out of "qual o dia da entrega" and what is left is still plainly not a
     * greeting. They only decide anything when they are the entire message.
     */
    private const FILLER = [
        'por favor', 'pfv', 'pf', 'obrigado', 'obrigada', 'obg', 'vlw',
        'valeu', 'desculpa', 'desculpe', 'please', 'thanks', 'thank you',
        'gracias', 'terima kasih', 'makasih',
        'ok', 'okay', 'oks', 'certo', 'bom', 'boa', 'dia', 'tarde', 'noite',
        'sr', 'sra', 'senhor', 'senhora', 'moco', 'moca', 'amigo', 'amiga',
        'pessoal', 'galera', 'gente', 'time', 'equipe', 'a todos', 'todos',
        'voce', 'voces', 'vc', 'vcs', 'ai', 'ae', 'entao', 'ne', 'eh', 'ah',
        'oh', 'hmm', 'hm', 'haha', 'hehe', 'rs', 'rsrs', 'kk', 'k', 'e',
        'com', 'there',
    ];

    private static ?string $pattern = null;

    /**
     * True when at least one of these messages gives the agent something to
     * work with.
     *
     * @param  iterable<Message>  $messages
     */
    public static function needsAnswer(iterable $messages): bool
    {
        foreach ($messages as $message) {
            if (self::carriesSomethingToAnswer($message)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Read one message as either an opening pleasantry or the start of the
     * actual conversation.
     */
    private static function carriesSomethingToAnswer(Message $message): bool
    {
        return match ($message->message_type) {
            // Decoration, and never attached to a run either (see
            // AiAttachments): a cartoon thumbs-up is the sticker for "oi".
            MessageType::Sticker => false,

            // The channel could not read it, so neither can the agent. A run
            // on "[unsupported]" buys a reply about our own limitations.
            MessageType::Unsupported, MessageType::Info => false,

            // Media is intent. Nobody sends a screenshot to say hello, and the
            // caption does not have to carry the question because the file
            // does — a bare photo of an error screen is the whole message.
            MessageType::Image, MessageType::Video, MessageType::Audio,
            MessageType::Document, MessageType::Location, MessageType::Contact,
            MessageType::InstagramShare => true,

            default => ! self::isGreetingOnly($message->body),
        };
    }

    /**
     * True when nothing survives once the hellos and the politeness around
     * them are taken out.
     */
    public static function isGreetingOnly(?string $text): bool
    {
        $normalized = self::normalize((string) $text);

        // Emoji, punctuation, or nothing at all — Str::ascii drops what it
        // cannot transliterate. There is no question inside a waving hand, and
        // the welcome answers it better than a guess would.
        if ($normalized === '') {
            return true;
        }

        if (str_word_count($normalized, 0, '0123456789') > self::MAX_GREETING_WORDS) {
            return false;
        }

        $rest = preg_replace(self::pattern(), ' ', $normalized);
        $rest = trim((string) preg_replace('/\s+/', ' ', (string) $rest));

        return $rest === '';
    }

    /**
     * One alternation over both lists, longest phrase first.
     *
     * Order is the whole trick: preg tries alternatives left to right at each
     * position, so "bom dia" has to come before "bom" or the day is left
     * behind as a leftover word and every "bom dia" reads as a question.
     */
    private static function pattern(): string
    {
        if (self::$pattern !== null) {
            return self::$pattern;
        }

        $phrases = array_unique(array_merge(self::OPENERS, self::FILLER));

        usort($phrases, fn (string $a, string $b) => strlen($b) <=> strlen($a));

        return self::$pattern = '/\b(?:' . implode('|', array_map(
            fn (string $phrase) => preg_quote($phrase, '/'),
            $phrases
        )) . ')\b/';
    }

    /**
     * Lowercase, unaccented, single-spaced, with punctuation turned into
     * boundaries — the shape the word lists are written against.
     */
    private static function normalize(string $text): string
    {
        $text = Str::lower(Str::ascii(trim($text)));

        // A repeated letter is enthusiasm, not a different word: "oiii" and
        // "olaaa" are the ones already in the list.
        $text = (string) preg_replace('/(.)\1{2,}/', '$1', $text);

        // Punctuation is what separates a greeting from what follows it —
        // "bom dia, preciso de ajuda" is one of each — so it becomes a space
        // rather than a character every entry would have to spell out.
        $text = (string) preg_replace('/[^a-z0-9]+/', ' ', $text);

        return trim((string) preg_replace('/\s+/', ' ', $text));
    }
}

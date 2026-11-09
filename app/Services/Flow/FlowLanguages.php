<?php

namespace App\Services\Flow;

use App\Models\Flow;
use App\Models\Tenant;

/**
 * Language codes as a flow that answers in the customer's language reads them.
 *
 * One spelling everywhere — BCP 47 with a lowercase language and an uppercase
 * region ("en", "es", "pt-BR") — because the same value is written by three
 * hands (the language detector, an agent correcting it, the author choosing
 * the flow's language) and compared by one. Comparison is on the language
 * alone: a flow written in "pt-BR" does not get translated for a customer the
 * detector heard as "pt".
 */
final class FlowLanguages
{
    /**
     * English names for the prompt. The model understands bare codes, but
     * "Indonesian" leaves less to guess than "id". Not a whitelist: a code
     * missing here is still translated, by its code.
     */
    private const NAMES = [
        'af' => 'Afrikaans', 'ar' => 'Arabic', 'bg' => 'Bulgarian', 'bn' => 'Bengali',
        'ca' => 'Catalan', 'cs' => 'Czech', 'da' => 'Danish', 'de' => 'German',
        'el' => 'Greek', 'en' => 'English', 'es' => 'Spanish', 'et' => 'Estonian',
        'fa' => 'Persian', 'fi' => 'Finnish', 'fil' => 'Filipino', 'fr' => 'French',
        'gu' => 'Gujarati', 'he' => 'Hebrew', 'hi' => 'Hindi', 'hr' => 'Croatian',
        'hu' => 'Hungarian', 'id' => 'Indonesian', 'it' => 'Italian', 'ja' => 'Japanese',
        'jv' => 'Javanese', 'ko' => 'Korean', 'lt' => 'Lithuanian', 'lv' => 'Latvian',
        'ms' => 'Malay', 'mr' => 'Marathi', 'nl' => 'Dutch', 'no' => 'Norwegian',
        'pa' => 'Punjabi', 'pl' => 'Polish', 'pt' => 'Portuguese', 'ro' => 'Romanian',
        'ru' => 'Russian', 'sk' => 'Slovak', 'sl' => 'Slovenian', 'sr' => 'Serbian',
        'su' => 'Sundanese', 'sv' => 'Swedish', 'sw' => 'Swahili', 'ta' => 'Tamil',
        'te' => 'Telugu', 'th' => 'Thai', 'tl' => 'Tagalog', 'tr' => 'Turkish',
        'uk' => 'Ukrainian', 'ur' => 'Urdu', 'vi' => 'Vietnamese', 'zh' => 'Chinese',
    ];

    /**
     * "EN", "pt_br", " pt-BR " → "en", "pt-BR". Anything that is not shaped
     * like a language tag — including "und", the detector's "cannot tell" —
     * is null, so nothing else can ever be stored as a customer's language.
     */
    public static function normalize(?string $code): ?string
    {
        $code = str_replace('_', '-', trim((string) $code));

        if (! preg_match('/^([a-z]{2,3})((?:-[a-z0-9]{1,8})*)$/i', $code, $match)) {
            return null;
        }

        $language = strtolower($match[1]);

        if (in_array($language, ['und', 'mul', 'zxx', 'mis'], true)) {
            return null;
        }

        // Keep the region (two letters or three digits) and drop the rest —
        // a script or a variant is more than a customer's language needs.
        foreach (array_filter(explode('-', $match[2])) as $subtag) {
            if (preg_match('/^([a-z]{2}|\d{3})$/i', $subtag)) {
                return "{$language}-" . strtoupper($subtag);
            }
        }

        return $language;
    }

    /** The language without its region: "pt-BR" → "pt". */
    public static function base(string $code): string
    {
        return strtolower(explode('-', $code)[0]);
    }

    public static function same(?string $a, ?string $b): bool
    {
        return $a !== null && $b !== null && self::base($a) === self::base($b);
    }

    /** "Portuguese (pt-BR)" — what the model is told to translate into. */
    public static function describe(string $code): string
    {
        $name = self::NAMES[self::base($code)] ?? null;

        if ($name === null) {
            return $code;
        }

        // Portuguese without a region means Brazilian here: the platform was
        // built in Brazil and nearly every Portuguese speaker writing to it is.
        if ($code === 'pt') {
            return "{$name} (pt-BR)";
        }

        return $code === self::base($code) ? $name : "{$name} ({$code})";
    }

    /**
     * The language a flow is written in: the author's choice, or the
     * workspace's market language — which is what almost every flow is
     * written in, and why the field can stay empty.
     */
    public static function ofFlow(Flow $flow, ?Tenant $tenant = null): string
    {
        return self::normalize($flow->language)
            ?? self::ofTenant($tenant ?? $flow->tenant);
    }

    public static function ofTenant(?Tenant $tenant): string
    {
        return self::normalize($tenant?->market?->default_locale)
            ?? self::normalize(config('markets.default_locale'))
            ?? 'pt-BR';
    }
}

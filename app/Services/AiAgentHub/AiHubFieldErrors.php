<?php

namespace App\Services\AiAgentHub;

use App\Exceptions\UpstreamServiceException;
use App\Support\Errors\UpstreamProvider;
use Illuminate\Validation\ValidationException;

/**
 * The hub's refusal of a field the customer typed, said at that field.
 *
 * Saving an agent or a key goes to the AI hub, and the hub validates what it
 * receives. Until this existed every refusal came back as one sentence — "A
 * configuração deste agente de IA não foi aceita" — which is true and useless:
 * the person had filled in six fields and was not told which.
 *
 * ⚠️ The rules live at the hub and only there. Nothing here knows that a prompt
 * needs ten characters: it reads what the hub answered and moves it under the
 * field. Probed against the live hub (Nov 2026) the answers look like
 *
 *     400 {"message": ["systemPrompt must be longer than or equal to 10 characters",
 *                      "systemPrompt must be a string"], "statusCode": 400}
 *     413 {"message": "request entity too large", "statusCode": 413}
 *
 * — class-validator's own wording, one entry per broken rule, several per field.
 * A copy of the numbers here would be right the day it was written and silently
 * wrong the day the hub changes them; the first guess (8 for the prompt) was
 * already wrong.
 *
 * The hub's sentence still never reaches the customer. What is read from it is
 * structure — which property, which rule, which number — and the words are ours
 * (lang/{locale}/ai_hub.php), in the person's language, under the field key the
 * dashboard form already uses.
 *
 * Only properties the customer actually types are translated, and only at the
 * call sites that pass them. Anything else — `externalId`, `providerCredentialId`,
 * "property x should not exist", `status` — is ours to have sent wrong, not
 * theirs to fix, and keeps the generic copy with its reference.
 */
final class AiHubFieldErrors
{
    /** Agent properties a person types into the agent form. */
    public const AGENT = ['name', 'description', 'model', 'systemPrompt', 'temperature', 'maxTokens'];

    /** Credential properties a person types into the credential form. */
    public const CREDENTIAL = ['name', 'apiKey', 'defaultModel'];

    /**
     * Run a hub call; turn a refusal of customer fields into field errors.
     *
     * @template T
     *
     * @param  callable(): T  $call
     * @param  list<string>  $fields  Hub properties the customer controls.
     * @param  array<string, string>  $rename  Hub property → request key, where they differ.
     * @param  array<string, mixed>  $payload  What was sent, in the hub's names — read
     *                                         only to say which field a 413 was about.
     * @return T
     */
    public static function guard(callable $call, array $fields, array $rename = [], array $payload = []): mixed
    {
        try {
            return $call();
        } catch (UpstreamServiceException $e) {
            $errors = self::fromException($e, $fields, $rename, $payload);

            if ($errors === []) {
                throw $e;
            }

            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @param  list<string>  $fields
     * @param  array<string, string>  $rename
     * @param  array<string, mixed>  $payload
     * @return array<string, string>
     */
    public static function fromException(UpstreamServiceException $e, array $fields, array $rename = [], array $payload = []): array
    {
        if ($e->provider !== UpstreamProvider::AiHub) {
            return [];
        }

        if (($e->details['status'] ?? null) === 413) {
            return self::tooLarge($fields, $rename, $payload);
        }

        $errors = [];

        foreach ((array) ($e->details['hub_messages'] ?? []) as $message) {
            $parsed = self::parse((string) $message);

            if ($parsed === null || ! in_array($parsed['property'], $fields, true)) {
                continue;
            }

            $key = $rename[$parsed['property']] ?? $parsed['property'];

            // One sentence per field — the first the hub gave. It lists the
            // length rule before the type rule ("must be longer than or equal
            // to 10 characters", then "must be a string"), and the first is the
            // one a person can act on.
            $errors[$key] ??= self::sentence($parsed['property'], $parsed['rule'], $parsed['value']);
        }

        return $errors;
    }

    /**
     * Read one class-validator message as structure.
     *
     * The shapes are the ones the hub was seen answering: "longer/shorter than
     * or equal to N characters", "must not be less/greater than N", "should not
     * be empty", "must be a string / an object / a number conforming …", "must
     * be one of the following values: …". Anything else on a known field still
     * lands under it, as "not accepted".
     *
     * @return array{property: string, rule: string, value: ?string}|null
     */
    public static function parse(string $message): ?array
    {
        $message = trim($message);

        // "property foo should not exist" — a field we sent that the hub does
        // not know. Ours to fix, never the customer's.
        if (preg_match('/^property\s+\S+\s+should not exist/i', $message)) {
            return null;
        }

        if (! preg_match('/^([A-Za-z_]\w*)\s+(.+)$/s', $message, $m)) {
            return null;
        }

        [, $property, $rest] = $m;

        $rule = match (true) {
            (bool) preg_match('/longer than or equal to (\d+)/i', $rest, $n) => ['min_length', $n[1]],
            (bool) preg_match('/shorter than or equal to (\d+)/i', $rest, $n) => ['max_length', $n[1]],
            (bool) preg_match('/must not be less than (-?[\d.]+)/i', $rest, $n) => ['min_value', $n[1]],
            (bool) preg_match('/must not be greater than (-?[\d.]+)/i', $rest, $n) => ['max_value', $n[1]],
            (bool) preg_match('/should not be empty|must not be empty|is required/i', $rest) => ['required', null],
            default => ['invalid', null],
        };

        return ['property' => $property, 'rule' => $rule[0], 'value' => $rule[1]];
    }

    /**
     * Our sentence for one field and one rule, in the signed-in person's language.
     */
    public static function sentence(string $property, string $rule, ?string $value = null): string
    {
        $locale = self::locale();

        return __("ai_hub.rules.{$rule}", [
            'attribute' => self::attribute($property, $locale),
            'min' => (string) $value,
            'max' => (string) $value,
        ], $locale);
    }

    /**
     * A 413 refuses the whole request, not a field — but the request is a
     * handful of short fields and one or two long texts the person wrote, so
     * the longest of those is what made it too big, and saying so is what tells
     * them which box to shorten.
     *
     * @param  list<string>  $fields
     * @param  array<string, string>  $rename
     * @param  array<string, mixed>  $payload
     * @return array<string, string>
     */
    private static function tooLarge(array $fields, array $rename, array $payload): array
    {
        $longest = null;
        $length = 0;

        foreach ($fields as $property) {
            $value = $payload[$property] ?? null;

            if (is_string($value) && mb_strlen($value) > $length) {
                $longest = $property;
                $length = mb_strlen($value);
            }
        }

        if ($longest === null) {
            return [];
        }

        return [($rename[$longest] ?? $longest) => self::sentence($longest, 'too_large')];
    }

    /** One line of lang/{locale}/ai_hub.php, in the signed-in person's language. */
    public static function translate(string $key, array $replace = []): string
    {
        return __($key, $replace, self::locale());
    }

    private static function attribute(string $property, ?string $locale): string
    {
        $line = "ai_hub.attributes.{$property}";
        $label = __($line, [], $locale);

        return $label === $line ? $property : $label;
    }

    private static function locale(): ?string
    {
        $user = request()->user();
        $locale = $user && method_exists($user, 'localeCode') ? $user->localeCode() : null;

        return $locale && array_key_exists($locale, (array) config('markets.locales', [])) ? $locale : null;
    }
}

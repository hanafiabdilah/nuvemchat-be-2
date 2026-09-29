<?php

namespace App\Services\AiAgentHub\Tools;

/**
 * Arguments the model sent, checked against the schema we gave it — before a
 * single query runs.
 *
 * Only the handful of JSON Schema keywords AiToolCatalog uses: types integer
 * and string, required, enum, minimum/maximum, maxLength, and no properties
 * beyond the declared ones. A model that sends `price` to `cart_add` is refused
 * here with a sentence it can read, not ignored — silently dropping a field
 * would let it believe the price was taken.
 */
final class AiToolArguments
{
    /**
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $arguments
     * @return list<string> problems, as sentences; empty when valid
     */
    public static function problems(array $schema, array $arguments): array
    {
        $properties = (array) ($schema['properties'] ?? []);
        $problems = [];

        foreach (array_keys($arguments) as $key) {
            if (! array_key_exists($key, $properties)) {
                $problems[] = "Unknown argument \"{$key}\".";
            }
        }

        foreach ((array) ($schema['required'] ?? []) as $key) {
            if (! array_key_exists($key, $arguments) || $arguments[$key] === null || $arguments[$key] === '') {
                $problems[] = "Missing required argument \"{$key}\".";
            }
        }

        foreach ($properties as $key => $rule) {
            if (! array_key_exists($key, $arguments) || $arguments[$key] === null) {
                continue;
            }

            $value = $arguments[$key];
            $rule = (array) $rule;

            switch ($rule['type'] ?? null) {
                case 'integer':
                    // "3" from a model is a 3; 3.5 is not.
                    if (! (is_int($value) || (is_string($value) && preg_match('/^-?\d+$/', $value)) || (is_float($value) && floor($value) === $value))) {
                        $problems[] = "\"{$key}\" must be a whole number.";

                        break;
                    }
                    $int = (int) $value;
                    if (isset($rule['minimum']) && $int < $rule['minimum']) {
                        $problems[] = "\"{$key}\" must be at least {$rule['minimum']}.";
                    }
                    if (isset($rule['maximum']) && $int > $rule['maximum']) {
                        $problems[] = "\"{$key}\" must be at most {$rule['maximum']}.";
                    }
                    break;

                case 'string':
                    if (! is_string($value) && ! is_numeric($value)) {
                        $problems[] = "\"{$key}\" must be text.";

                        break;
                    }
                    if (isset($rule['maxLength']) && mb_strlen((string) $value) > $rule['maxLength']) {
                        $problems[] = "\"{$key}\" is longer than {$rule['maxLength']} characters.";
                    }
                    if (isset($rule['enum']) && ! in_array((string) $value, $rule['enum'], true)) {
                        $problems[] = "\"{$key}\" must be one of: ".implode(', ', $rule['enum']).'.';
                    }
                    break;
            }
        }

        return $problems;
    }
}

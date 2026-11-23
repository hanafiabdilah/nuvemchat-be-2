<?php

namespace App\Services\Incident;

use App\Models\Conversation;
use App\Services\Contact\ContactIdentity;

/**
 * The incident's words for one customer: `{{contact.name}}` and
 * `{{contact.first_name}}` filled in — the two tokens campaigns already teach
 * (VariableResolver), and the only two a notice has any use for.
 *
 * A contact with no real name (only a number, or a placeholder) gets nothing
 * rather than their number: "Olá 5511999990001!" is worse than "Olá!". The
 * spaces and commas left around an empty token are tidied up so the sentence
 * still reads.
 */
final class IncidentText
{
    public const TOKENS = ['contact.name', 'contact.first_name'];

    public static function render(string $text, Conversation $conversation): string
    {
        $name = ContactIdentity::name($conversation->getRelationValue('contact') ?? $conversation->contact) ?? '';
        $first = $name !== '' ? (string) strtok($name, ' ') : '';

        $rendered = (string) preg_replace_callback(
            '/\{\{\s*(contact\.name|contact\.first_name)\s*\}\}/',
            fn (array $match) => $match[1] === 'contact.first_name' ? $first : $name,
            $text,
        );

        if ($name === '') {
            // "Olá , tudo bem" → "Olá, tudo bem"; "Olá !" → "Olá!"
            $rendered = (string) preg_replace('/[ \t]+([,.!?;:])/', '$1', $rendered);
            $rendered = (string) preg_replace('/[ \t]{2,}/', ' ', $rendered);
        }

        return trim($rendered);
    }
}

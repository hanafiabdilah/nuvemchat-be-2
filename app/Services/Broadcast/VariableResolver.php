<?php

namespace App\Services\Broadcast;

use App\Models\BroadcastRecipient;

/**
 * Personalises a campaign payload for one recipient.
 *
 * The payload is stored exactly as the dashboard built it — for WhatsApp that
 * means the Meta `components` array verbatim, produced by the same
 * buildTemplateSendComponents() the one-off template sender uses — with tokens
 * left in place of the per-person values. Resolving is then a recursive walk
 * that rewrites string leaves and touches nothing else.
 *
 * Doing it this way is what keeps a second, drifting copy of the components
 * builder out of PHP: whatever shape the front end learns to build next
 * (carousel cards, button variables, a header we have not modelled yet) flows
 * through here untouched.
 *
 * Tokens deliberately reuse the {{name}} form the flow editor already teaches
 * (see lib/interactiveNodeOptions.ts and VariableField.tsx), so an operator
 * writing a campaign is not learning a second syntax.
 */
class VariableResolver
{
    /**
     * Every token an operator may write, in the order the picker offers them.
     * `contact.phone` and `contact.email` are aliases of `contact.address`:
     * same value, but a campaign author should not have to type "address" when
     * the channel plainly deals in phone numbers.
     */
    public const TOKENS = [
        'contact.name',
        'contact.first_name',
        'contact.phone',
        'contact.email',
        'contact.address',
    ];

    /**
     * Rewrite every token in $payload for this recipient.
     *
     * @param  mixed  $payload  Any JSON-shaped value: array, string, or scalar.
     * @return mixed  The same shape, with tokens substituted.
     */
    public function resolve(mixed $payload, BroadcastRecipient $recipient): mixed
    {
        $values = $this->valuesFor($recipient);

        return $this->walk($payload, $values);
    }

    /**
     * @param  array<string, string>  $values
     */
    private function walk(mixed $node, array $values): mixed
    {
        if (is_array($node)) {
            return array_map(fn ($child) => $this->walk($child, $values), $node);
        }

        if (! is_string($node)) {
            return $node;
        }

        return strtr($node, $values);
    }

    /**
     * Token → replacement for one recipient.
     *
     * Whitespace inside the braces is accepted because people type it: an
     * operator who writes `{{ contact.name }}` should not get a template that
     * greets the customer with the token itself.
     *
     * @return array<string, string>
     */
    private function valuesFor(BroadcastRecipient $recipient): array
    {
        $name = $recipient->displayName();

        $resolved = [
            'contact.name' => $name,
            // Enough for a greeting; a name that is only a phone number stays
            // whole rather than being cut at the first space it does not have.
            'contact.first_name' => trim(explode(' ', trim($name))[0] ?? $name),
            'contact.phone' => $recipient->address,
            'contact.email' => $recipient->address,
            'contact.address' => $recipient->address,
        ];

        $values = [];

        foreach ($resolved as $token => $value) {
            $values['{{' . $token . '}}'] = $value;
            $values['{{ ' . $token . ' }}'] = $value;
        }

        return $values;
    }
}

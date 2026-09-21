<?php

namespace App\Services\Webhooks;

use App\Support\PublicUrl;

/**
 * Where a webhook may point.
 *
 * A URL typed into the dashboard is a URL this server will request, so it must
 * not be a way to reach the platform's own network (the database, Reverb, the
 * queue, a cloud metadata address). Checked twice: when the endpoint is saved
 * (literal addresses and obvious internal names), and again before every
 * delivery against what the name resolves to at that moment — a hostname can
 * be pointed somewhere else after it was saved. Redirects are never followed.
 */
final class WebhookUrl
{
    /**
     * The reason this URL cannot be saved, or null when it can.
     *
     * The rule itself now lives in App\Support\PublicUrl, which the flow
     * engine's HTTP node and the media download path also read — the same
     * check was missing from both, and one copy is what keeps them from
     * drifting apart. The wording stays here: it is about webhooks, and a
     * shared sentence would have to stop being about anything.
     */
    public static function problem(string $url): ?string
    {
        $parts = parse_url(trim($url));
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));

        if (! $parts || $host === '' || ! in_array($scheme, ['https', 'http'], true)) {
            return 'Informe uma URL completa, começando com https://.';
        }

        // The payload carries customer names and phone numbers: in clear text
        // only on a developer's own machine.
        if ($scheme !== 'https' && ! app()->environment('local')) {
            return 'Use uma URL https:// — os eventos levam dados dos clientes.';
        }

        if (! PublicUrl::isPublic($url)) {
            return 'A URL precisa ser um endereço público na internet.';
        }

        return null;
    }

    /** Whether the URL's host currently resolves to a non-public address. */
    public static function resolvesToPrivate(string $url): bool
    {
        return PublicUrl::resolvesToPrivate($url);
    }
}

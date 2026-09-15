<?php

namespace App\Services\Webhooks;

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
    /** The reason this URL cannot be saved, or null when it can. */
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

        $internalName = $host === 'localhost'
            || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.local')
            || str_ends_with($host, '.internal')
            || (! str_contains($host, '.') && ! filter_var($host, FILTER_VALIDATE_IP));

        if ($internalName || (filter_var($host, FILTER_VALIDATE_IP) && ! self::isPublicIp($host))) {
            return 'A URL precisa ser um endereço público na internet.';
        }

        return null;
    }

    /** Whether the URL's host currently resolves to a non-public address. */
    public static function resolvesToPrivate(string $url): bool
    {
        $host = strtolower(trim((string) parse_url($url, PHP_URL_HOST), '[]'));

        if ($host === '') {
            return true;
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return ! self::isPublicIp($host);
        }

        // Unresolvable is not "private": the request will simply fail and be
        // retried, which is the honest outcome for a name that is down.
        $addresses = gethostbynamel($host) ?: [];

        foreach ($addresses as $address) {
            if (! self::isPublicIp($address)) {
                return true;
            }
        }

        return false;
    }

    private static function isPublicIp(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }
}

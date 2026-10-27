<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Whether an e-mail address is a throwaway one — a temp-mail inbox that lives
 * for minutes.
 *
 * Refused at signup because an account is an e-mail address the platform can
 * reach later (password recovery, billing, the owner of a workspace), and a
 * throwaway one is mostly how trial abuse and fake workspaces are registered.
 *
 * Two lists and a handful of markers:
 *  - resources/data/disposable-email-domains.txt — the community list from
 *    github.com/disposable-email-domains (CC0), copied verbatim;
 *  - resources/data/disposable-email-domains-extra.txt — our own additions;
 *  - MARKERS — words that only ever appear in a temp-mail service's domain,
 *    for the new ones neither list has caught up with yet.
 *
 * A listed domain covers its subdomains too (`x.mailinator.com`). Read from
 * disk once per process: only signup asks, and ten thousand lines is a few
 * milliseconds.
 *
 * ⚠️ No list catches everything — temp-mail services rotate fresh domains.
 * This raises the cost of a throwaway signup; it does not make one impossible.
 */
final class DisposableEmail
{
    private const LISTS = [
        'data/disposable-email-domains.txt',
        'data/disposable-email-domains-extra.txt',
    ];

    /** Fragments no legitimate mail domain carries. */
    private const MARKERS = [
        'tempmail', 'temp-mail', 'tmpmail', 'tempinbox',
        'minutemail', 'throwawaymail', 'trashmail', 'disposablemail',
        'guerrillamail', 'mailinator', 'yopmail', 'fakeinbox',
    ];

    /** @var array<string, true>|null */
    private static ?array $domains = null;

    public static function isDisposable(string $email): bool
    {
        $domain = self::domainOf($email);

        if ($domain === '') {
            return false;
        }

        foreach (self::MARKERS as $marker) {
            if (str_contains($domain, $marker)) {
                return true;
            }
        }

        $domains = self::domains();
        $labels = explode('.', $domain);

        // The domain itself, then every parent short of the bare TLD.
        for ($i = 0; $i < count($labels) - 1; $i++) {
            if (isset($domains[implode('.', array_slice($labels, $i))])) {
                return true;
            }
        }

        return false;
    }

    private static function domainOf(string $email): string
    {
        $at = strrpos($email, '@');

        if ($at === false) {
            return '';
        }

        return rtrim(Str::lower(trim(substr($email, $at + 1))), '.');
    }

    /** @return array<string, true> */
    private static function domains(): array
    {
        if (self::$domains !== null) {
            return self::$domains;
        }

        $domains = [];

        foreach (self::LISTS as $list) {
            $path = resource_path($list);

            if (! is_file($path)) {
                continue;
            }

            foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $line = Str::lower(trim($line));

                if ($line === '' || str_starts_with($line, '#')) {
                    continue;
                }

                $domains[$line] = true;
            }
        }

        return self::$domains = $domains;
    }
}

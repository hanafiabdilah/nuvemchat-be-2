<?php

namespace App\Services\Flow;

use App\Support\Market\CountryCatalog;

/**
 * The phone router node ("Rotear por DDI/DDD"): sends a lead down a different
 * path depending on where their number is from.
 *
 * Built for operations that sell in several countries from one number — a
 * Brazilian lead gets the Portuguese funnel, a Mexican one the Spanish one —
 * and, inside one country, by area code (DDD). Each route is one output, so
 * the usual wiring is one route → a "Go to another flow" node per funnel.
 *
 * Routes are matched by calling code (DDI), not by country: the number is all
 * there is to go on, and some codes are shared — +1 is the United States,
 * Canada and most of the Caribbean. Those split by area code: the Dominican
 * Republic is +1 with 809, 829 or 849.
 *
 * Two fixed outputs besides the routes: `other` for a number no route covers,
 * and `unknown` for a conversation with no phone number at all (Instagram,
 * Telegram, e-mail, the site widget). `unknown` left unwired falls back to
 * `other`, so a router with only "other" wired still catches everyone.
 *
 * The frontend mirrors these rules in `lib/phoneRouterNodes.ts`.
 */
final class PhoneRouterNodes
{
    public const BRANCH_OTHER = 'other';

    public const BRANCH_UNKNOWN = 'unknown';

    public const RESERVED_BRANCHES = [self::BRANCH_OTHER, self::BRANCH_UNKNOWN];

    public const MAX_ROUTES = 20;

    public const MAX_LABEL_LENGTH = 40;

    public const MAX_CODES_PER_ROUTE = 60;

    public const MAX_AREA_CODES_PER_ROUTE = 100;

    /** Calling code of the number, e.g. "55". Empty when there is no number. */
    public const VARIABLE_DDI = 'phone_ddi';

    /** Area code, where its length is fixed (Brazil's DDD, North America's). */
    public const VARIABLE_DDD = 'phone_ddd';

    /** The label of the route taken, or "other" / "unknown". */
    public const VARIABLE_ROUTE = 'phone_route';

    /**
     * Countries whose area code has one length, so {{phone_ddd}} can be read
     * off the number. Everywhere else the length varies and the variable stays
     * empty — the routes still match area-code prefixes there.
     */
    private const AREA_CODE_LENGTHS = ['55' => 2, '1' => 3];

    /** @var list<string>|null */
    private static ?array $codes = null;

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return [
            'routes' => [
                ['id' => 'route_1', 'label' => 'Brasil', 'ddi' => ['55'], 'ddd' => []],
            ],
        ];
    }

    /**
     * The routes as the engine reads them: ids and labels filled in, codes as
     * digit strings without duplicates. Area codes only count on a route with
     * exactly one calling code — "11" means São Paulo after +55 and nothing in
     * particular after +52, so on a route mixing the two it would mean neither.
     *
     * @param  array<string, mixed>  $data
     * @return list<array{id: string, label: string, ddi: list<string>, ddd: list<string>}>
     */
    public static function routes(array $data): array
    {
        $routes = [];

        foreach (array_slice((array) ($data['routes'] ?? []), 0, self::MAX_ROUTES) as $index => $route) {
            if (! is_array($route)) {
                continue;
            }

            $ddi = self::digitList($route['ddi'] ?? [], 3, self::MAX_CODES_PER_ROUTE);
            $ddd = count($ddi) === 1 ? self::digitList($route['ddd'] ?? [], 6, self::MAX_AREA_CODES_PER_ROUTE) : [];
            $id = trim((string) ($route['id'] ?? ''));
            $label = trim((string) ($route['label'] ?? ''));

            $routes[] = [
                'id' => $id !== '' ? $id : 'route_'.($index + 1),
                'label' => $label !== '' ? mb_substr($label, 0, self::MAX_LABEL_LENGTH) : ($ddi !== [] ? '+'.implode(' +', $ddi) : 'Rota '.($index + 1)),
                'ddi' => $ddi,
                'ddd' => $ddd,
            ];
        }

        return $routes;
    }

    /**
     * Every output the node has: one per route, then the two fallbacks.
     *
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    public static function branches(array $data): array
    {
        return array_merge(array_column(self::routes($data), 'id'), self::RESERVED_BRANCHES);
    }

    /**
     * Where a number goes.
     *
     * Routes are tried in order and the first that matches wins — so a route
     * for São Paulo's DDDs placed above a route for all of Brazil takes São
     * Paulo, and the second takes the rest. A route without an edge is skipped
     * rather than ending the flow: an output nobody wired yet should not
     * swallow every lead it matches.
     *
     * @param  list<array{id: string, label: string, ddi: list<string>, ddd: list<string>}>  $routes
     * @param  list<string>  $wired  the branch values that have an edge
     * @return array{branch: string|null, route: string, ddi: string, ddd: string}
     *                                                                             `branch` null when nothing that matches is wired;
     *                                                                             `route` is what {{phone_route}} holds
     */
    public static function resolve(array $routes, ?string $phone, array $wired): array
    {
        $digits = preg_replace('/\D+/', '', (string) $phone) ?? '';

        if ($digits === '') {
            return [
                'branch' => self::firstWired([self::BRANCH_UNKNOWN, self::BRANCH_OTHER], $wired),
                'route' => self::BRANCH_UNKNOWN,
                'ddi' => '',
                'ddd' => '',
            ];
        }

        $ddi = self::callingCode($digits, array_merge(...array_column($routes, 'ddi'))) ?? '';
        $national = $ddi !== '' ? substr($digits, strlen($ddi)) : $digits;
        $ddd = isset(self::AREA_CODE_LENGTHS[$ddi]) ? substr($national, 0, self::AREA_CODE_LENGTHS[$ddi]) : '';

        foreach ($routes as $route) {
            if ($ddi === '' || ! in_array($ddi, $route['ddi'], true) || ! in_array($route['id'], $wired, true)) {
                continue;
            }

            if ($route['ddd'] !== [] && ! self::startsWithAny($national, $route['ddd'])) {
                continue;
            }

            return ['branch' => $route['id'], 'route' => $route['label'], 'ddi' => $ddi, 'ddd' => $ddd];
        }

        return [
            'branch' => self::firstWired([self::BRANCH_OTHER], $wired),
            'route' => self::BRANCH_OTHER,
            'ddi' => $ddi,
            'ddd' => $ddd,
        ];
    }

    /**
     * The number's calling code: the prefix that is a known code. Calling codes
     * are prefix-free by design (no code starts another), so the first length
     * that matches is the only one.
     *
     * @param  list<string>  $extra  codes the author picked, so a code missing
     *                               from the catalog still routes
     */
    public static function callingCode(string $digits, array $extra = []): ?string
    {
        $codes = array_flip(array_merge(self::knownCodes(), $extra));

        foreach ([1, 2, 3] as $length) {
            $prefix = substr($digits, 0, $length);

            if (strlen($prefix) === $length && isset($codes[$prefix])) {
                return $prefix;
            }
        }

        return null;
    }

    /** @return list<string> every calling code in the country catalog */
    public static function knownCodes(): array
    {
        return self::$codes ??= array_values(array_unique(array_filter(
            array_map(fn (array $country) => (string) ($country['calling_code'] ?? ''), CountryCatalog::all())
        )));
    }

    /**
     * @param  list<string>  $candidates
     * @param  list<string>  $wired
     */
    private static function firstWired(array $candidates, array $wired): ?string
    {
        foreach ($candidates as $candidate) {
            if (in_array($candidate, $wired, true)) {
                return $candidate;
            }
        }

        return null;
    }

    /** @param  list<string>  $prefixes */
    private static function startsWithAny(string $value, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if (str_starts_with($value, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private static function digitList(mixed $values, int $maxLength, int $maxCount): array
    {
        $list = [];

        foreach (is_array($values) ? $values : [] as $value) {
            $digits = preg_replace('/\D+/', '', (string) $value) ?? '';

            if ($digits !== '' && strlen($digits) <= $maxLength && ! in_array($digits, $list, true)) {
                $list[] = $digits;
            }
        }

        return array_slice($list, 0, $maxCount);
    }
}

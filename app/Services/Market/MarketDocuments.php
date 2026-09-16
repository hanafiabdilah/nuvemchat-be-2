<?php

namespace App\Services\Market;

use App\Models\Market;
use Illuminate\Support\Facades\Cache;

/**
 * The tax number a country's payment rails demand of a payer.
 *
 * Every charge carries one: the Brazilian acquirer refuses a Pix or a boleto
 * without a CPF, and local rails elsewhere ask for their own. Until markets
 * existed this was hard-coded to CPF/CNPJ in three places, which did not read
 * as a Brazilian assumption — it read as a workspace in another country being
 * unable to save a billing profile at all, and therefore unable to pay for
 * anything, with a Portuguese sentence about digit counts as the only clue.
 *
 * Three layers, in order: what an admin set for the market, then the shapes in
 * config/markets.php, then a generic tax id. The admin layer is what makes
 * **"no document at all"** expressible — a stored empty list — because some
 * countries' rails simply do not ask, and config cannot say that: an absent key
 * there means "nobody has written this country down yet", which is a different
 * statement and deserves the generic fallback rather than a free pass.
 */
final class MarketDocuments
{
    /**
     * The document types a market accepts. Empty = this country asks for none.
     *
     * @return list<array{code: string, min: int, max: int}>
     */
    public static function forMarket(?string $marketCode): array
    {
        $code = self::code($marketCode);
        $stored = self::stored($code);

        // An empty stored list is a decision ("no document here"), so it is
        // returned as-is; only an absent one falls through to config.
        if ($stored !== null) {
            return $stored;
        }

        return self::configured($code);
    }

    /**
     * What this country would ask for with nothing decided — the config layer.
     *
     * The Back Office shows it beside the editor so an admin can tell a list
     * somebody typed from the one the platform shipped.
     *
     * @return list<array{code: string, min: int, max: int}>
     */
    public static function defaultsFor(?string $marketCode): array
    {
        return self::configured(self::code($marketCode));
    }

    /** Whether a charge here needs a document at all. */
    public static function required(?string $marketCode): bool
    {
        return self::forMarket($marketCode) !== [];
    }

    /** @return list<string> */
    public static function codes(?string $marketCode): array
    {
        return array_map(fn (array $doc) => $doc['code'], self::forMarket($marketCode));
    }

    /**
     * Why this number is not a valid document of that type here, or null when
     * it is.
     *
     * Digits only, counted after punctuation is stripped: people type the dots
     * and dashes their country writes, and refusing those teaches nothing. The
     * check digits are the acquirer's business — rejecting a valid edge case
     * ourselves is worse than passing it on.
     */
    public static function problem(?string $marketCode, string $type, string $number): ?string
    {
        $accepted = self::forMarket($marketCode);

        // Nothing is asked for here, so nothing can be wrong with it.
        if ($accepted === []) {
            return null;
        }

        $type = strtoupper(trim($type));
        $digits = preg_replace('/\D/', '', $number) ?? '';
        $length = strlen($digits);

        foreach ($accepted as $doc) {
            if (strtoupper($doc['code']) !== $type) {
                continue;
            }

            if ($length >= $doc['min'] && $length <= $doc['max']) {
                return null;
            }

            return $doc['min'] === $doc['max']
                ? __('A :type has :count digits.', ['type' => $doc['code'], 'count' => $doc['min']])
                : __('A :type has between :min and :max digits.', [
                    'type' => $doc['code'],
                    'min' => $doc['min'],
                    'max' => $doc['max'],
                ]);
        }

        return __('Choose one of: :types.', ['types' => implode(', ', self::codes($marketCode))]);
    }

    /**
     * Keep only well-formed entries, and cap the code at what the column holds.
     *
     * `tenants.billing_document_type` is a string(8); a longer code would be
     * truncated on save and then never match its own rule again.
     *
     * @param  array<int, mixed>  $input
     * @return list<array{code: string, min: int, max: int}>
     */
    public static function sanitize(array $input): array
    {
        $clean = [];

        foreach ($input as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $code = strtoupper(trim((string) ($entry['code'] ?? '')));

            if ($code === '' || strlen($code) > 8) {
                continue;
            }

            $min = max(1, (int) ($entry['min'] ?? 1));
            $max = max($min, (int) ($entry['max'] ?? $min));

            $clean[] = ['code' => $code, 'min' => $min, 'max' => min(32, $max)];
        }

        return $clean;
    }

    public static function flush(?string $marketCode = null): void
    {
        if ($marketCode === null) {
            foreach (Market::query()->pluck('code') as $code) {
                Cache::forget(self::cacheKey($code));
            }

            return;
        }

        Cache::forget(self::cacheKey(self::code($marketCode)));
    }

    /**
     * @return list<array{code: string, min: int, max: int}>|null null = nothing decided
     */
    private static function stored(string $code): ?array
    {
        $value = Cache::rememberForever(
            self::cacheKey($code),
            // Wrapped: a cache cannot tell "no row" from "null column" once it
            // stores either as null, and those mean different things here.
            fn () => ['documents' => Market::query()->whereKey($code)->value('documents')],
        );

        $documents = $value['documents'] ?? null;

        return is_array($documents) ? self::sanitize($documents) : null;
    }

    /**
     * @return list<array{code: string, min: int, max: int}>
     */
    private static function configured(string $code): array
    {
        /** @var array<string, list<array{code: string, min: int, max: int}>> $map */
        $map = config('markets.documents', []);

        return $map[$code] ?? config('markets.default_documents', [
            ['code' => 'TAX_ID', 'min' => 5, 'max' => 20],
        ]);
    }

    private static function code(?string $marketCode): string
    {
        $code = strtoupper(trim((string) $marketCode));

        return $code !== '' ? $code : MarketResolver::defaultCode();
    }

    private static function cacheKey(string $code): string
    {
        return "market:documents:{$code}";
    }
}

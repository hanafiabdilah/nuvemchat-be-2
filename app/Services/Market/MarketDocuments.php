<?php

namespace App\Services\Market;

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
 * The shapes live in config/markets.php. A country with no entry gets a generic
 * tax id rather than a refusal: opening a market must not wait on somebody
 * adding a row here, and the acquirer validates the number properly anyway.
 */
final class MarketDocuments
{
    /**
     * The document types a market accepts.
     *
     * @return list<array{code: string, min: int, max: int}>
     */
    public static function forMarket(?string $marketCode): array
    {
        $code = strtoupper(trim((string) $marketCode));

        /** @var array<string, list<array{code: string, min: int, max: int}>> $map */
        $map = config('markets.documents', []);

        return $map[$code] ?? config('markets.default_documents', [
            ['code' => 'TAX_ID', 'min' => 5, 'max' => 20],
        ]);
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
        $type = strtoupper(trim($type));
        $digits = preg_replace('/\D/', '', $number) ?? '';
        $length = strlen($digits);

        foreach (self::forMarket($marketCode) as $doc) {
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
}

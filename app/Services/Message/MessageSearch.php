<?php

namespace App\Services\Message;

use App\Support\SqlDialect;
use Illuminate\Database\Eloquent\Builder;

/**
 * Turning what somebody typed into the search box into a predicate over
 * `messages.body`.
 *
 * This used to happen in the browser: the panel held every message this device
 * had ever downloaded and ran `body.includes(term)` over all of them. That is
 * unbounded by construction — and it could only ever find what the client had
 * already pulled, which is exactly the guarantee that breaks the moment the
 * client stops mirroring the whole history.
 *
 * ⚠️ The two drivers do NOT search the same way, and the difference is visible
 * to the reader:
 *
 * - MySQL/MariaDB (production) uses the FULLTEXT index on `messages.body` in
 *   BOOLEAN MODE. That matches *words*, not substrings — every term is sent as
 *   `+term*`, so a prefix still matches ("orcam" finds "orçamento"), but a term
 *   that starts mid-word does not ("çamento" finds nothing). Accents are
 *   handled by the column's collation, not here.
 * - SQLite (the test suite) has no such index, so it falls back to `LIKE
 *   %term%` — the old substring behaviour. Tests therefore exercise the
 *   *shape* of the query, never the ranking or the word-boundary rules.
 *
 * Terms shorter than MIN_TERM_LENGTH are dropped rather than searched: InnoDB
 * will not have indexed them (`innodb_ft_min_token_size` defaults to 3), so
 * asking for them returns nothing while looking like a search that ran.
 */
class MessageSearch
{
    /**
     * Shortest term the index can answer for. Mirrored in the SPA
     * (services/messageService.ts) so the box can say so before it asks.
     */
    public const MIN_TERM_LENGTH = 3;

    /**
     * Split the raw box into terms the index can answer for.
     *
     * Everything that is not a letter, digit or underscore is a separator —
     * which doubles as the escaping: boolean-mode operators (+ - * " ~ < > ( )
     * @) can never survive into the expression built below, so a query of
     * `+"foo` is three characters of punctuation and one term, not a syntax
     * error from the server.
     */
    public static function terms(string $raw): array
    {
        $parts = preg_split('/[^\p{L}\p{N}_]+/u', mb_strtolower(trim($raw))) ?: [];

        return array_values(array_unique(array_filter(
            $parts,
            fn (string $term) => mb_strlen($term) >= self::MIN_TERM_LENGTH
        )));
    }

    /**
     * The BOOLEAN MODE expression for a set of terms: every term required, and
     * every term open-ended at the right.
     *
     * `+` because a two-word search means both words — the default (neither
     * required nor excluded) would return rows carrying only one of them,
     * ranked lower but still there, which reads as the search ignoring half of
     * what was typed. `*` because people search by typing the beginning of a
     * word and stopping.
     */
    public static function booleanExpression(array $terms): string
    {
        return implode(' ', array_map(fn (string $term) => '+'.$term.'*', $terms));
    }

    /**
     * Constrain a message query to rows whose body matches every term.
     *
     * Caller passes the output of terms(); an empty array is the caller's
     * problem to short-circuit, because "no searchable terms" and "no results"
     * are different answers and only the caller can say which one to render.
     */
    public static function apply(Builder $query, array $terms): Builder
    {
        if ($terms === []) {
            return $query;
        }

        if (SqlDialect::isSqlite()) {
            foreach ($terms as $term) {
                $query->where('messages.body', 'like', '%'.$term.'%');
            }

            return $query;
        }

        return $query->whereRaw(
            'MATCH (messages.body) AGAINST (? IN BOOLEAN MODE)',
            [self::booleanExpression($terms)]
        );
    }
}

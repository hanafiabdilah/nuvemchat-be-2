<?php

namespace App\Services\Flow;

use Carbon\CarbonImmutable;

/**
 * The split test node: shares new conversations between two or more variants
 * of a funnel, each variant being one output. What sits after an output is the
 * author's — usually a Go-to-flow node pointing at the flow being tested.
 *
 * Two ways to share them out, because the request asked for both:
 *
 *   rotate   — every variant runs at once, conversations dealt out by weight
 *              (A, B, A, B… for equal weights). The fair test: both variants
 *              meet the same hours, the same ads, the same audience.
 *   sequence — one variant at a time. The first takes its `limit`, then the
 *              next one takes over, and the last takes everything after.
 *
 * The choice is deterministic — it reads the counts so far, it does not roll a
 * die — so twenty conversations in, a 50/50 test is 10/10 and not 13/7.
 *
 * Counts live in `flow_split_assignments`, keyed by the flow and the variant
 * id, never by the node id: a node created in an open editor is rebuilt with a
 * new id on every save until the page is reloaded, and a counter keyed by it
 * would start over each time. Variant ids are random strings the builder
 * mints, which is also what makes them the edges' branch values.
 *
 * Mirrors, on the frontend: src/lib/splitTestNodes.ts
 */
final class SplitTestNodes
{
    public const MODE_ROTATE = 'rotate';

    public const MODE_SEQUENCE = 'sequence';

    public const MODES = [self::MODE_ROTATE, self::MODE_SEQUENCE];

    public const MAX_VARIANTS = 10;

    public const MAX_LABEL_LENGTH = 40;

    public const MAX_WEIGHT = 100;

    public const MAX_LIMIT = 1000000;

    /** Holds the label of the variant the conversation went to, for later nodes. */
    public const VARIABLE = 'split_variant';

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return [
            'mode' => self::MODE_ROTATE,
            'variants' => [
                ['id' => 'variant_a', 'label' => 'A', 'weight' => 1, 'limit' => null],
                ['id' => 'variant_b', 'label' => 'B', 'weight' => 1, 'limit' => null],
            ],
            'started_at' => null,
        ];
    }

    /** @param  array<string, mixed>  $data */
    public static function mode(array $data): string
    {
        return in_array($data['mode'] ?? null, self::MODES, true) ? $data['mode'] : self::MODE_ROTATE;
    }

    /**
     * The variants, in the order they are drawn.
     *
     * A variant without an id falls back to its position, the way an
     * interactive option does — so a hand-written file still branches. A blank
     * label is its letter: "A", "B"…
     *
     * @param  array<string, mixed>  $data
     * @return list<array{id: string, label: string, weight: int, limit: int|null}>
     */
    public static function variants(array $data): array
    {
        $variants = [];

        foreach (array_values((array) ($data['variants'] ?? [])) as $index => $variant) {
            if (! is_array($variant) || $index >= self::MAX_VARIANTS) {
                continue;
            }

            $id = trim((string) ($variant['id'] ?? ''));
            $label = trim((string) ($variant['label'] ?? ''));
            $limit = (int) ($variant['limit'] ?? 0);

            $variants[] = [
                'id' => $id !== '' ? $id : 'variant_'.($index + 1),
                'label' => $label !== '' ? mb_substr($label, 0, self::MAX_LABEL_LENGTH) : self::letter($index),
                'weight' => max(1, min(self::MAX_WEIGHT, (int) ($variant['weight'] ?? 1))),
                'limit' => $limit > 0 ? min(self::MAX_LIMIT, $limit) : null,
            ];
        }

        return $variants;
    }

    /** @param  array<string, mixed>  $data */
    public static function branches(array $data): array
    {
        return array_column(self::variants($data), 'id');
    }

    /**
     * Where counting starts. "Restart the test" moves it to now; the rows
     * before it stay, so restarting never destroys a result someone may still
     * want to compare against.
     *
     * @param  array<string, mixed>  $data
     */
    public static function startedAt(array $data): ?CarbonImmutable
    {
        $value = $data['started_at'] ?? null;

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The variant the next conversation goes to.
     *
     * Only the variants passed in compete — the executor passes the ones with
     * an edge, because a conversation dealt to an unwired output would be
     * counted for a funnel it never saw.
     *
     * @param  list<array{id: string, label: string, weight: int, limit: int|null}>  $variants
     * @param  array<string, int>  $counts  conversations per variant id since the test started
     */
    public static function choose(array $variants, array $counts, string $mode): ?string
    {
        if ($variants === []) {
            return null;
        }

        if ($mode === self::MODE_SEQUENCE) {
            $last = count($variants) - 1;

            foreach ($variants as $index => $variant) {
                // The last one has no limit to reach: it takes everything that
                // is left, rather than the flow stopping once all are full.
                if ($index === $last || $variant['limit'] === null || ($counts[$variant['id']] ?? 0) < $variant['limit']) {
                    return $variant['id'];
                }
            }
        }

        // The one furthest behind its share: lowest count per unit of weight,
        // compared by cross-multiplying so no float decides a tie. Ties go to
        // the earlier variant, which is what makes equal weights alternate.
        $best = $variants[0];

        foreach (array_slice($variants, 1) as $variant) {
            $mine = ($counts[$variant['id']] ?? 0) * $best['weight'];
            $theirs = ($counts[$best['id']] ?? 0) * $variant['weight'];

            if ($mine < $theirs) {
                $best = $variant;
            }
        }

        return $best['id'];
    }

    /** "A" for the first variant, "J" for the tenth. */
    public static function letter(int $index): string
    {
        return chr(65 + max(0, min(25, $index)));
    }
}

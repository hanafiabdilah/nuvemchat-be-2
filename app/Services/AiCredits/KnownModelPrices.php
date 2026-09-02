<?php

namespace App\Services\AiCredits;

/**
 * Provider list prices, in USD per 1M tokens, used to pre-fill the Back Office
 * model form.
 *
 * ⚠️ **A reference, not a source of truth.** Providers change these, and this
 * file only changes when somebody edits it — so what is stored on the
 * `ai_model_prices` row is what counts, and the form says out loud that the
 * suggested figure should be confirmed against the provider.
 *
 * It exists because the alternative was worse: an admin typing four numbers by
 * hand for every model, from a page they had to go and find, with a typo in the
 * decimal place changing the published price by a factor of ten.
 *
 * When the hub reports a price of its own, that wins — see
 * AiModelCatalog::suggestionFor(). This is the floor under that.
 */
class KnownModelPrices
{
    /**
     * provider => [model => [input per 1M, output per 1M]].
     *
     * Keys are the base model id. Dated variants (`gpt-4o-2024-08-06`) resolve
     * to their base by longest-prefix match, because that is how providers name
     * snapshots and a dated id is otherwise simply unknown.
     *
     * @var array<string, array<string, array{float, float}>>
     */
    private const CATALOG = [
        'OPENAI' => [
            'gpt-4.1' => [2.00, 8.00],
            'gpt-4.1-mini' => [0.40, 1.60],
            'gpt-4.1-nano' => [0.10, 0.40],
            'gpt-4o' => [2.50, 10.00],
            'gpt-4o-mini' => [0.15, 0.60],
            'o3' => [2.00, 8.00],
            'o3-mini' => [1.10, 4.40],
            'o4-mini' => [1.10, 4.40],
        ],
        'ANTHROPIC' => [
            'claude-opus-4' => [15.00, 75.00],
            'claude-sonnet-4' => [3.00, 15.00],
            'claude-haiku-4-5' => [1.00, 5.00],
            'claude-3-5-haiku' => [0.80, 4.00],
            'claude-3-5-sonnet' => [3.00, 15.00],
        ],
        'GEMINI' => [
            'gemini-2.5-pro' => [1.25, 10.00],
            'gemini-2.5-flash' => [0.30, 2.50],
            'gemini-2.0-flash' => [0.10, 0.40],
        ],
    ];

    /**
     * The reference price for a model, or null when we have never heard of it.
     *
     * Null is a normal answer — a new model ships before this file learns about
     * it — and the form then leaves the price fields empty rather than guessing.
     *
     * @return array{input: float, output: float}|null
     */
    public static function for(?string $provider, ?string $model): ?array
    {
        $models = self::CATALOG[strtoupper((string) $provider)] ?? null;

        if ($models === null || $model === null) {
            return null;
        }

        $needle = strtolower($model);

        if (isset($models[$needle])) {
            return ['input' => $models[$needle][0], 'output' => $models[$needle][1]];
        }

        // Longest prefix wins: `gpt-4o-mini-2024-07-18` must resolve to
        // `gpt-4o-mini`, not to `gpt-4o`, which is sixteen times the price.
        $best = null;
        $bestLength = 0;

        foreach ($models as $id => $price) {
            if (str_starts_with($needle, $id) && strlen($id) > $bestLength) {
                $best = $price;
                $bestLength = strlen($id);
            }
        }

        return $best === null ? null : ['input' => $best[0], 'output' => $best[1]];
    }

    /** Providers this file knows anything about. @return list<string> */
    public static function providers(): array
    {
        return array_keys(self::CATALOG);
    }
}

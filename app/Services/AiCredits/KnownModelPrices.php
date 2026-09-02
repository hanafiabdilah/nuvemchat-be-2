<?php

namespace App\Services\AiCredits;

/**
 * The models the Back Office can price, and what their providers list them at
 * (USD per 1M tokens).
 *
 * **This is the catalogue, not a fallback.** It used to be one — the dropdown
 * was filled from the hub's `/models` and this only supplied a price when the
 * hub omitted one. That inverted the reliability: the hub's list is incomplete,
 * so models a workspace could perfectly well run were simply missing from the
 * form, and no amount of correctness elsewhere puts them back.
 *
 * Hub-reported models are still merged in on top, because a model the hub knows
 * and this file does not is real. But the list here is always present, whatever
 * the hub answers or whether it answers at all.
 *
 * ⚠️ Prices are a **reference to verify**, not an authority. Providers change
 * them and this file changes only when someone edits it, so the Back Office
 * marks a suggestion as such and what ends up on the `ai_model_prices` row is
 * what counts. The point is not to be right forever — it is that nobody should
 * be typing four decimal numbers copied from a pricing page.
 *
 * ⚠️ And it **will** fall behind. A model released tomorrow is not in here, and
 * "wait for this file to be edited" is not an acceptable answer to "we want to
 * sell the new one". That is what the picker's *Other model* option is for: any
 * id can be typed and priced the day it ships, and the row that gets saved works
 * exactly like one seeded from here. Adding it to this list later only saves the
 * next person the lookup.
 */
class KnownModelPrices
{
    /**
     * provider => model id => [label, input per 1M, output per 1M].
     *
     * Keys are base model ids. Dated snapshots (`gpt-4o-2024-08-06`) resolve to
     * their base by longest-prefix match, because that is how providers name
     * them and a dated id is otherwise simply unknown.
     *
     * @var array<string, array<string, array{0: string, 1: float, 2: float}>>
     */
    private const CATALOG = [
        'OPENAI' => [
            'gpt-5.1' => ['GPT-5.1', 1.25, 10.00],
            'gpt-5' => ['GPT-5', 1.25, 10.00],
            'gpt-5-mini' => ['GPT-5 mini', 0.25, 2.00],
            'gpt-5-nano' => ['GPT-5 nano', 0.05, 0.40],
            'gpt-4.1' => ['GPT-4.1', 2.00, 8.00],
            'gpt-4.1-mini' => ['GPT-4.1 mini', 0.40, 1.60],
            'gpt-4.1-nano' => ['GPT-4.1 nano', 0.10, 0.40],
            'gpt-4o' => ['GPT-4o', 2.50, 10.00],
            'gpt-4o-mini' => ['GPT-4o mini', 0.15, 0.60],
            'o3' => ['o3', 2.00, 8.00],
            'o3-mini' => ['o3-mini', 1.10, 4.40],
            'o4-mini' => ['o4-mini', 1.10, 4.40],
        ],
        'ANTHROPIC' => [
            'claude-opus-4-5' => ['Claude Opus 4.5', 5.00, 25.00],
            'claude-opus-4-1' => ['Claude Opus 4.1', 15.00, 75.00],
            'claude-sonnet-4-5' => ['Claude Sonnet 4.5', 3.00, 15.00],
            'claude-haiku-4-5' => ['Claude Haiku 4.5', 1.00, 5.00],
            'claude-opus-4' => ['Claude Opus 4', 15.00, 75.00],
            'claude-sonnet-4' => ['Claude Sonnet 4', 3.00, 15.00],
            'claude-3-5-sonnet' => ['Claude 3.5 Sonnet', 3.00, 15.00],
            'claude-3-5-haiku' => ['Claude 3.5 Haiku', 0.80, 4.00],
        ],
        'GEMINI' => [
            'gemini-3-pro' => ['Gemini 3 Pro', 2.00, 12.00],
            'gemini-2.5-pro' => ['Gemini 2.5 Pro', 1.25, 10.00],
            'gemini-2.5-flash' => ['Gemini 2.5 Flash', 0.30, 2.50],
            'gemini-2.5-flash-lite' => ['Gemini 2.5 Flash Lite', 0.10, 0.40],
            'gemini-2.0-flash' => ['Gemini 2.0 Flash', 0.10, 0.40],
        ],
    ];

    /**
     * Every model this file knows, flattened.
     *
     * @return list<array{provider: string, id: string, name: string, input: float, output: float}>
     */
    public static function all(): array
    {
        $models = [];

        foreach (self::CATALOG as $provider => $entries) {
            foreach ($entries as $id => [$label, $input, $output]) {
                $models[] = [
                    'provider' => $provider,
                    'id' => $id,
                    'name' => $label,
                    'input' => $input,
                    'output' => $output,
                ];
            }
        }

        return $models;
    }

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
            return ['input' => $models[$needle][1], 'output' => $models[$needle][2]];
        }

        // Longest prefix wins: `gpt-4o-mini-2024-07-18` must resolve to
        // `gpt-4o-mini`, not to `gpt-4o`, which is sixteen times the price.
        $best = null;
        $bestLength = 0;

        foreach ($models as $id => $entry) {
            if (str_starts_with($needle, $id) && strlen($id) > $bestLength) {
                $best = $entry;
                $bestLength = strlen($id);
            }
        }

        return $best === null ? null : ['input' => $best[1], 'output' => $best[2]];
    }

    /** Providers this file knows anything about. @return list<string> */
    public static function providers(): array
    {
        return array_keys(self::CATALOG);
    }
}

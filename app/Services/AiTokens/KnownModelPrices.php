<?php

namespace App\Services\AiTokens;

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
 * ⚠️ And it is now **the only way a model reaches a dropdown** besides the
 * hub reporting it. Every picker used to end in "Other model (type the id)",
 * and that box was where `gpt-4o-mini ` with a trailing space and ids no
 * provider serves came from — found only when a customer's agent failed to
 * run. It is gone (Nov 2026), so a model released tomorrow is sold the day
 * somebody adds one line here, or the day the hub starts listing it. Keep the
 * list current; a stale one is now a model nobody can choose.
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
        // Checked against developers.openai.com/api/docs/pricing on
        // 12 Nov 2026, standard tier, short context. `-pro` variants are left
        // out on purpose: ten times the price of their base model for a chat
        // reply is never what someone picking from a list meant.
        'OPENAI' => [
            'gpt-6.1-sol' => ['GPT-6.1 Sol', 2.00, 10.00],
            'gpt-6.1-terra' => ['GPT-6.1 Terra', 1.00, 5.00],
            'gpt-6-astra' => ['GPT-6 Astra', 10.00, 50.00],
            'gpt-6-sol' => ['GPT-6 Sol', 2.00, 10.00],
            'gpt-6-luna' => ['GPT-6 Luna', 0.10, 0.50],
            'gpt-5.6-sol' => ['GPT-5.6 Sol', 4.00, 20.00],
            'gpt-5.6-terra' => ['GPT-5.6 Terra', 2.00, 12.00],
            'gpt-5.6-luna' => ['GPT-5.6 Luna', 0.20, 1.20],
            'gpt-5.5' => ['GPT-5.5', 5.00, 30.00],
            'gpt-5.4' => ['GPT-5.4', 2.50, 15.00],
            'gpt-5.4-mini' => ['GPT-5.4 mini', 0.75, 4.50],
            'gpt-5.4-nano' => ['GPT-5.4 nano', 0.20, 1.25],
            'gpt-5.2' => ['GPT-5.2', 1.75, 14.00],
            'gpt-5.1' => ['GPT-5.1', 1.25, 10.00],
            'gpt-5' => ['GPT-5', 1.25, 10.00],
            'gpt-5-mini' => ['GPT-5 mini', 0.25, 2.00],
            'gpt-5-nano' => ['GPT-5 nano', 0.05, 0.40],
            'gpt-4.1' => ['GPT-4.1', 2.00, 8.00],
            'gpt-4.1-mini' => ['GPT-4.1 mini', 0.40, 1.60],
            // Gone from OpenAI's price page, still served by the hub.
            'gpt-4.1-nano' => ['GPT-4.1 nano', 0.10, 0.40],
            'gpt-4o' => ['GPT-4o', 2.50, 10.00],
            'gpt-4o-mini' => ['GPT-4o mini', 0.15, 0.60],
            'o3' => ['o3', 2.00, 8.00],
        ],
        // Anthropic's current models, same date. Mythos is left out: it is
        // sold to verified organisations only, so a workspace picking it from
        // a list would be picking something its key cannot run.
        'ANTHROPIC' => [
            'claude-fable-5-2' => ['Claude Fable 5.2', 8.00, 40.00],
            'claude-fable-5-1' => ['Claude Fable 5.1', 10.00, 50.00],
            'claude-fable-5' => ['Claude Fable 5', 10.00, 50.00],
            'claude-opus-5-5' => ['Claude Opus 5.5', 4.00, 20.00],
            'claude-opus-5' => ['Claude Opus 5', 5.00, 25.00],
            'claude-opus-4-8' => ['Claude Opus 4.8', 5.00, 25.00],
            'claude-opus-4-7' => ['Claude Opus 4.7', 5.00, 25.00],
            'claude-opus-4-6' => ['Claude Opus 4.6', 5.00, 25.00],
            'claude-sonnet-5-5' => ['Claude Sonnet 5.5', 2.00, 10.00],
            'claude-sonnet-5' => ['Claude Sonnet 5', 2.00, 10.00],
            'claude-sonnet-4-6' => ['Claude Sonnet 4.6', 3.00, 15.00],
            'claude-haiku-5-5' => ['Claude Haiku 5.5', 0.10, 0.50],
            'claude-haiku-4-5' => ['Claude Haiku 4.5', 1.00, 5.00],
        ],
        // aistudio.google.com/docs/pricing, same date, paid tier, prompts up
        // to 200K. ⚠️ Gemini 4's price doubles on 1 Dec 2026 — the Back
        // Office already marks these as a reference to confirm. Gemini 2.5
        // Pro and Flash are left out: deprecated, and rerouted on that date.
        'GEMINI' => [
            'gemini-4-carbon' => ['Gemini 4 Carbon', 2.00, 10.00],
            'gemini-4-argon' => ['Gemini 4 Argon', 2.00, 10.00],
            'gemini-3.8-flash' => ['Gemini 3.8 Flash', 0.75, 3.75],
            'gemini-3.6-flash' => ['Gemini 3.6 Flash', 0.75, 3.75],
            'gemini-3.5-flash-lite' => ['Gemini 3.5 Flash-Lite', 0.30, 2.50],
            'gemini-3.1-pro-preview' => ['Gemini 3.1 Pro (preview)', 2.00, 12.00],
            'gemini-3.1-flash-lite' => ['Gemini 3.1 Flash-Lite', 0.25, 1.50],
            'gemini-3-flash-preview' => ['Gemini 3 Flash (preview)', 0.50, 3.00],
            'gemini-2.5-flash-lite' => ['Gemini 2.5 Flash-Lite', 0.10, 0.40],
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

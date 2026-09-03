<?php

namespace App\Services\Credits;

use App\Models\AiModelPrice;
use App\Models\Setting;
use App\Services\AiTokens\KnownModelPrices;

/**
 * What the balance costs to fill and what spending it buys — every commercial
 * number of the offering, and where each one comes from.
 *
 * Both halves live here rather than in a wallet class and a run class, because
 * the Back Office edits them in one form and they are read from one settings
 * table. A boundary nothing enforces is a boundary that only shows up as two
 * imports.
 *
 * Every commercial number of the rental offering lives in the `settings` table
 * so the Back Office can move it without a deploy — the same pattern as the
 * MercadoPago and ProxyBR credentials — falling back to config/ai.php until an
 * admin has ever touched it. Prices are set by whoever runs the business, and a
 * price that needs a release is a price nobody adjusts.
 *
 * Everything reads through here, including the thresholds the tenant screen
 * shows: a floor enforced by the API and a different floor printed on the page
 * is a customer told one thing and refused for another.
 *
 * Nothing here reads a wallet or writes a row. It exists so the one piece of
 * arithmetic that decides how much money changes hands has a single home, and
 * so a test can state the price of a run in one line.
 */
class CreditPricing
{
    // ⚠️ The `ai_credits.` prefix stayed behind when the class was renamed, and
    // that is deliberate: these strings are not identifiers, they are the
    // primary keys of rows already sitting in the `settings` table. Renaming
    // them would silently reset every number an admin has set — the read would
    // miss and fall back to config — to fix a prefix no reader of the product
    // ever sees.
    public const KEY_MARKUP_PCT = 'ai_credits.markup_pct';
    public const KEY_USD_BRL_RATE = 'ai_credits.usd_brl_rate';
    public const KEY_FALLBACK_RUN_CENTS = 'ai_credits.fallback_run_cents';
    public const KEY_MIN_TOPUP_CENTS = 'ai_credits.min_topup_cents';
    public const KEY_LOW_BALANCE_CENTS = 'ai_credits.low_balance_cents';

    /**
     * The "typical reply" the price list is illustrated with: a page of context
     * in, a couple of sentences out. Round numbers on purpose — this is a
     * worked example, not a forecast, and precision here would only invite it
     * to be read as one.
     */
    private const EXAMPLE_INPUT_TOKENS = 2000;
    private const EXAMPLE_OUTPUT_TOKENS = 300;

    /** Markup on the provider's cost, in percent. */
    public static function markupPct(): float
    {
        return self::number(self::KEY_MARKUP_PCT, 'markup_pct', 40);
    }

    /** USD → BRL, fixed. See config/ai.php for why it is not a live feed. */
    public static function usdBrlRate(): float
    {
        $rate = self::number(self::KEY_USD_BRL_RATE, 'usd_brl_rate', 5.60);

        // A rate of zero would price every run at the fallback and look like the
        // hub had stopped reporting cost — a misconfiguration that hides as a
        // different problem is worse than one that refuses to apply.
        return $rate > 0 ? $rate : (float) config('ai.credits.usd_brl_rate', 5.60);
    }

    /** What a run costs when the hub reports no cost at all. */
    public static function fallbackRunCents(): int
    {
        return max(0, (int) self::number(self::KEY_FALLBACK_RUN_CENTS, 'fallback_run_cents', 5));
    }

    /** Smallest top-up we will issue a Pix for. */
    public static function minTopupCents(): int
    {
        return max(1, (int) self::number(self::KEY_MIN_TOPUP_CENTS, 'min_topup_cents', 1000));
    }

    /** Balance under which the workspace is warned it is about to lose its AI. */
    public static function lowBalanceCents(): int
    {
        return max(0, (int) self::number(self::KEY_LOW_BALANCE_CENTS, 'low_balance_cents', 500));
    }

    /**
     * The whole commercial block, resolved. Read by the Back Office editor and
     * by the tenant's own credits screen.
     *
     * @return array{markup_pct: float, usd_brl_rate: float, fallback_run_cents: int, min_topup_cents: int, low_balance_cents: int}
     */
    public static function settings(): array
    {
        return [
            'markup_pct' => self::markupPct(),
            'usd_brl_rate' => self::usdBrlRate(),
            'fallback_run_cents' => self::fallbackRunCents(),
            'min_topup_cents' => self::minTopupCents(),
            'low_balance_cents' => self::lowBalanceCents(),
        ];
    }

    /**
     * Store the commercial block. Only the keys present are written, so a
     * partial save leaves the rest alone.
     *
     * ⚠️ Changing these never rewrites what an old charge meant: every debit
     * copies the rate and markup it used onto its ledger row.
     *
     * @param  array<string, mixed>  $values  keyed by the short names in settings()
     */
    public static function store(array $values): void
    {
        $map = [
            'markup_pct' => self::KEY_MARKUP_PCT,
            'usd_brl_rate' => self::KEY_USD_BRL_RATE,
            'fallback_run_cents' => self::KEY_FALLBACK_RUN_CENTS,
            'min_topup_cents' => self::KEY_MIN_TOPUP_CENTS,
            'low_balance_cents' => self::KEY_LOW_BALANCE_CENTS,
        ];

        foreach ($map as $short => $key) {
            if (array_key_exists($short, $values) && $values[$short] !== null) {
                Setting::set($key, (string) $values[$short]);
            }
        }
    }

    /**
     * Price one run, in BRL cents.
     *
     * `$costUsd` is what the hub said the provider charged. Null means the hub
     * reported nothing — an older deployment, or a run whose stage does not
     * price itself — and those must not be free. The caller is expected to log
     * when it lands there.
     *
     * Rounded up, always. The alternative is that a very cheap run costs zero,
     * and a workspace can then hold an empty wallet and keep talking forever.
     *
     * `$provider` / `$model` select a per-model markup when one is configured.
     * Models are not equally worth reselling, and a single platform-wide
     * percentage either leaves nothing on a cheap model or overprices an
     * expensive one. Absent a row, the platform markup applies — which is every
     * model until an admin says otherwise.
     *
     * @return array{cents: int, cost_usd: float|null, rate: float, markup_pct: float, estimated: bool}
     */
    public static function priceRun(?float $costUsd, ?string $provider = null, ?string $model = null): array
    {
        $rate = self::usdBrlRate();
        $markup = self::markupFor($provider, $model);

        if ($costUsd === null || $costUsd <= 0) {
            return [
                'cents' => self::fallbackRunCents(),
                'cost_usd' => $costUsd,
                'rate' => $rate,
                'markup_pct' => $markup,
                'estimated' => true,
            ];
        }

        // Rounded to six places *before* the ceiling, and that is not cosmetic:
        // 0.02 × 5 × 1.5 is 0.15000000000000002 in binary floating point, and
        // ceiling that gives 16 cents instead of 15. Without this every single
        // price would quietly carry an extra cent it cannot justify.
        $brl = round($costUsd * $rate * (1 + $markup / 100), 6);

        return [
            'cents' => max(1, (int) ceil(round($brl * 100, 6))),
            'cost_usd' => $costUsd,
            'rate' => $rate,
            'markup_pct' => $markup,
            'estimated' => false,
        ];
    }

    /**
     * The markup that applies to one model: its own if it has one, otherwise
     * the platform's.
     */
    public static function markupFor(?string $provider, ?string $model): float
    {
        $override = AiModelPrice::forRun($provider, $model)?->markup_pct;

        return $override === null ? self::markupPct() : (float) $override;
    }

    /**
     * The customer-facing price list: every listed model, in BRL cents.
     *
     * Derived from the provider's published USD price, so it is an estimate and
     * says so — the actual charge is the cost of the run that really happened.
     * Models with no list price recorded are skipped rather than shown at zero:
     * a price list that quotes free is worse than one that is shorter.
     *
     * @return list<array{provider: string, model: string, label: ?string, input_cents_per_1m: int, output_cents_per_1m: int, example_reply_cents: int}>
     */
    public static function priceList(): array
    {
        $rate = self::usdBrlRate();
        $platformMarkup = self::markupPct();

        return AiModelPrice::query()
            ->listed()
            ->orderBy('sort_order')
            ->orderBy('provider')
            ->orderBy('model')
            ->get()
            ->filter(fn (AiModelPrice $price) => $price->input_usd_per_1m !== null && $price->output_usd_per_1m !== null)
            ->map(function (AiModelPrice $price) use ($rate, $platformMarkup) {
                $markup = $price->markup_pct === null ? $platformMarkup : (float) $price->markup_pct;
                $factor = $rate * (1 + $markup / 100);

                $inputPerM = (float) $price->input_usd_per_1m * $factor;
                $outputPerM = (float) $price->output_usd_per_1m * $factor;

                return [
                    'provider' => $price->provider,
                    'model' => $price->model,
                    'label' => $price->label,
                    'input_cents_per_1m' => (int) round($inputPerM * 100),
                    'output_cents_per_1m' => (int) round($outputPerM * 100),
                    // "Per million tokens" means nothing to somebody deciding
                    // between two models, so the list also carries one concrete
                    // reply priced out of it.
                    'example_reply_cents' => (int) max(1, ceil(
                        ($inputPerM * self::EXAMPLE_INPUT_TOKENS + $outputPerM * self::EXAMPLE_OUTPUT_TOKENS) / 1_000_000 * 100
                    )),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Every model a workspace may rent for the given providers, priced where a
     * price has been published.
     *
     * Wider than `priceList()` on purpose. Offering only the models an admin has
     * got round to pricing hid working options behind a Back Office table the
     * customer cannot see — and there was never a billing reason for it, because
     * an unpriced model is not unbilled: `priceRun()` charges it at the
     * provider's cost plus the platform markup, exactly like every other one.
     * What it lacks is a published per-million figure, and saying so is honest.
     *
     * Deliberately network-free — our own catalogue plus whatever has been
     * priced. This is read on every AI Agents page load, and a hub round-trip
     * there would buy marginal completeness at the cost of every visit.
     *
     * @param  list<string>  $providers
     * @return list<array{provider: string, model: string, label: ?string, input_cents_per_1m: ?int, output_cents_per_1m: ?int, example_reply_cents: ?int, priced: bool}>
     */
    public static function rentableModels(array $providers): array
    {
        $wanted = array_map('strtoupper', $providers);

        if ($wanted === []) {
            return [];
        }

        $priced = collect(self::priceList())
            ->keyBy(fn (array $row) => strtoupper($row['provider']) . '|' . strtolower($row['model']));

        $rate = self::usdBrlRate();
        $platformMarkup = self::markupPct();
        $rows = [];

        foreach (KnownModelPrices::all() as $model) {
            $provider = strtoupper($model['provider']);

            if (! in_array($provider, $wanted, true)) {
                continue;
            }

            // Priced from the catalogue's own USD figures at the platform rate
            // and markup — the same arithmetic priceList() does, on the numbers
            // this file has always carried. Returning nulls here was throwing
            // away a price we already knew: "no admin has typed a row for this
            // model yet" is not the same as "we cannot say what it costs", and
            // a picker that quotes nothing for half its options makes the half
            // it does quote look like the only ones that are billed.
            $factor = $rate * (1 + $platformMarkup / 100);
            $inputPerM = (float) $model['input'] * $factor;
            $outputPerM = (float) $model['output'] * $factor;

            $rows[$provider . '|' . strtolower($model['id'])] = [
                'provider' => $provider,
                'model' => $model['id'],
                'label' => $model['name'],
                'input_cents_per_1m' => (int) round($inputPerM * 100),
                'output_cents_per_1m' => (int) round($outputPerM * 100),
                'example_reply_cents' => (int) max(1, ceil(
                    ($inputPerM * self::EXAMPLE_INPUT_TOKENS + $outputPerM * self::EXAMPLE_OUTPUT_TOKENS) / 1_000_000 * 100
                )),
                // False still means "no admin has priced this": the Back Office
                // list and the margin override both key off it. What changed is
                // that an unpriced model now carries an estimate rather than a
                // blank.
                'priced' => false,
            ];
        }

        // Published prices win, and bring in anything priced by hand that our
        // catalogue has never heard of — a model added through the picker's
        // "other model" escape belongs on sale like any other.
        foreach ($priced as $key => $row) {
            if (! in_array(strtoupper($row['provider']), $wanted, true)) {
                continue;
            }

            $rows[$key] = $row + ['priced' => true];
        }

        return array_values($rows);
    }

    /**
     * A stored number, or the config default when nothing has been set.
     *
     * `is_numeric` rather than a null check: the settings table stores strings,
     * and a value someone cleared to "" must fall back rather than become zero.
     */
    private static function number(string $key, string $configKey, float $default): float
    {
        $setting = Setting::get($key);

        return is_numeric($setting)
            ? (float) $setting
            : (float) config("ai.credits.{$configKey}", $default);
    }
}

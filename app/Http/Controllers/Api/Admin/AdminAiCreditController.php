<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\AiCredit\CreditTransactionType;
use App\Http\Controllers\Controller;
use App\Models\AiCreditTransaction;
use App\Models\AiCreditWallet;
use App\Models\AiModelPrice;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Services\AiCredits\AiCreditPricing;
use App\Services\AiCredits\AiCreditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Back Office: prepaid AI balances, and the one lever support needs over them.
 *
 * The lever is the point of the page. Without a way to comp credit after an
 * outage — or to take back a debit that should never have been written — the
 * only remedy available to support is refunding a Pix, which returns the money
 * but not the service, and leaves the ledger claiming the workspace spent
 * something it did not.
 */
class AdminAiCreditController extends Controller
{
    private const PER_PAGE = 25;

    public function __construct(
        private readonly AiCreditService $credits,
    ) {}

    /**
     * Wallets, biggest balance first, with the period's movement beside each.
     *
     * A balance alone does not distinguish a workspace that deposited R$200
     * and has not started from one that deposits R$200 every week — and only
     * the second is a customer.
     */
    public function index(): JsonResponse
    {
        $wallets = AiCreditWallet::query()
            ->with('tenant.user:id,name,email')
            ->orderByDesc('balance_cents')
            ->paginate(self::PER_PAGE);

        $movement = AiCreditTransaction::query()
            ->whereIn('tenant_id', collect($wallets->items())->pluck('tenant_id'))
            ->where('created_at', '>=', now()->subDays(30))
            ->selectRaw('tenant_id,
                SUM(CASE WHEN amount_cents > 0 THEN amount_cents ELSE 0 END) as topped_up,
                SUM(CASE WHEN amount_cents < 0 THEN -amount_cents ELSE 0 END) as spent,
                SUM(CASE WHEN type = ? THEN 1 ELSE 0 END) as runs', [CreditTransactionType::Usage->value])
            ->groupBy('tenant_id')
            ->get()
            ->keyBy('tenant_id');

        return response()->json([
            'data' => collect($wallets->items())->map(fn (AiCreditWallet $wallet) => [
                'tenant_id' => $wallet->tenant_id,
                'name' => $wallet->tenant?->user?->name ?? "Tenant #{$wallet->tenant_id}",
                'email' => $wallet->tenant?->user?->email,
                'balance_cents' => $wallet->balance_cents,
                'currency' => $wallet->currency,
                'topped_up_cents_30d' => (int) ($movement[$wallet->tenant_id]->topped_up ?? 0),
                'spent_cents_30d' => (int) ($movement[$wallet->tenant_id]->spent ?? 0),
                'runs_30d' => (int) ($movement[$wallet->tenant_id]->runs ?? 0),
            ])->values(),
            'meta' => [
                'current_page' => $wallets->currentPage(),
                'last_page' => $wallets->lastPage(),
                'total' => $wallets->total(),
            ],
            'pricing' => AiCreditPricing::settings(),
        ]);
    }

    /**
     * Change the commercial numbers of the rental offering.
     *
     * These are prices, and prices are set by whoever runs the business — a
     * markup that needs a deploy is a markup nobody adjusts. Stored in the
     * `settings` table, the same DB-only pattern as the MercadoPago and ProxyBR
     * credentials.
     *
     * ⚠️ Changing them is not retroactive, and that is the point: every debit
     * copies the rate and markup it used onto its own ledger row, so a charge
     * from March still explains itself in July.
     *
     * `markup_pct` may be zero — running the offering at cost is a legitimate
     * decision (a promotion, a migration) — but the rate may not: a zero rate
     * would price every run at the fallback and read as if the hub had stopped
     * reporting cost, which is a misconfiguration disguised as a different
     * problem.
     */
    public function updatePricing(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'markup_pct' => ['required', 'numeric', 'min:0', 'max:1000'],
            'usd_brl_rate' => ['required', 'numeric', 'min:0.01', 'max:1000'],
            'fallback_run_cents' => ['required', 'integer', 'min:0', 'max:100000'],
            'min_topup_cents' => ['required', 'integer', 'min:1', 'max:10000000'],
            'low_balance_cents' => ['required', 'integer', 'min:0', 'max:10000000'],
        ]);

        $before = AiCreditPricing::settings();

        AiCreditPricing::store($validated);

        AuditLog::record(
            'ai_credit.pricing_updated',
            "Markup {$before['markup_pct']}% → {$validated['markup_pct']}%, rate {$before['usd_brl_rate']} → {$validated['usd_brl_rate']}",
            ['before' => $before, 'after' => AiCreditPricing::settings()],
            $request->user(),
        );

        return response()->json([
            'message' => 'Pricing updated',
            'pricing' => AiCreditPricing::settings(),
        ]);
    }

    /**
     * Per-model prices and margins.
     *
     * Returned raw (USD list price, markup or null) rather than as the computed
     * BRL figures the customer sees: this is the editor, and an editor that
     * shows the output instead of the input cannot be edited.
     */
    public function models(): JsonResponse
    {
        return response()->json([
            'data' => AiModelPrice::query()
                ->orderBy('sort_order')
                ->orderBy('provider')
                ->orderBy('model')
                ->get()
                ->map(fn (AiModelPrice $price) => [
                    'id' => $price->id,
                    'provider' => $price->provider,
                    'model' => $price->model,
                    'label' => $price->label,
                    'input_usd_per_1m' => $price->input_usd_per_1m === null ? null : (float) $price->input_usd_per_1m,
                    'output_usd_per_1m' => $price->output_usd_per_1m === null ? null : (float) $price->output_usd_per_1m,
                    'markup_pct' => $price->markup_pct === null ? null : (float) $price->markup_pct,
                    'is_listed' => $price->is_listed,
                    'sort_order' => $price->sort_order,
                ])->values(),
            // The list exactly as a customer sees it, so the effect of a margin
            // change is visible in the screen that made it rather than only in
            // the customer's.
            'preview' => AiCreditPricing::priceList(),
            'defaults' => AiCreditPricing::settings(),
        ]);
    }

    /**
     * Create or update one model's price row.
     *
     * Upsert on (provider, model) rather than a plain create: that pair is the
     * identity, and a second row for the same model would make "which markup
     * applies?" unanswerable.
     */
    public function upsertModel(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'provider' => ['required', 'string', 'max:50'],
            'model' => ['required', 'string', 'max:120'],
            'label' => ['nullable', 'string', 'max:120'],
            'input_usd_per_1m' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'output_usd_per_1m' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            // Null is meaningful: "use the platform markup", not "no margin".
            'markup_pct' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'is_listed' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:10000'],
        ]);

        $price = AiModelPrice::updateOrCreate(
            [
                'provider' => strtoupper($validated['provider']),
                'model' => $validated['model'],
            ],
            [
                'label' => $validated['label'] ?? null,
                'input_usd_per_1m' => $validated['input_usd_per_1m'] ?? null,
                'output_usd_per_1m' => $validated['output_usd_per_1m'] ?? null,
                'markup_pct' => $validated['markup_pct'] ?? null,
                'is_listed' => $validated['is_listed'] ?? true,
                'sort_order' => $validated['sort_order'] ?? 0,
            ],
        );

        AuditLog::record(
            'ai_credit.model_priced',
            "{$price->provider} {$price->model}: markup " . ($price->markup_pct ?? 'default'),
            $price->only(['provider', 'model', 'markup_pct', 'input_usd_per_1m', 'output_usd_per_1m', 'is_listed']),
            $request->user(),
        );

        return response()->json(['message' => 'Model price saved', 'id' => $price->id]);
    }

    /**
     * Remove a model's row. The model keeps working — it simply falls back to
     * the platform markup and stops appearing on the price list.
     */
    public function destroyModel(Request $request, AiModelPrice $model): JsonResponse
    {
        AuditLog::record(
            'ai_credit.model_price_removed',
            "{$model->provider} {$model->model}",
            $model->only(['provider', 'model']),
            $request->user(),
        );

        $model->delete();

        return response()->json(['message' => 'Model price removed']);
    }

    /**
     * One workspace's statement.
     *
     * Unlike the tenant's own view, this one carries the wholesale cost and the
     * rate each debit used: reconciling what was charged against the provider's
     * invoice is the reason the columns exist.
     */
    public function show(Tenant $tenant): JsonResponse
    {
        $wallet = $this->credits->wallet($tenant);

        $transactions = AiCreditTransaction::query()
            ->where('tenant_id', $tenant->id)
            ->orderByDesc('id')
            ->limit(200)
            ->get()
            ->map(fn (AiCreditTransaction $t) => [
                'id' => $t->id,
                'type' => $t->type->value,
                'amount_cents' => $t->amount_cents,
                'balance_after_cents' => $t->balance_after_cents,
                'description' => $t->description,
                'cost_usd' => $t->cost_usd,
                'usd_brl_rate' => $t->usd_brl_rate,
                'markup_pct' => $t->markup_pct,
                'estimated' => (bool) ($t->meta['estimated'] ?? false),
                'ai_hub_run_id' => $t->ai_hub_run_id,
                'invoice_id' => $t->invoice_id,
                'created_at' => $t->created_at,
            ]);

        return response()->json([
            'data' => [
                'tenant_id' => $tenant->id,
                'balance_cents' => $wallet->balance_cents,
                'currency' => $wallet->currency,
            ],
            'transactions' => $transactions,
        ]);
    }

    /**
     * Move a balance by hand, in either direction.
     *
     * A reason is required, not optional: an adjustment is the only row in the
     * ledger with no automatic explanation behind it, and one without a
     * sentence is a row nobody can defend three months later.
     */
    public function adjust(Request $request, Tenant $tenant): JsonResponse
    {
        $validated = $request->validate([
            'amount_cents' => ['required', 'integer', 'not_in:0', 'min:-10000000', 'max:10000000'],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $transaction = $this->credits->adjust(
            $tenant,
            (int) $validated['amount_cents'],
            $validated['reason'],
            ['admin_id' => $request->user()->id],
        );

        AuditLog::record(
            'ai_credit.adjusted',
            "Tenant #{$tenant->id}: " . $validated['amount_cents'] . ' cents — ' . $validated['reason'],
            [
                'tenant_id' => $tenant->id,
                'amount_cents' => (int) $validated['amount_cents'],
                'balance_after_cents' => $transaction?->balance_after_cents,
            ],
            $request->user(),
        );

        return response()->json([
            'message' => 'Balance adjusted',
            'balance_cents' => $transaction?->balance_after_cents ?? $this->credits->balanceCents($tenant),
        ]);
    }
}

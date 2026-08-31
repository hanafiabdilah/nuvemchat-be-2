<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\AiCredit\CreditTransactionType;
use App\Http\Controllers\Controller;
use App\Models\AiCreditTransaction;
use App\Models\AiCreditWallet;
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
    public function index(Request $request): JsonResponse
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
            'pricing' => [
                'markup_pct' => AiCreditPricing::markupPct(),
                'usd_brl_rate' => AiCreditPricing::usdBrlRate(),
            ],
        ]);
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

<?php

namespace App\Http\Controllers\Api\AiCredits;

use App\Http\Controllers\Controller;
use App\Http\Resources\AiCredit\AiCreditTransactionResource;
use App\Http\Resources\Billing\InvoiceResource;
use App\Models\AiCreditTransaction;
use App\Services\AiCredits\AiCreditPricing;
use App\Services\AiCredits\AiCreditService;
use App\Services\Billing\BillingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The prepaid AI balance: what is left, where it went, and how to add more.
 */
class AiCreditController extends Controller
{
    public function __construct(
        private readonly AiCreditService $credits,
        private readonly BillingService $billing,
    ) {}

    /**
     * Balance plus the recent statement.
     *
     * The statement is part of the same response rather than a second endpoint
     * because a balance with no history is a number a customer cannot check,
     * and "why did my credit drop" is the only question this page exists to
     * answer.
     */
    public function index(Request $request): JsonResponse
    {
        $tenant = $request->user()->tenant;
        $wallet = $this->credits->wallet($tenant);

        $transactions = AiCreditTransaction::query()
            ->where('tenant_id', $tenant->id)
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        return response()->json([
            'data' => [
                'balance_cents' => $wallet->balance_cents,
                'currency' => $wallet->currency,
                'low_balance_cents' => (int) config('ai.credits.low_balance_cents', 500),
                'min_topup_cents' => (int) config('ai.credits.min_topup_cents', 1000),
                // Published so the page can say what a run roughly costs before
                // anyone has spent anything. Withholding it would make the
                // first top-up a purchase with no stated price.
                'markup_pct' => AiCreditPricing::markupPct(),
                'usd_brl_rate' => AiCreditPricing::usdBrlRate(),
            ],
            'transactions' => AiCreditTransactionResource::collection($transactions),
        ]);
    }

    /**
     * Buy credit. Pix only — see BillingService::createAiCreditTopupPixInvoice.
     *
     * The floor is enforced here rather than in the service so the customer
     * gets a field error on the amount they typed, not an exception.
     */
    public function topup(Request $request): JsonResponse
    {
        $min = (int) config('ai.credits.min_topup_cents', 1000);

        $validated = $request->validate([
            'amount_cents' => ['required', 'integer', "min:{$min}", 'max:10000000'],
            'payer_email' => ['nullable', 'email'],
        ]);

        $tenant = $request->user()->tenant;

        $invoice = $this->billing->createAiCreditTopupPixInvoice(
            $tenant,
            (int) $validated['amount_cents'],
            $validated['payer_email'] ?? $request->user()->email,
        );

        return response()->json([
            'message' => 'Charge created',
            'data' => new InvoiceResource($invoice),
        ], 201);
    }
}

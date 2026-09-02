<?php

namespace App\Http\Controllers\Api\Credits;

use App\Http\Controllers\Controller;
use App\Http\Resources\Credit\CreditTransactionResource;
use App\Http\Resources\Billing\InvoiceResource;
use App\Models\CreditTransaction;
use App\Services\Credits\CreditPricing;
use App\Services\Credits\CreditService;
use App\Services\Billing\BillingService;
use App\Services\Connection\Apiway\ApiwayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The prepaid balance: what is left, where it went, and how to add more.
 */
class CreditController extends Controller
{
    public function __construct(
        private readonly CreditService $credits,
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

        $transactions = CreditTransaction::query()
            ->where('tenant_id', $tenant->id)
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        return response()->json([
            'data' => [
                'balance_cents' => $wallet->balance_cents,
                'currency' => $wallet->currency,
                // Straight from CreditPricing, never from config: the floor
                // printed on the page has to be the floor the API enforces, or
                // a customer is told one number and refused for another. The
                // markup and rate are published for the same reason — a first
                // top-up with no stated price is a purchase in the dark.
            ] + CreditPricing::settings(),
            // What each model costs, in the currency the balance is held in.
            // Shipped with the balance rather than behind its own endpoint
            // because "how long will R$50 last me" is the question the page
            // exists to answer, and neither half answers it alone.
            'models' => CreditPricing::priceList(),
            'transactions' => CreditTransactionResource::collection($transactions),
        ]);
    }

    /**
     * What the workspace needs to be told about its balance, if anything.
     *
     * Its own endpoint rather than a field on the statement, because it is read
     * on every page load by the banner and must stay cheap. Deliberately
     * separate from `index()`, which is heavy (a hundred statement rows and the
     * whole model price list) and only wanted on one screen.
     *
     * Two things, in order of consequence. A renewal the balance cannot cover
     * is first because it has a deadline and no undo — ProxyBR revokes the
     * number on the day. A low balance is only a nudge.
     */
    public function alerts(Request $request, ApiwayService $apiway): JsonResponse
    {
        $tenant = $request->user()->tenant;
        $balance = $this->credits->balanceCents($tenant);

        $atRisk = $apiway->renewalsAtRisk($tenant);

        return response()->json([
            'data' => [
                'balance_cents' => $balance,
                'currency' => 'BRL',
                // Only meaningful once there has been money to run low on: a
                // workspace that has never topped up is not "running out".
                'low_balance' => $balance > 0 && $balance < CreditPricing::lowBalanceCents(),
                'renewals_at_risk' => [
                    'count' => $atRisk->count(),
                    'instances' => (int) $atRisk->sum('quantity'),
                    // The soonest deadline and what it would take to clear the
                    // whole window — the two numbers a banner has to state.
                    'next_expires_at' => $atRisk->first()?->expires_at?->toIso8601String(),
                    'shortfall_cents' => max(0, (int) $atRisk->sum('total_price_cents') - $balance),
                ],
            ],
        ]);
    }

    /**
     * Buy credit. Pix only — see BillingService::createCreditTopupPixInvoice.
     *
     * The floor is enforced here rather than in the service so the customer
     * gets a field error on the amount they typed, not an exception.
     */
    public function topup(Request $request): JsonResponse
    {
        $min = CreditPricing::minTopupCents();

        $validated = $request->validate([
            'amount_cents' => ['required', 'integer', "min:{$min}", 'max:10000000'],
            'payer_email' => ['nullable', 'email'],
        ]);

        $tenant = $request->user()->tenant;

        $invoice = $this->billing->createCreditTopupPixInvoice(
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

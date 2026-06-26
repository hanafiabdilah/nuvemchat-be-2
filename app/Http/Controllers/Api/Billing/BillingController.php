<?php

namespace App\Http\Controllers\Api\Billing;

use App\Enums\Billing\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Http\Resources\Billing\InvoiceResource;
use App\Http\Resources\Billing\PlanResource;
use App\Http\Resources\Billing\SubscriptionResource;
use App\Models\Invoice;
use App\Models\Plan;
use App\Services\Billing\BillingService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BillingController extends Controller
{
    public function __construct(
        protected BillingService $billing,
    ) {}

    /**
     * Public Bricks config (just the publishable key).
     */
    public function config()
    {
        return response()->json([
            'public_key' => config('services.mercadopago.public_key'),
        ]);
    }

    public function plans()
    {
        $plans = Plan::active()->public()->orderBy('sort_order')->get();

        return response()->json(['data' => PlanResource::collection($plans)]);
    }

    public function subscription(Request $request)
    {
        $subscription = $this->tenant($request)->currentSubscription?->loadMissing('plan');

        return response()->json([
            'data' => $subscription ? new SubscriptionResource($subscription) : null,
        ]);
    }

    public function invoices(Request $request)
    {
        $invoices = Invoice::where('tenant_id', $request->user()->tenant_id)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        return response()->json(['data' => InvoiceResource::collection($invoices)]);
    }

    /**
     * Subscribe to a plan via card (recurring) or pix.
     */
    public function subscribe(Request $request)
    {
        $validated = $request->validate([
            'plan_id' => ['required', 'exists:plans,id'],
            'method' => ['required', Rule::enum(PaymentMethod::class)],
            'card_token_id' => ['required_if:method,card', 'string'],
            'payer_email' => ['required', 'email'],
        ]);

        $plan = Plan::active()->findOrFail($validated['plan_id']);
        $method = PaymentMethod::from($validated['method']);

        $subscription = $this->billing->subscribe(
            $this->tenant($request),
            $plan,
            $method,
            [
                'card_token_id' => $validated['card_token_id'] ?? null,
                'payer_email' => $validated['payer_email'],
            ],
        );

        return response()->json([
            'data' => new SubscriptionResource($subscription->loadMissing('plan')),
            // For pix, the frontend needs the freshly-created charge to render the QR.
            'invoice' => $method === PaymentMethod::Pix
                ? new InvoiceResource($subscription->invoices()->latest()->first())
                : null,
        ], 201);
    }

    /**
     * Regenerate the Pix QR for the current pending invoice.
     */
    public function refreshPix(Request $request)
    {
        $subscription = $this->tenant($request)->currentSubscription;
        abort_if($subscription === null, 404, 'No subscription');

        $invoice = $this->billing->createPixInvoice($subscription, $request->user()->email);

        return response()->json(['data' => new InvoiceResource($invoice)]);
    }

    public function invoiceStatus(Request $request, Invoice $invoice)
    {
        abort_if($invoice->tenant_id !== $request->user()->tenant_id, 403);

        return response()->json(['data' => new InvoiceResource($invoice)]);
    }

    public function cancel(Request $request)
    {
        $subscription = $this->tenant($request)->currentSubscription;
        abort_if($subscription === null, 404, 'No subscription');

        $this->billing->cancel($subscription);

        return response()->json(['data' => new SubscriptionResource($subscription->fresh()->loadMissing('plan'))]);
    }

    private function tenant(Request $request)
    {
        return $request->user()->tenant;
    }
}

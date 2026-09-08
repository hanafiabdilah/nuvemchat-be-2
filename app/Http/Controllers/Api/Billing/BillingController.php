<?php

namespace App\Http\Controllers\Api\Billing;

use App\Enums\Billing\InvoiceStatus;
use App\Enums\Billing\PaymentMethod;
use App\Exceptions\Billing\PaymentAlreadySettledException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Billing\InvoiceResource;
use App\Http\Resources\Billing\PlanResource;
use App\Http\Resources\Billing\SubscriptionResource;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Tenant;
use App\Services\Billing\BillingService;
use App\Services\Billing\PaymentService\PaymentServiceClient;
use App\Support\Errors\HasUserSafeMessage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class BillingController extends Controller
{
    public function __construct(
        protected BillingService $billing,
        protected PaymentServiceClient $payments,
    ) {}

    /**
     * What can be charged right now, straight from the payment service.
     *
     * Replaces the old "here is the gateway's public key" endpoint. A checkout
     * that hard-codes its buttons shows someone an option that fails on the day
     * a provider is suspended or a method is turned off; asking makes that a
     * non-event.
     *
     * ⚠️ `merchant_initiated_cards` is the one field the checkout must not
     * ignore. It says whether a stored card can be charged with nobody at the
     * screen — which is the only thing that makes "renova automaticamente"
     * true. Without a provider that can do it, the card tile is hidden rather
     * than sold and then discovered to be manual at the second cycle.
     *
     * Cached for a minute: cheap enough to ask on every render, and the whole
     * point is that the answer changes.
     */
    public function paymentMethods()
    {
        try {
            $methods = Cache::remember(
                'billing:payment-methods:BRL',
                now()->addMinute(),
                fn () => $this->payments->paymentMethods('BRL'),
            );
        } catch (\Throwable $e) {
            Log::warning('Could not load payment methods', ['error' => $e->getMessage()]);

            // An empty list is the honest answer and the safe one: the checkout
            // renders "no methods available" instead of a button that fails.
            $methods = [];
        }

        $card = collect($methods)->firstWhere('method', 'card');

        return response()->json([
            'data' => $methods,
            'card_auto_renew' => (bool) ($card['merchant_initiated_cards'] ?? false),
        ]);
    }

    /**
     * Open a card tokenisation session.
     *
     * The browser never sends a card here; it sends it straight to the gateway
     * named by `sdk`, using `public_key`, and hands back a single-use token.
     * `provider` comes back so the charge that follows runs against the same
     * gateway that minted the token — a token belongs to one and is meaningless
     * to another.
     */
    public function cardSession(Request $request)
    {
        $tenant = $this->tenant($request);

        $session = $this->payments->instrumentSession([
            'customer_reference' => $tenant->paymentCustomerReference(),
            'type' => 'card_token',
        ]);

        return response()->json([
            'data' => [
                'sdk' => $session['session']['sdk'] ?? null,
                'public_key' => $session['session']['public_key'] ?? null,
                'provider' => $session['provider']['name'] ?? null,
            ],
        ]);
    }

    /**
     * The billing identity a charge cannot happen without.
     *
     * Read and written separately from the checkout because it outlives it: a
     * renewal runs from a scheduler with nobody at a screen, so the CPF or CNPJ
     * has to be a stored fact rather than a form field.
     */
    public function billingProfile(Request $request)
    {
        $tenant = $this->tenant($request);

        return response()->json(['data' => $this->profilePayload($tenant)]);
    }

    public function updateBillingProfile(Request $request)
    {
        $validated = $request->validate([
            'billing_name' => ['required', 'string', 'max:191'],
            'billing_document_type' => ['required', Rule::in(['CPF', 'CNPJ'])],
            // Length only, and punctuation stripped below. The check digits are
            // the acquirer's business — rejecting a valid edge case ourselves
            // would be worse than passing it on.
            'billing_document_number' => ['required', 'string', 'max:32'],
        ]);

        $digits = preg_replace('/\D/', '', $validated['billing_document_number']);
        $expected = $validated['billing_document_type'] === 'CNPJ' ? 14 : 11;

        if (strlen($digits) !== $expected) {
            return response()->json([
                'message' => "Um {$validated['billing_document_type']} tem {$expected} dígitos.",
                'errors' => ['billing_document_number' => ["Um {$validated['billing_document_type']} tem {$expected} dígitos."]],
            ], 422);
        }

        $tenant = $this->tenant($request);
        $tenant->update([
            'billing_name' => $validated['billing_name'],
            'billing_document_type' => $validated['billing_document_type'],
            'billing_document_number' => $digits,
        ]);

        return response()->json(['data' => $this->profilePayload($tenant->fresh())]);
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
            'data' => $subscription ? (new SubscriptionResource($subscription))->withUsage() : null,
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
            'card_token' => ['required_if:method,card', 'string'],
            // Which gateway minted the token, from the card session. Not the
            // customer's choice and not the operator's — the token only works
            // against the one that issued it.
            'provider' => ['nullable', 'string', 'max:32'],
            'payer_email' => ['required', 'email'],
        ]);

        $plan = Plan::active()->findOrFail($validated['plan_id']);
        $method = PaymentMethod::from($validated['method']);

        try {
            $subscription = $this->billing->subscribe(
                $this->tenant($request),
                $plan,
                $method,
                [
                    'card_token' => $validated['card_token'] ?? null,
                    'provider' => $validated['provider'] ?? null,
                    'payer_email' => $validated['payer_email'],
                ],
            );
        } catch (\Throwable $e) {
            // Anything already carrying wording of ours — a translated upstream
            // refusal, a missing CPF — reaches the customer untouched. Rewriting
            // it here would be a silent downgrade, and nothing would record it.
            if ($e instanceof HasUserSafeMessage) {
                throw $e;
            }

            Log::error('Billing subscribe failed', ['error' => $e->getMessage(), 'plan_id' => $plan->id]);

            return response()->json([
                'message' => 'Falha ao processar a assinatura. Tente novamente.',
            ], 500);
        }

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

        try {
            $this->billing->cancel($subscription);
        } catch (PaymentAlreadySettledException) {
            return $this->paymentSettledResponse($request);
        }

        // An unpaid checkout is torn down and detached, leaving the tenant with no
        // plan at all — reflect that instead of echoing the dangling row back.
        $current = $this->tenant($request)->fresh()->currentSubscription;

        return response()->json([
            'data' => $current ? new SubscriptionResource($current->loadMissing('plan')) : null,
        ]);
    }

    /**
     * Abandon an unpaid checkout (typically a pix QR that was never settled) so
     * the tenant is free to pick a different plan.
     */
    public function cancelPending(Request $request)
    {
        $subscription = $this->tenant($request)->currentSubscription;
        abort_if($subscription === null, 404, 'No subscription');

        // A live plan is cancelled at period end, never voided outright.
        abort_if(
            $subscription->status->isUsable(),
            422,
            'Esta assinatura já está ativa. Use o cancelamento da assinatura.',
        );

        try {
            $this->billing->cancelPendingCheckout($subscription);
        } catch (PaymentAlreadySettledException) {
            return $this->paymentSettledResponse($request);
        }

        return response()->json(['data' => null]);
    }

    /**
     * Cancel a single open charge.
     *
     * ⚠️ Local only — the payment service has no way to kill a pending Pix, so
     * the code stays payable until it expires. If it is paid anyway the webhook
     * honours it, which is the right outcome: the money arrived.
     */
    public function cancelInvoice(Request $request, Invoice $invoice)
    {
        abort_if($invoice->tenant_id !== $request->user()->tenant_id, 403);
        abort_if($invoice->status !== InvoiceStatus::Pending, 422, 'Esta fatura não está mais pendente.');

        $invoice = $this->billing->cancelInvoice($invoice);

        if ($invoice->status === InvoiceStatus::Paid) {
            return $this->paymentSettledResponse($request);
        }

        return response()->json(['data' => new InvoiceResource($invoice)]);
    }

    /**
     * The cancel lost the race against the payment: report it as a conflict and
     * hand back the (now active) subscription so the UI can settle on the truth.
     */
    private function paymentSettledResponse(Request $request)
    {
        $current = $this->tenant($request)->fresh()->currentSubscription;

        return response()->json([
            'message' => 'O pagamento já foi confirmado. A assinatura permanece ativa.',
            'code' => 'payment_already_settled',
            'data' => $current ? new SubscriptionResource($current->loadMissing('plan')) : null,
        ], 409);
    }

    /**
     * The document is echoed back masked. It is a legal identifier the customer
     * already knows, so showing enough to recognise which one is on file is the
     * point; printing it in full into every page load is not.
     */
    private function profilePayload(Tenant $tenant): array
    {
        $number = (string) $tenant->billing_document_number;

        return [
            'billing_name' => $tenant->billing_name,
            'billing_document_type' => $tenant->billing_document_type,
            'billing_document_hint' => $number === '' ? null : '•••'.substr($number, -4),
            'is_complete' => $tenant->hasBillingIdentity(),
        ];
    }

    private function tenant(Request $request): Tenant
    {
        return $request->user()->tenant;
    }
}

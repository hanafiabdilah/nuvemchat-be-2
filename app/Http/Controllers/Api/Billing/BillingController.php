<?php

namespace App\Http\Controllers\Api\Billing;

use App\Enums\Billing\InvoiceStatus;
use App\Enums\Billing\PaymentMethod;
use App\Enums\Connection\Channel;
use App\Exceptions\Billing\PaymentAlreadySettledException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Billing\InvoiceResource;
use App\Http\Resources\Billing\PlanResource;
use App\Http\Resources\Billing\SubscriptionResource;
use App\Models\Invoice;
use App\Models\Plan;
use App\Services\Billing\BillingService;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
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
            'public_key' => \App\Services\Billing\MercadoPago\MercadoPagoConfig::publicKey(),
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
            'card_token_id' => ['required_if:method,card', 'string'],
            'payer_email' => ['required', 'email'],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $plan = Plan::active()->findOrFail($validated['plan_id']);
        $method = PaymentMethod::from($validated['method']);

        try {
            $subscription = $this->billing->subscribe(
                $this->tenant($request),
                $plan,
                $method,
                [
                    'card_token_id' => $validated['card_token_id'] ?? null,
                    'payer_email' => $validated['payer_email'],
                    'quantity' => $validated['quantity'] ?? 1,
                ],
            );
        } catch (RequestException $e) {
            // MercadoPago rejected the request (bad payload, amount below minimum, card
            // token from a different account, subscriptions not enabled, etc.). Surface
            // the provider's reason instead of a blind 500 so it's actionable.
            $body = $e->response?->json();
            Log::error('Billing subscribe: MercadoPago rejected the request', [
                'status' => $e->response?->status(),
                'body' => $e->response?->body(),
                'plan_id' => $plan->id,
            ]);

            return response()->json([
                'message' => 'Não foi possível processar o pagamento no provedor: '
                    . ($body['message'] ?? $body['error'] ?? 'erro desconhecido'),
                'provider_error' => $body,
            ], 422);
        } catch (\Throwable $e) {
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

    public function changeQuantity(Request $request)
    {
        $validated = $request->validate([
            'quantity' => ['required', 'integer', 'min:1', 'max:100'],
        ]);

        $tenant = $this->tenant($request);
        $subscription = $tenant->currentSubscription?->loadMissing('plan');
        abort_if($subscription === null, 404, 'No subscription');
        abort_if(! $subscription->plan?->quantity_enabled, 422, 'Este plano não permite alteração de quantidade.');

        $instancesCount = $tenant->connections()
            ->where('channel', Channel::WhatsappApiway->value)
            ->count();

        if ($validated['quantity'] < $instancesCount) {
            return response()->json([
                'message' => "Você tem {$instancesCount} instâncias ativas; cancele algumas antes de reduzir.",
            ], 422);
        }

        try {
            $subscription = $this->billing->changeQuantity($subscription, $validated['quantity']);
        } catch (\RuntimeException) {
            return response()->json([
                'message' => 'Não foi possível atualizar a assinatura no provedor de pagamento.',
            ], 502);
        }

        return response()->json([
            'data' => new SubscriptionResource($subscription->fresh()->loadMissing('plan')),
        ]);
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
     * Cancel a single open charge (kills the pix QR at the provider).
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

    private function tenant(Request $request)
    {
        return $request->user()->tenant;
    }
}

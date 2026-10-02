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
use App\Services\Billing\Fiscal\FiscalInvoiceService;
use App\Services\Billing\PlanChange;
use App\Services\Billing\SavedCardService;
use App\Services\Market\MarketDocuments;
use App\Support\Errors\HasUserSafeMessage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class BillingController extends Controller
{
    public function __construct(
        protected BillingService $billing,
    ) {}

    /**
     * What can be charged right now, straight from whichever gateway bills
     * this workspace (the payment service, or — with PAYMENT_METHOD=direct —
     * Mercado Pago in Brazil and dLocal Go elsewhere).
     *
     * A checkout that hard-codes its buttons shows someone an option that
     * fails on the day a provider is suspended or a method is turned off;
     * asking makes that a non-event. `offered` is the answer the checkout
     * renders: no per-plan checkbox decides it any more.
     *
     * ⚠️ `merchant_initiated_cards` is the one field the checkout must not
     * ignore. It says whether a stored card can be charged with nobody at the
     * screen — which is the only thing that makes "renova automaticamente"
     * true. Without it, the card tile is hidden rather than sold and then
     * discovered to be manual at the second cycle.
     */
    public function paymentMethods(Request $request)
    {
        $tenant = $this->tenant($request);

        try {
            $methods = $this->billing->paymentMethodsFor($tenant);
        } catch (\Throwable $e) {
            Log::warning('Could not load payment methods', ['error' => $e->getMessage()]);

            // An empty list is the honest answer and the safe one: the checkout
            // renders "no methods available" instead of a button that fails.
            $methods = [];
        }

        $card = collect($methods)->firstWhere('method', 'card');

        return response()->json([
            'data' => $methods,
            'offered' => $this->billing->offeredFrom($tenant, $methods),
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
        return response()->json([
            'data' => $this->billing->cardSession($this->tenant($request)),
        ]);
    }

    /**
     * Open the gateway payment a card form is bound to (dLocal Go SmartFields).
     *
     * Only for a card session that answered `requires_checkout`: the form
     * cannot be drawn without the token this returns. Nothing is charged and
     * nothing is written locally — see BillingService::openCardCheckout().
     */
    public function cardCheckout(Request $request)
    {
        $validated = $request->validate([
            'plan_id' => ['required', 'exists:plans,id'],
            'payer_email' => ['nullable', 'email'],
        ]);

        $plan = Plan::active()->public()->findOrFail($validated['plan_id']);

        return response()->json([
            'data' => $this->billing->openCardCheckout(
                $this->tenant($request),
                $plan,
                $validated['payer_email'] ?? $request->user()->email,
            ),
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
        $tenant = $this->tenant($request);
        $market = $tenant->market_code;

        $needsDocument = MarketDocuments::required($market);

        $validated = $request->validate([
            'billing_name' => ['required', 'string', 'max:191'],
            // Whatever this country's rails ask for — CPF/CNPJ in Brazil,
            // NPWP/NIK in Indonesia, nothing at all where an admin said so.
            // Hard-coding the Brazilian pair did not read as a Brazilian
            // assumption; it read as an Indonesian workspace unable to save a
            // billing profile, and so unable to pay at all.
            //
            // ⚠️ The rules disappear rather than relax when no document is
            // asked for: `Rule::in([])` matches nothing, so leaving them in
            // place would make such a market impossible to save.
            ...($needsDocument ? [
                'billing_document_type' => ['required', Rule::in(MarketDocuments::codes($market))],
                // Length only, and punctuation stripped below. The check digits
                // are the acquirer's business — rejecting a valid edge case
                // ourselves would be worse than passing it on.
                'billing_document_number' => ['required', 'string', 'max:32'],
            ] : []),
        ]);

        if ($needsDocument) {
            $problem = MarketDocuments::problem(
                $market,
                $validated['billing_document_type'],
                $validated['billing_document_number'],
            );

            if ($problem !== null) {
                return response()->json([
                    'message' => $problem,
                    'errors' => ['billing_document_number' => [$problem]],
                ], 422);
            }
        }

        $tenant->update([
            'billing_name' => $validated['billing_name'],
            'billing_document_type' => $needsDocument ? $validated['billing_document_type'] : null,
            'billing_document_number' => $needsDocument
                ? preg_replace('/\D/', '', $validated['billing_document_number'])
                : null,
        ]);

        return response()->json(['data' => $this->profilePayload($tenant->fresh())]);
    }

    /**
     * The tomador's address on the notas fiscais Pingly issues (Brazil only).
     *
     * Its own endpoint rather than a field of the billing profile: that form
     * asks for the CPF/CNPJ again on every save, and correcting a street
     * number is no reason to retype a tax document. Optional as a whole —
     * most prefeituras accept a nota without one — and all-or-nothing: an
     * incomplete address is never sent (see FiscalInvoiceService::address()).
     */
    public function updateBillingAddress(Request $request)
    {
        $tenant = $this->tenant($request);

        abort_unless($tenant->market_code === FiscalInvoiceService::MARKET, 404);

        $validated = $request->validate([
            'billing_address' => ['present', 'nullable', 'array'],
            'billing_address.cep' => ['required_with:billing_address.logradouro', 'nullable', 'string', 'max:9'],
            'billing_address.logradouro' => ['nullable', 'string', 'max:125'],
            'billing_address.numero' => ['nullable', 'string', 'max:10'],
            'billing_address.complemento' => ['nullable', 'string', 'max:60'],
            'billing_address.bairro' => ['nullable', 'string', 'max:60'],
            'billing_address.cidade' => ['nullable', 'string', 'max:60'],
            'billing_address.codigo_cidade' => ['nullable', 'string', 'max:7'],
            'billing_address.estado' => ['nullable', 'string', 'size:2'],
        ]);

        $address = array_filter(array_map(
            fn ($value) => is_string($value) ? trim($value) : $value,
            (array) ($validated['billing_address'] ?? []),
        ), fn ($value) => filled($value));

        if (isset($address['estado'])) {
            $address['estado'] = strtoupper($address['estado']);
        }

        $tenant->update(['billing_address' => $address ?: null]);

        return response()->json(['data' => $this->profilePayload($tenant->fresh())]);
    }

    /**
     * The plans on sale in this workspace's country, at that country's prices.
     *
     * A plan with no price for the market is not listed: it is not sold there,
     * and showing it at the platform's own price would quote a number nobody
     * set for this country.
     */
    public function plans(Request $request)
    {
        $market = $this->tenant($request)->market_code;

        $plans = Plan::active()->public()
            ->soldIn($market)
            ->with('marketPrices')
            ->orderBy('sort_order')
            ->get()
            ->map(fn (Plan $plan) => $plan->applyMarketPrice($market));

        return response()->json(['data' => PlanResource::collection($plans)]);
    }

    public function subscription(Request $request)
    {
        $tenant = $this->tenant($request);
        $subscription = $tenant->currentSubscription?->loadMissing('plan');

        // The nested plan carries the platform's home price until it is told
        // otherwise — the catalog above already does this, and a workspace that
        // reached "Your plan" from a manual grant was reading R$ off a plan row
        // while its own subscription was in rupiah. In-memory only.
        $subscription?->plan?->applyMarketPrice($tenant->market_code);

        $pending = $this->billing->pendingChangeFor($tenant);
        $pendingInvoice = $pending?->invoices()->where('status', InvoiceStatus::Pending->value)->latest('id')->first();

        return response()->json([
            'data' => $subscription ? (new SubscriptionResource($subscription))->withUsage() : null,
            // An upgrade started and not yet paid. The plan above keeps working
            // until it is; this is what lets the page offer to pay or drop it.
            'pending_change' => $pending ? [
                'subscription_id' => $pending->id,
                'plan' => $pending->plan ? ['id' => $pending->plan->id, 'name' => $pending->plan->name] : null,
                'price_cents' => $pending->price_cents,
                'currency' => $pending->currency,
                'invoice' => $pendingInvoice ? new InvoiceResource($pendingInvoice) : null,
            ] : null,
        ]);
    }

    /**
     * What switching to a plan costs and when it takes effect — the checkout
     * shows exactly this, and subscribe/schedule charge exactly this.
     */
    public function planChangeQuote(Request $request)
    {
        $validated = $request->validate([
            'plan_id' => ['required', 'exists:plans,id'],
            'method' => ['nullable', Rule::enum(PaymentMethod::class)],
        ]);

        $plan = Plan::active()->public()->findOrFail($validated['plan_id']);
        $method = filled($validated['method'] ?? null) ? PaymentMethod::from($validated['method']) : null;

        return response()->json([
            'data' => app(PlanChange::class)->quote($this->tenant($request), $plan, $method),
        ]);
    }

    /** Move to a cheaper plan when the period already paid for ends. */
    public function schedulePlanChange(Request $request)
    {
        $validated = $request->validate([
            'plan_id' => ['required', 'exists:plans,id'],
        ]);

        $plan = Plan::active()->public()->findOrFail($validated['plan_id']);
        $tenant = $this->tenant($request);

        try {
            $subscription = $this->billing->schedulePlanChange($tenant, $plan);
        } catch (\Throwable $e) {
            if ($e instanceof HasUserSafeMessage || $e instanceof \Illuminate\Validation\ValidationException) {
                throw $e;
            }

            Log::error('Billing schedule plan change failed', ['error' => $e->getMessage(), 'plan_id' => $plan->id]);

            return response()->json([
                'message' => 'Não foi possível agendar a troca de plano. Tente novamente.',
            ], 500);
        }

        return response()->json(['data' => new SubscriptionResource($subscription->loadMissing('plan'))]);
    }

    /** Keep the current plan: drop the scheduled downgrade. */
    public function cancelScheduledPlanChange(Request $request)
    {
        try {
            $subscription = $this->billing->cancelScheduledChange($this->tenant($request));
        } catch (\Throwable $e) {
            if ($e instanceof HasUserSafeMessage) {
                throw $e;
            }

            Log::error('Billing cancel scheduled plan change failed', ['error' => $e->getMessage()]);

            return response()->json([
                'message' => 'Não foi possível manter o plano atual. Tente novamente.',
            ], 500);
        }

        return response()->json([
            'data' => $subscription ? new SubscriptionResource($subscription->loadMissing('plan')) : null,
        ]);
    }

    public function invoices(Request $request)
    {
        $invoices = Invoice::where('tenant_id', $request->user()->tenant_id)
            ->with('fiscalInvoice')
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
            // The payment the card form was bound to, for a gateway whose form
            // needs one (dLocal Go SmartFields). Absent everywhere else.
            'checkout_token' => ['nullable', 'string', 'max:128'],
            // A kept card (Mercado Pago): the token was minted from its id and
            // the CVV. `discard_card_on_failure` is set for a card added for
            // this very charge — refused, it leaves the list again.
            'saved_card_id' => ['nullable', 'integer'],
            'discard_card_on_failure' => ['sometimes', 'boolean'],
            // A second single-use token from the same saved card + CVV, for a
            // gateway that authorises the renewals after the first payment
            // (Mercado Pago). `card_token` pays; this one becomes the mandate.
            'mandate_token' => ['nullable', 'string', 'max:255'],
            'payer_email' => ['required', 'email'],
        ]);

        // ⚠️ `->public()` as well, matching plans() above. Without it the
        // catalogue filtered and the checkout did not, so a tenant could
        // count plan ids and subscribe to one deliberately kept off the
        // shelf — an internal, legacy or partner plan, which is exactly the
        // kind with better quotas or a lower price.
        $plan = Plan::active()->public()->findOrFail($validated['plan_id']);
        $method = PaymentMethod::from($validated['method']);
        $savedCard = filled($validated['saved_card_id'] ?? null) && $method === PaymentMethod::Card
            ? app(SavedCardService::class)->find($this->tenant($request), (int) $validated['saved_card_id'])
            : null;

        try {
            $subscription = $this->billing->subscribe(
                $this->tenant($request),
                $plan,
                $method,
                [
                    'card_token' => $validated['card_token'] ?? null,
                    'mandate_token' => $validated['mandate_token'] ?? null,
                    'provider' => $validated['provider'] ?? null,
                    'checkout_token' => $validated['checkout_token'] ?? null,
                    'saved_card' => $savedCard,
                    'discard_card_on_failure' => (bool) ($validated['discard_card_on_failure'] ?? false),
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

        $invoice = $subscription->invoices()->latest('id')->first();

        return response()->json([
            'data' => new SubscriptionResource($subscription->loadMissing('plan')),
            // For pix / a hosted checkout, the frontend needs the freshly-created
            // charge to render the QR or open the gateway's page — and for a
            // card, the charge's outcome: refused, or waiting on 3-D Secure.
            'invoice' => $invoice ? new InvoiceResource($invoice) : null,
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
     * Undo a scheduled cancellation. `cancel()` never told the provider
     * anything (there is no preapproval to revoke), so undoing it is just as
     * local: clear the flag before `billing:charge-renewals`/`pix-generate`
     * read it and skip the subscription for good.
     */
    public function resume(Request $request)
    {
        $subscription = $this->tenant($request)->currentSubscription;
        abort_if($subscription === null, 404, 'No subscription');

        abort_if(
            ! $subscription->cancel_at_period_end,
            422,
            'Esta assinatura não está agendada para cancelamento.',
        );

        // The deadline already having passed means the period is over and
        // access lapsed regardless of the flag — resuming would silently
        // resurrect a subscription the tenant no longer has, instead of the
        // "keep what you already have" the button promises.
        abort_if(
            ! $subscription->isUsable(),
            422,
            'O período atual desta assinatura já terminou.',
        );

        $subscription = $this->billing->resume($subscription);

        return response()->json(['data' => new SubscriptionResource($subscription->loadMissing('plan'))]);
    }

    /**
     * Abandon an unpaid checkout (typically a pix QR that was never settled) so
     * the tenant is free to pick a different plan.
     */
    public function cancelPending(Request $request)
    {
        $tenant = $this->tenant($request);

        // An unpaid upgrade first: it is the checkout the customer is looking
        // at, while the plan it would replace is live and must be left alone.
        if ($pending = $this->billing->pendingChangeFor($tenant)) {
            try {
                $this->billing->cancelPendingCheckout($pending);
            } catch (PaymentAlreadySettledException) {
                return $this->paymentSettledResponse($request);
            }

            return response()->json(['data' => null]);
        }

        $subscription = $tenant->currentSubscription;
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
            // What this country accepts, so the form offers those and not a
            // list of Brazilian documents its customer does not hold. Empty
            // means this country asks for none — the form then has one field,
            // and the flag says so outright rather than leaving the dashboard
            // to infer it from an empty array.
            'document_types' => MarketDocuments::forMarket($tenant->market_code),
            'document_required' => MarketDocuments::required($tenant->market_code),
            // The nota fiscal's tomador address — offered only where a nota is
            // issued at all.
            'address_supported' => $tenant->market_code === FiscalInvoiceService::MARKET,
            'billing_address' => $tenant->market_code === FiscalInvoiceService::MARKET ? $tenant->billing_address : null,
        ];
    }

    private function tenant(Request $request): Tenant
    {
        return $request->user()->tenant;
    }
}

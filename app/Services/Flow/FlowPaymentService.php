<?php

namespace App\Services\Flow;

use App\Enums\Flow\FlowPaymentStatus;
use App\Enums\Integration\IntegrationCategory;
use App\Events\FlowPaymentUpdated;
use App\Exceptions\UpstreamServiceException;
use App\Models\Contact;
use App\Models\FlowNode;
use App\Models\FlowPayment;
use App\Models\FlowState;
use App\Models\Integration;
use App\Services\Contact\ContactIdentity;
use App\Services\Conversation\SystemMessage;
use App\Services\Integrations\IntegrationDrivers;
use App\Services\Integrations\Payments\ChargeRequest;
use App\Support\Errors\UpstreamError;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The life of a charge a flow issued: created, then settled exactly once.
 *
 * Three things can learn that a charge ended — the gateway's webhook, the
 * expiry job, and the polling sweep — and any two can arrive together. They all
 * call settle(), which moves a row out of `pending` under a row lock, so only
 * the first one to get there resumes the flow. The rest find nothing to do.
 *
 * None of them trusts what it was told. Each reads the charge back from the
 * gateway with the workspace's own key (refresh()), which is what makes an
 * unauthenticated webhook safe to act on.
 */
class FlowPaymentService
{
    /**
     * How long past its deadline a charge we cannot get an answer about stays
     * pending. The gateways refuse payment after their own expiry, so past this
     * "unpaid" is what is true — and a flow parked forever on a gateway outage
     * is the one outcome worse than taking the failed branch.
     */
    private const UNVERIFIED_GRACE_MINUTES = 30;

    /**
     * Issue the charge a payment node describes.
     *
     * Always returns a row: `pending` when the gateway accepted it, `failed`
     * with our own sentence when anything — the node, the integration, the
     * gateway — refused. The attempt is recorded either way, because "the bot
     * tried to charge Maria and could not" is exactly what the Integrations
     * page exists to show.
     *
     * @param  callable(string): string  $interpolate
     */
    public function createForNode(FlowState $flowState, FlowNode $node, callable $interpolate): FlowPayment
    {
        $conversation = $flowState->conversation;
        $connection = $conversation->connection;
        $tenantId = (int) $connection->tenant_id;
        $contact = $conversation->contact;
        $data = $node->data ?? [];

        $integrationId = (int) ($data['integration_id'] ?? 0);
        $integration = $integrationId > 0
            ? Integration::forTenant($tenantId)->inCategory(IntegrationCategory::Payment)->find($integrationId)
            : null;

        $method = PaymentNodes::method($data);
        $rawAmount = trim($interpolate((string) ($data['amount'] ?? '')));
        $amountCents = PaymentNodes::parseAmount($rawAmount);
        $description = Str::limit(trim($interpolate((string) ($data['description'] ?? ''))), 140, '');
        $expiresAt = CarbonImmutable::now()->addMinutes(PaymentNodes::expiresInMinutes($data));

        $payment = new FlowPayment([
            'tenant_id' => $tenantId,
            'integration_id' => $integration?->id,
            'provider' => $integration?->provider,
            'conversation_id' => $conversation->id,
            'contact_id' => $contact?->id,
            'flow_id' => $flowState->flow_id,
            'flow_state_id' => $flowState->id,
            'flow_node_id' => $node->id,
            'reference' => 'pingly-fp-'.Str::lower((string) Str::ulid()),
            'method' => $method,
            'amount_cents' => $amountCents ?? 0,
            'currency' => 'BRL',
            'description' => $description !== '' ? $description : null,
            'status' => FlowPaymentStatus::Pending,
            'expires_at' => $expiresAt,
        ]);

        $refusal = match (true) {
            $integration === null => 'A integração de pagamento deste nó não existe mais. Escolha outra em Configurações → Integrações e salve o fluxo.',
            ! $integration->enabled => "A integração \"{$integration->name}\" está desativada.",
            $amountCents === null => 'O valor da cobrança não é válido'.($rawAmount !== '' ? ": \"{$rawAmount}\"." : ' (ficou vazio).'),
            ! in_array($method, $integration->provider->paymentMethods(), true) => "{$integration->provider->label()} não oferece este tipo de cobrança.",
            default => null,
        };

        if ($refusal !== null) {
            return $this->markFailed($payment, $refusal);
        }

        // Saved before the network call so the reference is ours before the
        // gateway ever sees it — a create that times out after the gateway
        // accepted it still leaves a row the sweep can reconcile.
        $payment->save();

        $document = trim($interpolate((string) ($data['payer_document'] ?? '')));

        $charge = new ChargeRequest(
            reference: $payment->reference,
            method: $method,
            amountCents: $amountCents,
            currency: 'BRL',
            description: $description !== '' ? $description : 'Pagamento',
            expiresAt: $expiresAt,
            payerName: ContactIdentity::name($contact),
            payerEmail: $this->payerEmail($data, $interpolate, $contact),
            payerDocument: $document !== '' ? $document : null,
            notificationUrl: $integration->webhookUrl(),
        );

        try {
            $result = IntegrationDrivers::payment($integration)->createCharge($charge);
        } catch (UpstreamServiceException $e) {
            $integration->recordError($e->getMessage());

            return $this->markFailed($payment, $e->getMessage());
        } catch (\Throwable $e) {
            $message = UpstreamError::messageFrom($integration->provider->upstream(), $e, [
                'integration_id' => $integration->id,
                'flow_payment_id' => $payment->id,
            ]);
            $integration->recordError($message);

            return $this->markFailed($payment, $message);
        }

        if ($result->pixCode === null && $result->paymentUrl === null) {
            return $this->markFailed($payment, 'O provedor aceitou a cobrança, mas não devolveu nem Pix nem link de pagamento.');
        }

        $payment->forceFill([
            'provider_payment_id' => $result->providerPaymentId !== '' ? $result->providerPaymentId : null,
            'status' => $result->status,
            'pix_code' => $result->pixCode,
            'payment_url' => $result->paymentUrl,
            'expires_at' => $result->expiresAt,
            'settled_at' => $result->status->isFinal() ? now() : null,
        ])->save();

        $integration->recordUse();

        return $payment;
    }

    /**
     * Ask the gateway where a pending charge stands, and settle it if it ended.
     *
     * Throws when the gateway cannot be asked — the webhook turns that into a
     * 500 (so the provider retries), the job into a retry, the sweep into a
     * warning and another try next minute.
     */
    public function refresh(FlowPayment $payment): FlowPayment
    {
        if (! $payment->isPending()) {
            return $payment;
        }

        $integration = $payment->integration;

        if ($integration === null) {
            // Deleted while a charge was open. Nothing can be asked any more;
            // the deadline is all that is left to go on.
            if ($payment->expires_at && $payment->expires_at->isPast()) {
                $this->settle($payment, FlowPaymentStatus::Expired, null, 'integration_deleted');
            }

            return $payment->fresh();
        }

        try {
            $status = IntegrationDrivers::payment($integration)->fetchStatus($payment);
        } finally {
            FlowPayment::whereKey($payment->id)->update(['last_checked_at' => now()]);
        }

        if ($status->status->isFinal()) {
            $this->settle($payment, $status->status, $status->paidAt, $status->providerStatus);
        }

        return $payment->fresh();
    }

    /**
     * The deadline passed. Asked one last time before calling it unpaid, so a
     * payment made in the final second — or confirmed by a webhook that never
     * arrived — still takes the paid branch.
     */
    public function expire(FlowPayment $payment): void
    {
        if (! $payment->isPending() || ($payment->expires_at && $payment->expires_at->isFuture())) {
            return;
        }

        try {
            $payment = $this->refresh($payment);
        } catch (\Throwable $e) {
            $overdueMinutes = $payment->expires_at ? (int) $payment->expires_at->diffInMinutes(now()) : PHP_INT_MAX;

            if ($overdueMinutes < self::UNVERIFIED_GRACE_MINUTES) {
                throw $e;
            }

            Log::warning('FlowPaymentService: gateway unreachable past the grace window, calling the charge unpaid', [
                'flow_payment_id' => $payment->id,
                'error' => $e->getMessage(),
            ]);
        }

        if ($payment->isPending()) {
            $this->settle($payment, FlowPaymentStatus::Expired, null, 'expired');
        }
    }

    /**
     * Move a charge out of `pending` — once — and resume the flow waiting on it.
     *
     * Returns whether anything changed. A paid notification for a charge we had
     * already called expired is recorded (the money is real) but never replays
     * the flow: it has already gone down the failed branch, and sending the
     * "thank you" path an hour later would contradict what the customer was told.
     */
    public function settle(
        FlowPayment $payment,
        FlowPaymentStatus $status,
        ?CarbonInterface $paidAt = null,
        ?string $providerStatus = null,
    ): bool {
        if (! $status->isFinal()) {
            return false;
        }

        $outcome = DB::transaction(function () use ($payment, $status, $paidAt, $providerStatus) {
            $locked = FlowPayment::whereKey($payment->id)->lockForUpdate()->first();

            if ($locked === null) {
                return null;
            }

            $meta = array_filter(array_merge($locked->meta ?? [], ['provider_status' => $providerStatus]), fn ($v) => $v !== null);

            if ($locked->status === FlowPaymentStatus::Pending) {
                $locked->forceFill([
                    'status' => $status,
                    'paid_at' => $status === FlowPaymentStatus::Paid ? ($paidAt ?? now()) : null,
                    'settled_at' => now(),
                    'failure_reason' => match ($status) {
                        FlowPaymentStatus::Expired => 'O prazo para pagamento terminou.',
                        FlowPaymentStatus::Failed => $locked->failure_reason ?? 'O pagamento foi recusado ou cancelado.',
                        default => null,
                    },
                    'meta' => $meta,
                ])->save();

                return 'settled';
            }

            if ($status === FlowPaymentStatus::Paid && $locked->status === FlowPaymentStatus::Expired && $locked->paid_at === null) {
                $locked->forceFill([
                    'paid_at' => $paidAt ?? now(),
                    'meta' => array_merge($meta, ['paid_late' => true]),
                ])->save();

                return 'paid_late';
            }

            return null;
        });

        if ($outcome === null) {
            return false;
        }

        $payment->refresh();

        if ($outcome === 'paid_late') {
            $this->note($payment, PaymentNodes::INFO_PAID_LATE);

            return true;
        }

        $this->note($payment, match ($payment->status) {
            FlowPaymentStatus::Paid => PaymentNodes::INFO_PAID,
            FlowPaymentStatus::Expired => PaymentNodes::INFO_EXPIRED,
            default => PaymentNodes::INFO_FAILED,
        });

        (new FlowExecutor)->resumeFromPayment($payment);

        return true;
    }

    /**
     * Tell the thread and every open dashboard what happened to a charge.
     *
     * The note is the agents' view of it — it sits in the timeline between the
     * Pix the bot sent and whatever the bot says next, which is the only place
     * someone reading the conversation later will look. The broadcast is for
     * the dashboards open right now.
     */
    public function note(FlowPayment $payment, string $code): void
    {
        $conversation = $payment->conversation;
        $amount = $payment->formattedAmount();
        $provider = $payment->provider?->label() ?? '—';

        if ($conversation !== null) {
            $body = match ($code) {
                PaymentNodes::INFO_CREATED => "Payment requested: {$amount} via {$provider}.",
                PaymentNodes::INFO_PAID => "Payment received: {$amount} via {$provider}.",
                PaymentNodes::INFO_EXPIRED => "The payment of {$amount} expired without being paid.",
                PaymentNodes::INFO_PAID_LATE => "The payment of {$amount} arrived after it had expired. The flow had already moved on.",
                default => "The payment of {$amount} could not be completed: {$payment->failure_reason}",
            };

            SystemMessage::info($conversation, $body, $code, array_filter([
                'amount_cents' => $payment->amount_cents,
                'currency' => $payment->currency,
                'provider' => $payment->provider?->value,
                'reason' => $code === PaymentNodes::INFO_FAILED ? $payment->failure_reason : null,
            ], fn ($value) => $value !== null));
        }

        try {
            broadcast(new FlowPaymentUpdated($payment));
        } catch (\Throwable $e) {
            Log::warning('FlowPaymentService: could not broadcast the payment update', [
                'flow_payment_id' => $payment->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function markFailed(FlowPayment $payment, string $reason): FlowPayment
    {
        $payment->forceFill([
            'status' => FlowPaymentStatus::Failed,
            'failure_reason' => Str::limit($reason, 480),
            'settled_at' => now(),
        ])->save();

        Log::info('FlowPaymentService: charge not created', [
            'flow_payment_id' => $payment->id,
            'integration_id' => $payment->integration_id,
            'reason' => $reason,
        ]);

        return $payment;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  callable(string): string  $interpolate
     */
    private function payerEmail(array $data, callable $interpolate, ?Contact $contact): ?string
    {
        $typed = strtolower(trim($interpolate((string) ($data['payer_email'] ?? ''))));

        if ($typed !== '' && filter_var($typed, FILTER_VALIDATE_EMAIL)) {
            return $typed;
        }

        return ContactIdentity::for($contact)['email'] ?? null;
    }
}

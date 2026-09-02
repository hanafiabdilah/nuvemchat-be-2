<?php

namespace App\Http\Controllers\Api\Apiway;

use App\Enums\Apiway\ApiwaySubscriptionSource;
use App\Enums\Billing\InvoicePurpose;
use App\Enums\Billing\InvoiceStatus;
use App\Exceptions\ApiwayPartnerException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Apiway\ApiwaySubscriptionResource;
use App\Http\Resources\Billing\InvoiceResource;
use App\Models\ApiwaySubscription;
use App\Services\Connection\Apiway\ApiwayService;
use Illuminate\Http\Request;

class ApiwaySubscriptionController extends Controller
{
    public function __construct(
        private readonly ApiwayService $apiway,
    ) {}

    /**
     * Manual (early) renewal: re-quote at ProxyBR and charge the balance now.
     *
     * Worth keeping as a button even though apiway:renew does this on its own,
     * because the scheduled charge only starts three days out — someone going on
     * holiday, or who has just topped up after a warning, wants to settle it and
     * stop thinking about it.
     */
    public function renew(Request $request, int $subscription)
    {
        $row = $this->findSubscription($request, $subscription);

        abort_if($row->source !== ApiwaySubscriptionSource::Unit, 422, 'Instâncias incluídas no plano são renovadas automaticamente.');
        abort_if($row->status->isTerminal() || ! $row->provider_subscription_id, 422, 'Esta assinatura não pode mais ser renovada. Contrate uma nova instância.');

        // Legacy: a Pix renewal from before the balance, still open. Hand it back
        // rather than charging as well — the customer may be about to pay it.
        $openRenewal = $row->invoices()
            ->where('purpose', InvoicePurpose::ApiwayRenewal->value)
            ->where('status', InvoiceStatus::Pending->value)
            ->latest('id')
            ->first();

        if ($openRenewal) {
            return response()->json(['invoice' => new InvoiceResource($openRenewal)]);
        }

        try {
            $paid = $this->apiway->renewFromBalance($row);
        } catch (ApiwayPartnerException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => $e->getErrorCode() ?? 'apiway_unavailable',
            ], in_array($e->getHttpStatus(), [400, 422], true) ? 422 : 502);
        }

        $row = $row->fresh();

        if (! $paid) {
            return response()->json([
                'message' => 'Seu saldo não cobre esta renovação. Recarregue para continuar.',
                'code' => 'insufficient_credit',
                'required_cents' => $row->total_price_cents,
            ], 422);
        }

        return response()->json(['data' => new ApiwaySubscriptionResource($row->load('instances'))], 202);
    }

    /**
     * Abandon an UNPAID purchase: voids the Pix charge and deletes the local
     * row, so a closed checkout never leaves a pending card behind. 409 when
     * the payment settled meanwhile (the purchase then proceeds normally).
     */
    public function abandon(Request $request, int $subscription)
    {
        $row = $this->findSubscription($request, $subscription);

        if (! $this->apiway->abandonPendingPurchase($row)) {
            return response()->json([
                'message' => 'O pagamento já foi confirmado — a instância será provisionada.',
                'data' => new ApiwaySubscriptionResource($row->fresh()->load('instances')),
            ], 409);
        }

        return response()->json(['deleted' => true]);
    }

    /**
     * Cancel = permanent revoke at ProxyBR. Requires typed confirmation.
     */
    public function cancel(Request $request, int $subscription)
    {
        $request->validate(['confirm' => ['required', 'in:CANCELAR']]);

        $row = $this->findSubscription($request, $subscription);

        try {
            $row = $this->apiway->cancel($row);
        } catch (ApiwayPartnerException $e) {
            return response()->json([
                'message' => 'Não foi possível cancelar no provedor. Tente novamente.',
                'code' => $e->getErrorCode() ?? 'apiway_unavailable',
            ], 502);
        }

        return response()->json(['data' => new ApiwaySubscriptionResource($row->load('instances'))]);
    }

    private function findSubscription(Request $request, int $id): ApiwaySubscription
    {
        return $request->user()->tenant->apiwaySubscriptions()->findOrFail($id);
    }
}

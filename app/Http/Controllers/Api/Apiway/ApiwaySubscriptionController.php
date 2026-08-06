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
use App\Services\Billing\BillingService;
use App\Services\Connection\Apiway\ApiwayPartnerClient;
use App\Services\Connection\Apiway\ApiwayService;
use Illuminate\Http\Request;

class ApiwaySubscriptionController extends Controller
{
    public function __construct(
        private readonly ApiwayService $apiway,
        private readonly ApiwayPartnerClient $partner,
        private readonly BillingService $billing,
    ) {}

    /**
     * Manual (early) renewal: re-quote at ProxyBR, price the row, emit a Pix
     * invoice. Paying it triggers the partner renew via the payment webhook.
     */
    public function renewInvoice(Request $request, int $subscription)
    {
        $row = $this->findSubscription($request, $subscription);

        abort_if($row->source !== ApiwaySubscriptionSource::Unit, 422, 'Instâncias incluídas no plano são renovadas automaticamente.');
        abort_if($row->status->isTerminal() || ! $row->provider_subscription_id, 422, 'Esta assinatura não pode mais ser renovada. Contrate uma nova instância.');

        $openRenewal = $row->invoices()
            ->where('purpose', InvoicePurpose::ApiwayRenewal->value)
            ->where('status', InvoiceStatus::Pending->value)
            ->latest('id')
            ->first();

        if ($openRenewal) {
            return response()->json(['data' => new InvoiceResource($openRenewal)]);
        }

        try {
            // Renewal price follows the CURRENT catalog (ProxyBR re-quotes at
            // renew time as well) — refresh the row before charging.
            $quote = $this->partner->quote($row->quantity, $row->location_code, $row->cycle);
            $row->update([
                'unit_price_cents' => (int) round(((float) ($quote['unit_price'] ?? 0)) * 100),
                'total_price_cents' => (int) round(((float) ($quote['total_price'] ?? 0)) * 100),
            ]);

            $invoice = $this->billing->createApiwayPixInvoice(
                $row->fresh(),
                InvoicePurpose::ApiwayRenewal,
                $request->user()->email,
            );
        } catch (ApiwayPartnerException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => $e->getErrorCode() ?? 'apiway_unavailable',
            ], in_array($e->getHttpStatus(), [400, 422], true) ? 422 : 502);
        }

        return response()->json(['data' => new InvoiceResource($invoice)], 201);
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

<?php

namespace App\Http\Controllers\Api\Apiway;

use App\Enums\Apiway\ApiwaySubscriptionStatus;
use App\Enums\Billing\PaymentMethod;
use App\Exceptions\ApiwayPartnerException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Apiway\ApiwayInstanceResource;
use App\Http\Resources\Apiway\ApiwaySubscriptionResource;
use App\Http\Resources\Billing\InvoiceResource;
use App\Models\ApiwayInstance;
use App\Models\AuditLog;
use App\Services\Connection\Apiway\ApiwayPartnerClient;
use App\Services\Connection\Apiway\ApiwayService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ApiwayInstanceController extends Controller
{
    public function __construct(
        private readonly ApiwayService $apiway,
        private readonly ApiwayPartnerClient $partner,
    ) {}

    /**
     * The tenant's purchased instances (linked or in the available pool).
     */
    public function index(Request $request)
    {
        $tenant = $request->user()->tenant;

        if ($request->boolean('refresh')) {
            try {
                $this->apiway->syncStatuses($tenant);
            } catch (\Throwable $e) {
                Log::warning('Apiway refresh failed', ['tenant_id' => $tenant->id, 'error' => $e->getMessage()]);
            }
        }

        $instances = $tenant->apiwayInstances()
            ->with(['subscription', 'connection'])
            ->orderByDesc('id')
            ->get();

        // Purchases that have no instances yet (paying / provisioning / failed)
        // still need to show up so the tenant sees what they're waiting for.
        $pendingSubscriptions = $tenant->apiwaySubscriptions()
            ->whereIn('status', [
                ApiwaySubscriptionStatus::PendingPayment->value,
                ApiwaySubscriptionStatus::Provisioning->value,
                ApiwaySubscriptionStatus::Failed->value,
            ])
            ->doesntHave('instances')
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'data' => ApiwayInstanceResource::collection($instances),
            'pending_subscriptions' => ApiwaySubscriptionResource::collection($pendingSubscriptions),
            'usage' => $this->apiway->usageSummary($tenant),
        ]);
    }

    /**
     * Create an instance: either from the plan's included allotment (free) or
     * as a unit purchase (Pix invoice / card preapproval) at catalog price.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'mode' => ['required', 'in:included,unit'],
            'quantity' => ['required_if:mode,unit', 'integer', 'min:1', 'max:100'],
            'cycle' => ['required_if:mode,unit', 'in:mensal,anual'],
            'location_code' => ['required_if:mode,unit', 'string', 'max:20'],
            'method' => ['required_if:mode,unit', 'in:pix,card'],
            'card_token_id' => ['required_if:method,card', 'nullable', 'string'],
            'payer_email' => ['nullable', 'email'],
        ]);

        $tenant = $request->user()->tenant;

        try {
            if ($validated['mode'] === 'included') {
                $subscription = $this->apiway->createIncludedInstance($tenant);

                return response()->json([
                    'data' => new ApiwaySubscriptionResource($subscription->load('instances')),
                ], $subscription->status === ApiwaySubscriptionStatus::Active ? 201 : 202);
            }

            $result = $this->apiway->startUnitPurchase(
                $tenant,
                $validated['quantity'],
                $validated['cycle'],
                $validated['location_code'],
                PaymentMethod::from($validated['method']),
                $validated['card_token_id'] ?? null,
                $validated['payer_email'] ?? $request->user()->email,
            );
        } catch (ApiwayPartnerException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => $e->getErrorCode() ?? 'apiway_unavailable',
            ], in_array($e->getHttpStatus(), [400, 422], true) ? 422 : 502);
        }

        return response()->json([
            'data' => new ApiwaySubscriptionResource($result['subscription']->load('instances')),
            'invoice' => $result['invoice'] ? new InvoiceResource($result['invoice']) : null,
            'authorized' => $result['authorized'],
        ], 201);
    }

    /**
     * Pairing QR for an instance that is NOT linked to a connection yet
     * (linked ones pair through the connection connect/check-status flow).
     */
    public function qr(Request $request, int $instance)
    {
        $instance = $this->findInstance($request, $instance);

        try {
            // Micro-cache: the SPA polls every few seconds and the partner
            // throttle (120/min) is shared by the entire platform.
            $data = Cache::remember(
                "apiway:qr:{$instance->provider_instance_id}",
                3,
                fn () => $this->partner->instanceQr($instance->provider_instance_id),
            );
        } catch (ApiwayPartnerException $e) {
            return $this->partnerError($e);
        }

        $qr = $data['qr_code'] ?? null;

        return response()->json([
            'data' => [
                'status' => $data['status'] ?? null,
                'connected' => $data['connected'] ?? false,
                'logged_in' => $data['logged_in'] ?? false,
                'qr_code' => $qr && ! str_starts_with($qr, 'data:') ? 'data:image/png;base64,'.$qr : $qr,
                'qr_pending_reason' => $data['qr_pending_reason'] ?? null,
            ],
        ]);
    }

    public function status(Request $request, int $instance)
    {
        $instance = $this->findInstance($request, $instance);

        try {
            $data = Cache::remember(
                "apiway:status:{$instance->provider_instance_id}",
                3,
                fn () => $this->partner->instanceStatus($instance->provider_instance_id),
            );
        } catch (ApiwayPartnerException $e) {
            return $this->partnerError($e);
        }

        $instance->update(['status' => $data['status'] ?? $instance->status]);

        return response()->json(['data' => $data]);
    }

    /**
     * Reveal the instance API token (used by the tenant's own integrations
     * against the public core). Audited.
     */
    public function revealToken(Request $request, int $instance)
    {
        $instance = $this->findInstance($request, $instance);

        try {
            $data = $this->partner->instanceToken($instance->provider_instance_id);
        } catch (ApiwayPartnerException $e) {
            return $this->partnerError($e);
        }

        AuditLog::record('apiway.token.reveal', "Revealed API Way instance token #{$instance->id}");

        return response()->json([
            'data' => [
                'token' => $data['token'] ?? null,
                'masked' => $data['masked'] ?? null,
            ],
        ]);
    }

    private function findInstance(Request $request, int $id): ApiwayInstance
    {
        return $request->user()->tenant->apiwayInstances()->findOrFail($id);
    }

    private function partnerError(ApiwayPartnerException $e)
    {
        return response()->json([
            'message' => $e->getMessage(),
            'code' => $e->getErrorCode() ?? 'apiway_unavailable',
        ], in_array($e->getHttpStatus(), [400, 404, 422], true) ? $e->getHttpStatus() : 502);
    }
}

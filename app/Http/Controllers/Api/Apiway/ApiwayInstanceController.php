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
use App\Services\Connection\Apiway\ApiwayService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ApiwayInstanceController extends Controller
{
    public function __construct(
        private readonly ApiwayService $apiway,
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

        // Purchases that have no instances yet (provisioning / failed) still
        // show up so the tenant sees what they're waiting for. pending_payment
        // rows are deliberately hidden: an abandoned checkout is deleted (see
        // abandon()), never surfaced as a lingering card.
        $pendingSubscriptions = $tenant->apiwaySubscriptions()
            ->whereIn('status', [
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
            'location_code' => ['required_if:mode,unit', 'nullable', 'string', 'max:20'],
            'method' => ['required_if:mode,unit', 'in:pix,card'],
            'card_token_id' => ['required_if:method,card', 'nullable', 'string'],
            'payer_email' => ['nullable', 'email'],
        ]);

        $tenant = $request->user()->tenant;

        try {
            if ($validated['mode'] === 'included') {
                $subscription = $this->apiway->createIncludedInstance($tenant, $validated['location_code'] ?? null);

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
     * Reveal the instance API token (used by the tenant's own integrations
     * against the public core). Stored locally after the first partner fetch.
     * Audited.
     */
    public function revealToken(Request $request, int $instance)
    {
        $instance = $this->findInstance($request, $instance);

        try {
            $token = $this->apiway->instanceCoreToken($instance);
        } catch (ApiwayPartnerException $e) {
            return $this->partnerError($e);
        }

        AuditLog::record('apiway.token.reveal', "Revealed API Way instance token #{$instance->id}");

        return response()->json([
            'data' => [
                'token' => $token,
                'masked' => substr($token, 0, 10).str_repeat('*', 18).substr($token, -4),
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

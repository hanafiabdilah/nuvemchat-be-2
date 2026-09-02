<?php

namespace App\Http\Controllers\Api\Apiway;

use App\Enums\Apiway\ApiwaySubscriptionStatus;
use App\Exceptions\ApiwayPartnerException;
use App\Exceptions\Billing\InsufficientCreditException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Apiway\ApiwayInstanceResource;
use App\Http\Resources\Apiway\ApiwaySubscriptionResource;
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
     * Create an instance: either from the plan's included allotment (free) or as
     * a unit purchase at catalog price, charged to the prepaid balance.
     *
     * No payment method to choose any more — the balance is the payment method,
     * and there is no pending state to return: by the time this responds the
     * charge has settled and provisioning has either finished or been queued.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'mode' => ['required', 'in:included,unit'],
            // Optional on the included path — it defaults to one, and the real
            // ceiling is the plan's remaining allowance, which only the service
            // can see.
            'quantity' => ['required_if:mode,unit', 'integer', 'min:1', 'max:100'],
            'cycle' => ['required_if:mode,unit', 'in:mensal,anual'],
            'location_code' => ['required_if:mode,unit', 'nullable', 'string', 'max:20'],
        ]);

        $tenant = $request->user()->tenant;

        try {
            $subscription = $validated['mode'] === 'included'
                ? $this->apiway->createIncludedInstance(
                    $tenant,
                    $validated['location_code'] ?? null,
                    (int) ($validated['quantity'] ?? 1),
                )
                : $this->apiway->purchaseUnits(
                    $tenant,
                    $validated['quantity'],
                    $validated['cycle'],
                    $validated['location_code'],
                );
        } catch (InsufficientCreditException $e) {
            // 422 with the numbers, not a bare "insufficient balance": the page
            // that made this request has to tell the customer how much to add,
            // and it should not have to subtract two figures to find out.
            return response()->json([
                'message' => 'Seu saldo não cobre esta compra. Recarregue para continuar.',
                'code' => 'insufficient_credit',
                'balance_cents' => $e->balanceCents,
                'required_cents' => $e->requiredCents,
                'shortfall_cents' => $e->shortfallCents(),
            ], 422);
        } catch (ApiwayPartnerException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => $e->getErrorCode() ?? 'apiway_unavailable',
            ], in_array($e->getHttpStatus(), [400, 422], true) ? 422 : 502);
        }

        return response()->json([
            'data' => new ApiwaySubscriptionResource($subscription->load('instances')),
        ], $subscription->status === ApiwaySubscriptionStatus::Active ? 201 : 202);
    }

    /**
     * Rename an instance.
     *
     * The name ProxyBR provisions with ("Instancia 01", and the same for
     * everyone) is a label from their side, not a choice anybody here made — so
     * a pool of unlinked instances read as identical rows. This overwrites it
     * locally only: the partner API has no rename, and the name matters to the
     * person picking one out of a list, not to ProxyBR.
     *
     * Once an instance is linked, the connection's name is what the lists show;
     * this is what identifies it before then.
     */
    public function rename(Request $request, int $instance)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
        ]);

        $row = $this->findInstance($request, $instance);
        $row->update(['name' => trim($validated['name'])]);

        return response()->json(['data' => new ApiwayInstanceResource($row->fresh()->load(['subscription', 'connection']))]);
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

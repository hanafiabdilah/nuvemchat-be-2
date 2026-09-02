<?php

namespace App\Http\Controllers\Api\TrainedAgent;

use App\Enums\TrainedAgent\HireStatus;
use App\Exceptions\Billing\InsufficientCreditException;
use App\Http\Controllers\Controller;
use App\Http\Resources\TrainedAgent\TrainedAgentCatalogResource;
use App\Http\Resources\TrainedAgent\TrainedAgentCategoryResource;
use App\Http\Resources\TrainedAgent\TrainedAgentHireResource;
use App\Models\TrainedAgentBlueprint;
use App\Models\TrainedAgentCategory;
use App\Models\TrainedAgentHire;
use App\Services\TrainedAgent\TrainedAgentService;
use Illuminate\Http\Request;

/**
 * The tenant side of the trained-agent catalog: browse, hire, pay, retry.
 */
class TrainedAgentController extends Controller
{
    public function __construct(
        private readonly TrainedAgentService $service,
    ) {}

    /**
     * The catalog, the tenant's own hires and what their plan still allows —
     * one request, because the page cannot render any part of it usefully
     * without the other two (a price means nothing without the allowance).
     */
    public function index(Request $request)
    {
        $tenant = $request->user()->tenant;

        $categories = TrainedAgentCategory::query()
            ->active()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => TrainedAgentCatalogResource::collection($this->service->catalog()),
            'categories' => TrainedAgentCategoryResource::collection($categories),
            'hires' => TrainedAgentHireResource::collection($this->service->hires($tenant)),
            'usage' => $this->service->usageSummary($tenant),
        ]);
    }

    /**
     * Hire a blueprint. Free while the plan's included slots last, otherwise
     * charged to the prepaid balance — the server decides which, from the
     * allowance.
     */
    public function hire(Request $request, TrainedAgentBlueprint $blueprint)
    {
        $validated = $request->validate([
            'provider_credential_id' => ['required', 'integer'],
            'agent_name' => ['nullable', 'string', 'max:150'],
        ]);

        abort_unless($blueprint->is_active && $blueprint->is_public, 404);

        $tenant = $request->user()->tenant;

        // A paid hire is a charge, and spending the balance is a billing act
        // even for someone allowed to manage agents. The free path stays open to
        // anyone with ai-agents.create, which is the common case.
        if (! $this->service->usageSummary($tenant)['can_hire_included']) {
            abort_unless($request->user()->can('billing.manage'), 403, 'Your included agents are used up; buying another requires billing permission.');
        }

        try {
            $hire = $this->service->hire(
                $tenant,
                $blueprint,
                (int) $validated['provider_credential_id'],
                $validated['agent_name'] ?? null,
            );
        } catch (InsufficientCreditException $e) {
            return response()->json($this->shortfall($e), 422);
        }

        return response()->json([
            'data' => new TrainedAgentHireResource($hire->load('blueprint.category')),
            'usage' => $this->service->usageSummary($tenant),
        ], 201);
    }

    /** Give up on an unpaid hire and void its charge. */
    public function abandon(Request $request, int $hire)
    {
        $row = $this->findHire($request, $hire);

        $abandoned = $this->service->abandonPending($row);

        return response()->json([
            'message' => $abandoned ? 'Purchase abandoned' : 'This purchase can no longer be abandoned',
            'usage' => $this->service->usageSummary($request->user()->tenant),
        ], $abandoned ? 200 : 409);
    }

    /**
     * Re-run a fork that failed. Without this button the alternative is a
     * support ticket.
     *
     * A paid hire is charged again, because failing refunded it — normally
     * invisible, since the refund is sitting in the same balance. It can still
     * come back 422 if the tenant spent that money in between.
     */
    public function retry(Request $request, int $hire)
    {
        $row = $this->findHire($request, $hire);

        abort_unless($row->status === HireStatus::Failed, 409, 'This agent is not in a failed state.');

        try {
            $this->service->retry($row);
        } catch (InsufficientCreditException $e) {
            return response()->json($this->shortfall($e), 422);
        }

        return response()->json([
            'data' => new TrainedAgentHireResource($row->fresh()->load('blueprint.category')),
        ]);
    }

    private function findHire(Request $request, int $id): TrainedAgentHire
    {
        return TrainedAgentHire::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->findOrFail($id);
    }

    /**
     * The refusal a page can act on: the amount still missing, not just the
     * fact that something was missing.
     *
     * @return array<string, mixed>
     */
    private function shortfall(InsufficientCreditException $e): array
    {
        return [
            'message' => 'Seu saldo não cobre esta contratação. Recarregue para continuar.',
            'code' => 'insufficient_credit',
            'balance_cents' => $e->balanceCents,
            'required_cents' => $e->requiredCents,
            'shortfall_cents' => $e->shortfallCents(),
        ];
    }
}

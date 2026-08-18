<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\Billing\BillingCycle;
use App\Enums\Billing\Feature;
use App\Enums\Billing\Quota;
use App\Http\Controllers\Controller;
use App\Http\Resources\Billing\PlanResource;
use App\Models\Plan;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AdminPlanController extends Controller
{
    public function index()
    {
        $plans = Plan::orderBy('sort_order')->get();

        return PlanResource::collection($plans);
    }

    /**
     * The feature and quota keys a plan may carry.
     *
     * The Back Office plan editor renders this instead of keeping its own list.
     * It used to keep one, and it fell behind: `crm` shipped with the Leads
     * module and never reached the array, so — because saving a plan replaces
     * the whole `features` object — opening any plan in the editor and pressing
     * save switched the funnel off for that plan's customers, with no error and
     * nothing on screen to suggest it had happened.
     */
    public function meta()
    {
        return response()->json([
            'data' => [
                'features' => array_map(fn (Feature $f) => [
                    'key' => $f->value,
                    'label' => $f->label(),
                    'description' => $f->description(),
                ], Feature::cases()),
                'quotas' => array_map(fn (Quota $q) => [
                    'key' => $q->value,
                    'label' => $q->label(),
                    'description' => $q->description(),
                    'enforced_at' => $q->enforcedAt(),
                ], Quota::cases()),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $validated = $this->validatePlan($request);
        $validated['slug'] ??= Str::slug($validated['name']);

        $plan = Plan::create($validated);

        return (new PlanResource($plan))->response()->setStatusCode(201);
    }

    public function update(Request $request, Plan $plan)
    {
        $validated = $this->validatePlan($request, $plan);
        $plan->update($validated);

        return new PlanResource($plan->fresh());
    }

    public function destroy(Plan $plan)
    {
        $plan->delete(); // soft delete — keeps history for existing subscriptions

        return response()->json(['message' => 'Plan deleted']);
    }

    private function validatePlan(Request $request, ?Plan $plan = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'slug' => ['nullable', 'string', 'max:120', Rule::unique('plans', 'slug')->ignore($plan?->id)],
            'description' => ['nullable', 'string', 'max:500'],
            'price_cents' => ['required', 'integer', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'billing_cycle' => ['required', Rule::enum(BillingCycle::class)],
            'trial_days' => ['nullable', 'integer', 'min:0'],
            // Both blocks are sent whole and stored whole, so an unknown key is
            // never a harmless extra — it is a feature nobody will ever enforce,
            // or a typo that quietly disables one. The `array:` whitelist keeps
            // the enums the only vocabulary a plan can speak.
            'quotas' => ['nullable', 'array:'.implode(',', Quota::values())],
            // Blank = unlimited, so nullable rather than required.
            'quotas.*' => ['nullable', 'integer', 'min:0'],
            'features' => ['nullable', 'array:'.implode(',', Feature::values())],
            'features.*' => ['boolean'],
            'is_active' => ['boolean'],
            'is_public' => ['boolean'],
            'sort_order' => ['nullable', 'integer'],
            'mp_card_enabled' => ['boolean'],
            'mp_pix_enabled' => ['boolean'],
        ]);
    }
}

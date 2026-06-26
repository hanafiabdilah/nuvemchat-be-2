<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\Billing\BillingCycle;
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
            'quotas' => ['nullable', 'array'],
            'features' => ['nullable', 'array'],
            'is_active' => ['boolean'],
            'is_public' => ['boolean'],
            'sort_order' => ['nullable', 'integer'],
            'mp_card_enabled' => ['boolean'],
            'mp_pix_enabled' => ['boolean'],
        ]);
    }
}

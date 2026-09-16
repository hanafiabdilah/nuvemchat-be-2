<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\Billing\BillingCycle;
use App\Enums\Billing\Feature;
use App\Enums\Billing\Quota;
use App\Http\Controllers\Controller;
use App\Http\Resources\Billing\PlanResource;
use App\Models\Market;
use App\Models\Plan;
use App\Services\Market\MarketBillingMethods;
use App\Services\Market\MarketResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AdminPlanController extends Controller
{
    public function index()
    {
        $plans = Plan::with('marketPrices')->orderBy('sort_order')->get();

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
                // The countries a plan can be priced for. Served here rather
                // than from the Markets endpoint so pricing a plan does not
                // also require the permission that opens a country.
                'markets' => Market::query()
                    ->orderBy('name')
                    ->get(['code', 'name', 'currency'])
                    ->map(fn (Market $market) => [
                        'code' => $market->code,
                        'name' => $market->name,
                        'currency' => $market->currency,
                        // Which rails reach this country, so the editor draws a
                        // Pix tick only where Pix exists. Without it the form
                        // offered one for every country — Pix is Brazilian, and
                        // a checkbox that cannot work is worse than no checkbox.
                        'billing_methods' => MarketBillingMethods::forMarket($market->code),
                    ]),
                // Which of them the plan row's own `price_cents` belongs to.
                'default_market' => MarketResolver::defaultCode(),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $validated = $this->validatePlan($request);
        $validated['slug'] ??= Str::slug($validated['name']);
        $prices = $this->pricesFrom($validated);

        $plan = Plan::create($validated);

        // Absent, the plan keeps the home-market price the model seeded from
        // its own columns — an editor that predates per-country prices must
        // still produce a plan that is on sale.
        if ($prices !== null) {
            $plan->syncMarketPrices($prices);
        }

        return (new PlanResource($plan->load('marketPrices')))->response()->setStatusCode(201);
    }

    public function update(Request $request, Plan $plan)
    {
        $validated = $this->validatePlan($request, $plan);
        $prices = $this->pricesFrom($validated);

        $plan->update($validated);

        // Only when the editor sent a list. A client that does not know about
        // per-country prices must not be able to take a plan off sale
        // everywhere by omitting them.
        if ($prices !== null) {
            $plan->syncMarketPrices($prices);
        }

        return new PlanResource($plan->fresh()->load('marketPrices'));
    }

    public function destroy(Plan $plan)
    {
        $plan->delete(); // soft delete — keeps history for existing subscriptions

        return response()->json(['message' => 'Plan deleted']);
    }

    /**
     * Pull the price list out of the validated payload.
     *
     * Null (rather than an empty list) when the key is absent, so "did not say"
     * and "said: nowhere" stay different answers.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, int>|null
     */
    private function pricesFrom(array &$validated): ?array
    {
        if (! array_key_exists('prices', $validated)) {
            return null;
        }

        $prices = $validated['prices'] ?? [];
        unset($validated['prices']);

        // Either shape: a bare amount (what the editor sent before payment
        // methods moved per country) or the whole row. The trait accepts both,
        // so an older client keeps working and simply leaves the methods alone.
        //
        // ⚠️ A foreach rather than array_map because the market code is the key,
        // and the key is what decides which rails a tick may name at all.
        $clean = [];

        foreach ($prices as $code => $value) {
            if (! is_array($value)) {
                $clean[$code] = (int) $value;

                continue;
            }

            $row = ['amount_cents' => (int) ($value['amount_cents'] ?? 0)];

            // Clamped, not merely validated: "Pix in Indonesia" is not a
            // decision an admin is allowed to record (see MarketBillingMethods),
            // and storing it would write back the very lie the read-side
            // accessor on MarketPrice exists to undo.
            foreach (['card_enabled' => 'card', 'pix_enabled' => 'pix'] as $flag => $method) {
                if (array_key_exists($flag, $value)) {
                    $row[$flag] = (bool) $value[$flag] && MarketBillingMethods::has((string) $code, $method);
                }
            }

            $clean[$code] = $row;
        }

        return $clean;
    }

    private function validatePlan(Request $request, ?Plan $plan = null): array
    {
        return $request->validate([
            // Per-country prices, keyed by market code: what the plan costs
            // there, and — by being present at all — that it is sold there.
            // No exchange rate is involved; a plan's price is whatever somebody
            // typed for that country.
            'prices' => ['sometimes', 'array'],
            // A number, or a row carrying the payment methods this plan offers
            // in that country. Pix is a Brazilian rail, so the question is per
            // country — `plans.pix_enabled` answered it for the whole world.
            'prices.*' => ['nullable'],
            'prices.*.amount_cents' => ['sometimes', 'integer', 'min:0'],
            'prices.*.card_enabled' => ['sometimes', 'boolean'],
            'prices.*.pix_enabled' => ['sometimes', 'boolean'],
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
            'card_enabled' => ['boolean'],
            'pix_enabled' => ['boolean'],
        ]);
    }
}

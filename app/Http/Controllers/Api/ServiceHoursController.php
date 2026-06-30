<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\BusinessHours;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class ServiceHoursController extends Controller
{
    /**
     * Return the tenant's service hours (falling back to a sensible default
     * when nothing has been configured yet) plus the live open/closed state.
     */
    public function show()
    {
        $tenant = $this->tenant();
        $config = $tenant->service_hours ?: BusinessHours::defaultConfig();

        return response()->json([
            'data' => array_merge($config, [
                'is_open_now' => BusinessHours::isOpen($tenant),
            ]),
        ]);
    }

    /**
     * Update the tenant's service hours.
     */
    public function update(Request $request)
    {
        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            'timezone' => ['required', 'string', Rule::in(timezone_identifiers_list())],
            'away_message' => ['nullable', 'string', 'max:1000'],
            'days' => ['required', 'array'],
            'days.*' => ['array'],
            'days.*.*.open' => ['required', 'date_format:H:i'],
            'days.*.*.close' => ['required', 'date_format:H:i'],
        ]);

        // Keep only the recognised day keys, in canonical order.
        $days = [];
        foreach (BusinessHours::DAYS as $day) {
            $days[$day] = array_values($validated['days'][$day] ?? []);
        }

        $tenant = $this->tenant();
        $tenant->service_hours = [
            'enabled' => (bool) $validated['enabled'],
            'timezone' => $validated['timezone'],
            'days' => $days,
            'away_message' => $validated['away_message'] ?? '',
        ];
        $tenant->save();

        return $this->show();
    }

    private function tenant(): Tenant
    {
        return Tenant::findOrFail(Auth::user()->tenant_id);
    }
}

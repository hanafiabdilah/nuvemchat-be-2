<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Settings that belong to the workspace rather than to the person reading it.
 *
 * Today that is one field: the clock. A workspace starts on its country's
 * default (Tenant::save copies it in), which is right for almost everyone and
 * wrong for the ones it cannot know about — Brazil alone spans four zones, and
 * a business in Manaus reading Brasília hours in its service-hours form and on
 * its renewal dates has no way to say so.
 *
 * Kept out of the billing profile endpoint on purpose even though it shares a
 * screen with it: a timezone is not a tax identity, and an endpoint that
 * validates both would have to make the document rules optional to let a zone
 * through.
 */
class WorkspaceController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $tenant = $request->user()->tenant;

        return response()->json([
            'data' => [
                'timezone' => $tenant->displayTimezone(),
                // What the country would have chosen, so the form can say
                // whether this workspace is following it or has been moved.
                'market_timezone' => $tenant->market?->default_timezone,
                'market_name' => $tenant->market?->name,
            ],
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $tenant = $request->user()->tenant;

        $validated = $request->validate([
            // Against the zones this PHP build actually knows, because the value
            // is handed to Carbon on paths that run unattended: a typo accepted
            // here would surface as a renewal notice throwing in a scheduler.
            'timezone' => ['required', 'string', Rule::in(timezone_identifiers_list())],
        ]);

        $tenant->timezone = $validated['timezone'];
        $tenant->save();

        return $this->show($request);
    }
}

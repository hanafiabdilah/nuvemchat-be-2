<?php

namespace App\Http\Controllers\Api\Crm;

use App\Http\Controllers\Controller;
use App\Jobs\Crm\RescoreLeads;
use App\Models\Lead;
use App\Services\Crm\LeadScoring;
use Illuminate\Http\Request;

/**
 * Settings › Lead scoring: the points the workspace gives. Saving rescores
 * every open lead at once, so the list never shows numbers from rules that
 * no longer exist.
 */
class LeadScoringController extends Controller
{
    private const INLINE_LIMIT = 2000;

    public function show(Request $request)
    {
        return response()->json([
            'data' => LeadScoring::config($request->user()->tenant),
            'criteria' => LeadScoring::CRITERIA,
        ]);
    }

    public function update(Request $request)
    {
        $tenant = $request->user()->tenant;
        $config = LeadScoring::validate($tenant->id, $request->all());

        $tenant->forceFill(['crm_lead_scoring' => $config])->save();

        // A big workspace is rescored in the background, the rest right away.
        $open = Lead::query()->where('tenant_id', $tenant->id)->whereIn('status', ['new', 'contacted', 'qualified'])->count();

        if ($open > self::INLINE_LIMIT) {
            RescoreLeads::dispatch($tenant->id);
        }

        return response()->json([
            'data' => $config,
            'rescored' => $open > self::INLINE_LIMIT ? null : LeadScoring::rescore($tenant->id),
            'message' => __('crm.scoring.saved'),
        ]);
    }
}

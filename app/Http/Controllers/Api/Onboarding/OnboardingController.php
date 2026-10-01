<?php

namespace App\Http\Controllers\Api\Onboarding;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\Onboarding\OnboardingState;
use Illuminate\Http\Request;

class OnboardingController extends Controller
{
    /**
     * "Set up later" in the first-run guide. The only way through it that
     * leaves no other trace, so it is the only one stored.
     */
    public function skip(Request $request, OnboardingState $onboarding)
    {
        $tenant = $request->user()->tenant;
        abort_if($tenant === null, 404);

        $onboarding->skip($tenant);

        return response()->json(['data' => self::payloadFor($tenant->fresh(), $onboarding)]);
    }

    /** What the dashboard reads, here and in GET /user. */
    public static function payloadFor(Tenant $tenant, OnboardingState $onboarding): array
    {
        $completedBy = $onboarding->completedBy($tenant);

        return [
            'required' => $completedBy === null,
            'completed_by' => $completedBy,
        ];
    }
}

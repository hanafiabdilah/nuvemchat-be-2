<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\MarketResource;
use App\Models\Market;
use App\Services\Market\MarketResolver;
use App\Support\PlatformUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What a page needs to know before anyone has signed in.
 *
 * Almost everything the dashboard uses to reach the platform is the same on
 * every domain: the API is same-origin, and the platform address and realtime
 * host are platform-wide. The one thing that differs is which country the
 * domain sells in — the market a signup here would join, and so the currency
 * that account is billed in for good. That is what this answers.
 */
class PublicBootstrapController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $market = Market::query()->findOrFail(MarketResolver::codeForRequest($request));

        return response()->json([
            'data' => [
                'platform_url' => PlatformUrl::root() ?? $request->root(),
                'market' => MarketResource::make($market)->resolve($request),
                // Whether there is anything to tell apart. With a single market a
                // "this account is Brazilian" notice at signup says nothing.
                'multiple_markets' => Market::query()->count() > 1,
            ],
        ]);
    }
}

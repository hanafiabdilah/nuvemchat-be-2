<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Flow;
use App\Services\Flow\SplitTests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The results of the split tests in one flow, for the builder.
 *
 * Read with `flows.view`, like the flow itself. Sales are money, and a role
 * that may read a flow is not necessarily one that may read the workspace's
 * revenue — so the sales columns come back only with `statistics.tenant.view`,
 * the permission the sales page asks for, and null otherwise.
 */
class FlowSplitTestController extends Controller
{
    public function index(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $flow = Flow::where('tenant_id', $user->tenant_id)->findOrFail($id);

        return response()->json([
            'data' => SplitTests::results($flow, $user->can('statistics.tenant.view')),
        ]);
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\SyncMetaAdAccount;
use App\Models\MetaAdAccount;
use App\Services\MetaAds\MetaAdsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The workspace's Meta ad accounts, as the Integrations page manages them.
 *
 * Nothing here carries a token or Meta's own error text: an account's state
 * travels as a status the page words itself (needs a new login / last read
 * failed), and the token never leaves the server.
 */
class MetaAdsController extends Controller
{
    public function __construct(private MetaAdsService $service) {}

    public function index(Request $request): JsonResponse
    {
        if (! MetaAdsService::enabled()) {
            return $this->unavailable();
        }

        $accounts = MetaAdAccount::where('tenant_id', $request->user()->tenant_id)
            ->with('connectedBy:id,name')
            ->orderBy('name')
            ->get();

        return response()->json([
            'available' => MetaAdsService::available(),
            'accounts' => $accounts->map(fn (MetaAdAccount $account) => $this->present($account))->values(),
        ]);
    }

    /** The Facebook popup URL, valid for one login. */
    public function oauth(Request $request): JsonResponse
    {
        if (! MetaAdsService::enabled()) {
            return $this->unavailable();
        }

        if (! MetaAdsService::available()) {
            return response()->json([
                'message' => 'Connecting Meta ad accounts is not available on this platform yet.',
                'code' => 'meta_ads_unavailable',
            ], 422);
        }

        return response()->json([
            'url' => $this->service->oauthUrl($request->user()->tenant, $request->user()),
        ]);
    }

    /** Count an account in or out of the statistics. */
    public function update(int $id, Request $request): JsonResponse
    {
        if (! MetaAdsService::enabled()) {
            return $this->unavailable();
        }

        $validated = $request->validate(['enabled' => ['required', 'boolean']]);

        $account = MetaAdAccount::where('tenant_id', $request->user()->tenant_id)->findOrFail($id);
        $wasEnabled = $account->enabled;
        $account->update(['enabled' => $validated['enabled']]);

        // Back on: catch up on what it spent while it was off.
        if ($validated['enabled'] && ! $wasEnabled && $account->status !== MetaAdAccount::STATUS_REAUTH) {
            SyncMetaAdAccount::dispatch($account->id, 30);
        }

        return response()->json(['account' => $this->present($account->fresh('connectedBy'))]);
    }

    /** Read the costs again now, instead of at the next hourly pass. */
    public function sync(Request $request): JsonResponse
    {
        if (! MetaAdsService::enabled()) {
            return $this->unavailable();
        }

        $queued = MetaAdAccount::where('tenant_id', $request->user()->tenant_id)
            ->where('enabled', true)
            ->where('status', '!=', MetaAdAccount::STATUS_REAUTH)
            ->pluck('id')
            ->each(fn (int $id) => SyncMetaAdAccount::dispatch($id, MetaAdsService::SYNC_DAYS))
            ->count();

        return response()->json(['queued' => $queued]);
    }

    /** Forget every ad account of the workspace, and the costs read from them. */
    public function destroy(Request $request): JsonResponse
    {
        if (! MetaAdsService::enabled()) {
            return $this->unavailable();
        }

        $removed = $this->service->disconnect($request->user()->tenant);

        return response()->json(['removed' => $removed]);
    }

    /** Switched off on the platform (META_ADS_ENABLED): the dashboard shows "coming soon". */
    private function unavailable(): JsonResponse
    {
        return response()->json([
            'message' => 'This feature is not available yet.',
            'code' => 'feature_unavailable',
            'feature' => 'meta_ads',
        ], 403);
    }

    /** @return array<string, mixed> */
    private function present(MetaAdAccount $account): array
    {
        return [
            'id' => $account->id,
            'account_id' => $account->account_id,
            'name' => $account->name,
            'currency' => $account->currency,
            'timezone' => $account->timezone,
            'enabled' => $account->enabled,
            'status' => $account->status,
            'last_synced_at' => $account->last_synced_at?->toIso8601String(),
            'connected_by' => $account->connectedBy?->name,
            'created_at' => $account->created_at?->toIso8601String(),
        ];
    }
}

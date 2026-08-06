<?php

namespace App\Http\Controllers\Api\Apiway;

use App\Exceptions\ApiwayPartnerException;
use App\Http\Controllers\Controller;
use App\Services\Connection\Apiway\ApiwayPartnerClient;
use App\Services\Connection\Apiway\ApiwayService;
use Illuminate\Http\Request;

class ApiwayCatalogController extends Controller
{
    public function __construct(
        private readonly ApiwayService $apiway,
        private readonly ApiwayPartnerClient $partner,
    ) {}

    /**
     * ProxyBR price catalog (tiers, locations, min/max) + the tenant's
     * included-quota usage, so the UI can decide between "use included" and
     * "buy at unit price".
     */
    public function catalog(Request $request)
    {
        if (! $this->partner->isConfigured()) {
            return response()->json([
                'message' => 'API Way não está configurado na plataforma.',
                'code' => 'apiway_unconfigured',
            ], 503);
        }

        try {
            $catalog = $this->apiway->catalog();
        } catch (ApiwayPartnerException $e) {
            return response()->json([
                'message' => 'Catálogo API Way indisponível no momento.',
                'code' => $e->getErrorCode() ?? 'apiway_unavailable',
            ], 502);
        }

        return response()->json([
            'data' => array_merge($catalog, [
                'usage' => $this->apiway->usageSummary($request->user()->tenant),
            ]),
        ]);
    }

    /**
     * Price preview straight from ProxyBR — shown before checkout. The charge
     * itself re-quotes server-side, so this is display-only.
     */
    public function quote(Request $request)
    {
        $validated = $request->validate([
            'quantity' => ['required', 'integer', 'min:1', 'max:100'],
            'location_code' => ['required', 'string', 'max:20'],
            'cycle' => ['required', 'in:mensal,anual'],
        ]);

        try {
            $quote = $this->partner->quote(
                $validated['quantity'],
                $validated['location_code'],
                $validated['cycle'],
            );
        } catch (ApiwayPartnerException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => $e->getErrorCode() ?? 'apiway_unavailable',
            ], in_array($e->getHttpStatus(), [400, 422], true) ? 422 : 502);
        }

        return response()->json(['data' => $quote]);
    }
}

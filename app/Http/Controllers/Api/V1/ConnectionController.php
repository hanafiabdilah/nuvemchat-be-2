<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Connection\Status as ConnectionStatus;
use App\Http\Controllers\Controller;
use App\Models\Connection;
use App\Models\TenantApiKey;
use App\Services\Lead\LeadIntakeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/connections — which numbers a lead can be sent to.
 *
 * Doubles as the cheapest way for an integrator to check a key works. Identity
 * only: no credentials, no connection API keys.
 */
class ConnectionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var TenantApiKey $key */
        $key = $request->attributes->get('tenant_api_key');

        $leadChannels = array_map(fn ($channel) => $channel->value, LeadIntakeService::CHANNELS);

        $connections = Connection::where('tenant_id', $key->tenant_id)
            ->orderBy('id')
            ->get()
            ->map(function (Connection $connection) use ($leadChannels) {
                $status = $connection->status instanceof \BackedEnum ? $connection->status->value : $connection->status;

                return [
                    'id' => $connection->id,
                    'name' => $connection->name,
                    'channel' => $connection->channel->value,
                    'status' => $status,
                    'accepts_leads' => $status === ConnectionStatus::Active->value
                        && in_array($connection->channel->value, $leadChannels, true),
                ];
            });

        return response()->json(['data' => $connections]);
    }
}

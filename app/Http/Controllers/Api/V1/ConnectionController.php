<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Connection\Status as ConnectionStatus;
use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use App\Models\Connection;
use App\Services\Lead\LeadIntakeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/connections — the workspace's connections, by public id, with
 * what each can be used for.
 *
 * Doubles as the cheapest way for an integrator to check a key works. Identity
 * only: no credentials.
 */
class ConnectionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var ApiKey $key */
        $key = $request->attributes->get('api_key');

        $connections = Connection::where('tenant_id', $key->tenant_id)
            ->orderBy('id')
            ->get()
            ->map(function (Connection $connection) {
                $active = $connection->status === ConnectionStatus::Active;

                return [
                    'id' => $connection->public_id,
                    'name' => $connection->name,
                    'channel' => $connection->channel->value,
                    'status' => $connection->status->value,
                    'sends_messages' => $active && in_array($connection->channel, SendMessageController::CHANNELS, true),
                    'accepts_leads' => $active && in_array($connection->channel, LeadIntakeService::CHANNELS, true),
                ];
            });

        return response()->json(['data' => $connections]);
    }
}

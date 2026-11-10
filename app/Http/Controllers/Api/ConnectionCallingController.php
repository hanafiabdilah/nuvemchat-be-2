<?php

namespace App\Http\Controllers\Api;

use App\Enums\Connection\Channel;
use App\Exceptions\UpstreamServiceException;
use App\Http\Controllers\Controller;
use App\Models\Connection;
use App\Services\Calls\WhatsappCallingApi;
use Illuminate\Http\Request;

/**
 * "Calls" on a WhatsApp Official connection: whether Meta lets customers call
 * the number, and what they are told in the chat when nobody can answer.
 *
 * Whether calling is on lives at Meta, not here — it is read live every time
 * rather than mirrored into a column that would go stale the day someone
 * flips it in WhatsApp Manager.
 */
class ConnectionCallingController extends Controller
{
    public function __construct(private WhatsappCallingApi $api) {}

    public function show(Request $request, int $id)
    {
        $connection = $this->connection($request, $id);

        return response()->json(['data' => $this->state($connection)]);
    }

    public function update(Request $request, int $id)
    {
        $connection = $this->connection($request, $id);

        $validated = $request->validate([
            'enabled' => ['sometimes', 'boolean'],
            'busy_message' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        if (array_key_exists('busy_message', $validated)) {
            $text = trim((string) $validated['busy_message']);
            $connection->forceFill(['call_busy_message' => $text === '' ? null : $text])->save();
        }

        if (array_key_exists('enabled', $validated)) {
            if ($reason = $this->unavailableReason($connection)) {
                return response()->json([
                    'message' => 'Calls cannot be answered from the dashboard on this connection.',
                    'code' => $reason,
                ], 422);
            }

            $this->api->setEnabled($connection, (bool) $validated['enabled']);
        }

        return response()->json(['data' => $this->state($connection->refresh())]);
    }

    /** @return array<string, mixed> */
    private function state(Connection $connection): array
    {
        $state = [
            'available' => true,
            'unavailable_reason' => $this->unavailableReason($connection),
            'status' => null,
            'restrictions' => [],
            'busy_message' => $connection->call_busy_message,
            'error' => null,
        ];

        if ($state['unavailable_reason']) {
            $state['available'] = false;

            return $state;
        }

        try {
            $calling = $this->api->settings($connection);
            $state['status'] = strtoupper((string) ($calling['status'] ?? 'DISABLED'));
            $state['restrictions'] = collect(data_get($calling, 'restrictions.restrictions_list', []))
                ->map(fn ($item) => [
                    'type' => $item['type'] ?? null,
                    'expires_at' => isset($item['expiration']) ? (int) $item['expiration'] : null,
                ])
                ->values()
                ->all();
        } catch (UpstreamServiceException $e) {
            $state['error'] = ['message' => $e->getMessage(), 'code' => $e->getErrorCode(), 'ref' => $e->reference];
        }

        return $state;
    }

    /**
     * Why this connection cannot ring the dashboard at all. Coexistence keeps
     * calls on the WhatsApp Business app on the phone — Meta does not route
     * them to the Cloud API.
     */
    private function unavailableReason(Connection $connection): ?string
    {
        if (! empty($connection->credentials['is_coexistence'])) {
            return 'coexistence';
        }

        if (empty($connection->credentials['phone_number_id']) || empty($connection->credentials['access_token'])) {
            return 'not_connected';
        }

        return null;
    }

    private function connection(Request $request, int $id): Connection
    {
        $user = $request->user();
        $connection = Connection::query()->where('tenant_id', $user->tenant_id)->findOrFail($id);

        abort_unless($user->canAccessConnection($connection), 404);
        abort_unless($connection->channel === Channel::WhatsappOfficial, 422, 'Calls are only available on WhatsApp Official connections.');

        return $connection;
    }
}

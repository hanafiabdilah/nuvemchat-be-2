<?php

namespace App\Http\Controllers\Api\Apiway;

use App\Http\Controllers\Controller;
use App\Models\Connection;
use App\Services\Connection\Apiway\ApiwaySendPolicy;
use App\Services\Message\ApiwaySendQueue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The API Way anti-ban policy of one connection's number (ApiwaySendPolicy):
 * read it, change it, empty the queue it has built up.
 */
class ApiwaySendPolicyController extends Controller
{
    public function __construct(
        private readonly ApiwaySendPolicy $policy,
    ) {}

    public function show(int $id): JsonResponse
    {
        return response()->json(['data' => $this->policy->show($this->connection($id))]);
    }

    public function update(int $id, Request $request): JsonResponse
    {
        $connection = $this->connection($id);

        $validated = $request->validate([
            // One of the levels, instead of the fields below; see PRESETS.
            'preset' => ['sometimes', 'string', Rule::in(array_keys(ApiwaySendPolicy::PRESETS))],
            // Never 0 — see ApiwaySendPolicy::UNLIMITED_DAILY_CAP.
            'daily_cap' => ['sometimes', 'integer', 'min:1', 'max:'.ApiwaySendPolicy::UNLIMITED_DAILY_CAP],
            'daily_cap_unlimited' => ['sometimes', 'boolean'],
            'msgs_per_min' => ['sometimes', 'integer', 'min:1', 'max:'.ApiwaySendPolicy::MAX_MSGS_PER_MIN],
            'min_delay_ms' => ['sometimes', 'integer', 'min:'.ApiwaySendPolicy::MIN_DELAY_MS, 'max:'.ApiwaySendPolicy::MAX_DELAY_MS],
            'max_delay_ms' => ['sometimes', 'integer', 'min:'.ApiwaySendPolicy::MIN_DELAY_MS, 'max:'.ApiwaySendPolicy::MAX_DELAY_MS],
            'auto_throttle_enabled' => ['sometimes', 'boolean'],
            // Turning the queue back on only; Pingly never switches it off.
            'queue_enabled' => ['sometimes', 'accepted'],
        ]);

        foreach (['daily_cap_unlimited', 'auto_throttle_enabled', 'queue_enabled'] as $flag) {
            if (array_key_exists($flag, $validated)) {
                $validated[$flag] = $request->boolean($flag);
            }
        }

        // A level replaces the individual fields rather than mixing with them:
        // half a level is a hand-tuned policy wearing a level's name.
        if (isset($validated['preset'])) {
            $validated = ApiwaySendPolicy::presetChanges($validated['preset'])
                + array_intersect_key($validated, ['queue_enabled' => true]);
        }

        return response()->json([
            'data' => $this->policy->update($connection, $validated, $request->user()),
        ]);
    }

    /**
     * The levels on their own, for the connection wizard: it offers them
     * before an instance exists to read a policy from.
     */
    public function presets(): JsonResponse
    {
        return response()->json([
            'data' => [
                'presets' => ApiwaySendPolicy::presets(),
                'unlimited_daily_cap' => ApiwaySendPolicy::UNLIMITED_DAILY_CAP,
            ],
        ]);
    }

    public function clearQueue(int $id, Request $request, ApiwaySendQueue $queue): JsonResponse
    {
        return response()->json([
            'data' => $this->policy->clearQueue($this->connection($id), $request->user(), $queue),
        ]);
    }

    private function connection(int $id): Connection
    {
        $connection = request()->user()->tenant->connections()->findOrFail($id);

        abort_unless(request()->user()->canAccessConnection($connection->id), 403);

        return $connection;
    }
}

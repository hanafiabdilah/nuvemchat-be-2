<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Live\LiveMonitor;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The tenant dashboard's live monitor.
 *
 * Split into two request shapes on purpose, because the two halves of the page
 * change at completely different speeds. The stream is a keyset delta — the
 * client sends the last message id it holds and gets only what happened since,
 * which is cheap enough to ask for every couple of seconds. The counters and
 * the agent roster are aggregates over the whole workspace; they are worth a
 * query every ten seconds and not every two, so they only come back when the
 * caller asks for them with `full=1` (or on the first load, which has no
 * cursor and therefore needs everything).
 *
 * Nothing here returns message content — see LiveMonitor.
 */
class LiveController extends Controller
{
    public function index(Request $request)
    {
        $request->validate([
            'after_id' => ['nullable', 'integer', 'min:0'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.LiveMonitor::MAX_FEED_LIMIT],
            'full' => ['nullable', 'boolean'],
            'scope' => ['nullable', Rule::in(LiveMonitor::scopes())],
        ]);

        $user = $request->user();
        $monitor = LiveMonitor::forUser($user, (string) $request->string('scope', LiveMonitor::SCOPE_ALL));

        $afterId = $request->filled('after_id') ? (int) $request->integer('after_id') : null;
        $events = $monitor->feed($afterId, (int) $request->integer('limit', LiveMonitor::FEED_LIMIT));

        $payload = [
            'now' => now()->toIso8601String(),
            // Never move the cursor backwards on a delta that came back empty:
            // the client's own id is newer than anything this call saw.
            'cursor' => $events === [] && $afterId !== null ? $afterId : $monitor->cursorFor($events),
            'events' => $events,
        ];

        if ($afterId === null || $request->boolean('full')) {
            $payload['pulse'] = $monitor->pulse();
            $payload['status_updates'] = $monitor->statusUpdates();
            $payload['activity'] = $monitor->activity();

            // The roster is agent data, gated by the same permission as the
            // Agents tab of Statistics. Someone without it still gets the
            // stream and the counters; the panel simply is not there.
            $payload['agents'] = $user->can('statistics.agents.view')
                ? $monitor->agents()
                : null;
        }

        return response()->json(['data' => $payload]);
    }
}

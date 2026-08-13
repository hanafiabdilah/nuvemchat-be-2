<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\MessageResource;
use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class MessageController extends Controller
{
    public function index(Request $request)
    {
        $since = $request->input('since');
        $before = $request->input('before');
        $connectionId = $request->input('connection_id');
        $limit = (int) $request->input('limit', 100);
        $limit = max(1, min($limit, 500));

        $user = Auth::user();

        // Eager-load everything MessageResource touches — without this, each
        // message lazy-loads its relations and a 500-row sync page explodes
        // into hundreds of queries.
        $query = Message::with([
            'repliedMessage',
            'reactions.contact',
            'contact',
            'sentByUser',
            'sentByFlow',
            'sentByAiHubAgent',
            'conversation.connection',
        ])->whereHas('conversation', function ($q) use ($user, $connectionId) {
            // visibleTo() is the tenant AND connection-access filter — plain
            // tenant scoping here used to hand an agent the whole tenant's
            // message history regardless of which connections they were given.
            $q->visibleTo($user);

            // Optional: restrict to one connection. Used by the client to
            // backfill the history of a connection it was just granted,
            // without re-pulling everything it already holds.
            if (filled($connectionId)) {
                $q->where('connection_id', $connectionId);
            }
        })->orderBy('id', 'DESC');

        // Delta sync: only messages touched since the last sync (edits, new).
        if ($since !== null && $since !== '') {
            $query->where('updated_at', '>', $since);
        }

        // Cursor pagination (newest-first). Client walks backwards by passing
        // `before` = smallest id it already holds, until has_more is false.
        if ($before !== null && $before !== '') {
            $query->where('id', '<', $before);
        }

        // Fetch one extra row to detect whether older/more messages remain.
        $messages = $query->limit($limit + 1)->get();
        $hasMore = $messages->count() > $limit;
        $messages = $messages->take($limit);

        return response()->json([
            'data' => MessageResource::collection($messages),
            'has_more' => $hasMore,
            'next_before' => $hasMore ? $messages->last()?->id : null,
            'server_time' => now()->toIso8601String(),
        ]);
    }
}

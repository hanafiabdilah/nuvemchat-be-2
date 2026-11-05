<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\MessageResource;
use App\Models\Message;
use App\Services\Message\MessageSearch;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

class MessageController extends Controller
{
    /**
     * Everything MessageResource touches. Shared with the search below and
     * with ConversationController::messages() — without it a page of rows
     * lazy-loads its relations one message at a time.
     */
    public const RELATIONS = [
        'repliedMessage',
        'reactions.contact',
        'contact',
        'sentByUser',
        'sentByFlow',
        'sentByAiHubAgent',
        'conversation.connection',
    ];

    public function index(Request $request)
    {
        // Parsed to a UTC datetime, never bound as the ISO-8601 string the
        // client echoes back (`2026-11-05T10:15:18+00:00`). MariaDB cannot turn
        // that literal into a range on messages_updated_at, so it walked the
        // whole table by primary key instead: 186k rows and 2.5 s for a delta
        // that returned nothing, on every dashboard boot and every reconnect —
        // enough to fill PHP-FPM on 4 Nov 2026. In SQLite the same string is
        // compared as text and never matches a same-day row.
        $since = rescue(
            fn () => filled($request->input('since'))
                ? Carbon::parse($request->input('since'))->utc()->format('Y-m-d H:i:s')
                : null,
            null,
            report: false,
        );
        $before = $request->input('before');
        $connectionId = $request->input('connection_id');
        $limit = (int) $request->input('limit', 100);
        $limit = max(1, min($limit, 500));

        // Stamped before the query runs, not after: a message that changes while
        // this request is being served then falls after the cursor and comes in
        // the next delta instead of being skipped (a re-sent row is harmless —
        // the client upserts). Same rule as ConversationController::index.
        $serverTime = now()->toIso8601String();

        $user = Auth::user();

        // Eager-load everything MessageResource touches — without this, each
        // message lazy-loads its relations and a 500-row sync page explodes
        // into hundreds of queries.
        $query = Message::with(self::RELATIONS)
            ->whereHas('conversation', function ($q) use ($user, $connectionId) {
                // visibleTo() is the tenant AND connection-access filter — plain
                // tenant scoping here used to hand an agent the whole tenant's
                // message history regardless of which connections they were given.
                $q->readableBy($user);

                // Optional: restrict to one connection. Used by the client to
                // backfill the history of a connection it was just granted,
                // without re-pulling everything it already holds.
                if (filled($connectionId)) {
                    $q->where('connection_id', $connectionId);
                }
            })->orderBy('id', 'DESC');

        // Delta sync: only messages touched since the last sync (edits, new).
        if ($since !== null) {
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
            'server_time' => $serverTime,
        ]);
    }

    /**
     * Find messages by their text, across every thread this user can reach.
     *
     * The panel searched its own IndexedDB mirror before this existed, which
     * put two limits on it that nobody chose: it could only find what had
     * already been downloaded, and finding anything meant loading the entire
     * message table into JS memory first (on every keystroke's worth of
     * re-render, through a live query that re-fired on every write).
     *
     * Scoped exactly like the inbox, not merely like the tenant:
     * `visibleTo()` for connection access, and removed groups excluded — a
     * thread the panel refuses to list must not be reachable by typing a word
     * from it.
     *
     * Info notes are deliberately NOT excluded. Most of them render from a
     * code rather than from their stored body ("Ana assumiu esta conversa."),
     * so matching them is a little odd — but the Action node's internal notes
     * are info messages whose body IS what the reader sees, and those are
     * worth finding. That is also what the client-side search did.
     */
    public function search(Request $request)
    {
        $user = Auth::user();
        $terms = MessageSearch::terms((string) $request->input('q', ''));
        $connectionId = $request->input('connection_id');
        $limit = max(1, min((int) $request->input('limit', 50), 100));

        // Nothing long enough to look up. Answered as an empty result plus the
        // rule, so the box can explain itself instead of looking broken.
        if ($terms === []) {
            return response()->json([
                'data' => [],
                'min_term_length' => MessageSearch::MIN_TERM_LENGTH,
            ]);
        }

        $query = Message::with(self::RELATIONS)
            ->whereNotNull('body')
            // Unsent messages have a body on disk and nothing on screen.
            ->whereNull('unsend_at')
            ->whereHas('conversation', function ($q) use ($user, $connectionId) {
                $q->readableBy($user)
                    // Same exclusion as ConversationController@index: a removed
                    // group's history stays on disk but never surfaces.
                    ->whereDoesntHave('contact', function ($c) {
                        $c->whereNotNull('group_removed_at');
                    });

                if (filled($connectionId)) {
                    $q->where('connection_id', $connectionId);
                }
            })
            // Newest first, by id rather than by date: it is the same order and
            // it is the key the rows are already stored in.
            ->orderBy('id', 'DESC')
            ->limit($limit);

        MessageSearch::apply($query, $terms);

        return response()->json([
            'data' => MessageResource::collection($query->get()),
            'min_term_length' => MessageSearch::MIN_TERM_LENGTH,
        ]);
    }
}

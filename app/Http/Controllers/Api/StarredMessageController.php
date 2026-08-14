<?php

namespace App\Http\Controllers\Api;

use App\Events\MessageUpdated;
use App\Http\Controllers\Controller;
use App\Http\Resources\ConversationResource;
use App\Http\Resources\MessageResource;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Starred messages: the workspace's bookmarks.
 *
 * The star lives on the message rather than on a per-agent pivot, for the same
 * reason mute lives on the conversation — message events ride a shared
 * per-connection channel, so a per-user flag would travel to every subscriber
 * regardless. See the migration for the full note.
 *
 * Reads go through `Conversation::visibleTo()` like every other message path:
 * an agent must not be handed a bookmark from an inbox they were never given.
 * Starring itself is deliberately *not* gated on `isAccessibleBy()` — that one
 * governs acting *in* a thread (sending, resolving), while starring only marks
 * something already on screen, and the threads worth bookmarking are often
 * unassigned ones sitting in the queue.
 */
class StarredMessageController extends Controller
{
    /**
     * Every starred message this user can reach, newest star first, each with
     * the thread it belongs to — the list renders real message cards, so it
     * needs everything MessageResource touches plus the conversation for the
     * contact's name and for opening the thread on click.
     */
    public function index(Request $request): JsonResponse
    {
        $limit = max(1, min((int) $request->input('limit', 100), 200));

        $messages = Message::with([
            'repliedMessage',
            'reactions.contact',
            'contact',
            'sentByUser',
            'sentByFlow',
            'sentByAiHubAgent',
            'conversation.contact',
            'conversation.connection',
            'conversation.agent',
            'conversation.tags',
        ])
            ->whereNotNull('starred_at')
            ->whereHas('conversation', fn ($query) => $query->visibleTo(Auth::user()))
            ->orderByDesc('starred_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        return response()->json([
            'data' => $messages->map(fn (Message $message) => [
                'message' => new MessageResource($message),
                'conversation' => new ConversationResource($message->conversation),
            ]),
        ]);
    }

    public function store(int $id, int $message_id): JsonResponse
    {
        return $this->setStarred($id, $message_id, now());
    }

    public function destroy(int $id, int $message_id): JsonResponse
    {
        return $this->setStarred($id, $message_id, null);
    }

    /**
     * Re-starring an already-starred message keeps its original timestamp: the
     * list is ordered by it, and a second click from another tab should not
     * quietly reorder the list under everyone.
     */
    private function setStarred(int $id, int $message_id, ?\Illuminate\Support\Carbon $starredAt): JsonResponse
    {
        $conversation = Conversation::visibleTo(Auth::user())->findOrFail($id);
        $message = $conversation->messages()->where('id', $message_id)->firstOrFail();

        $alreadyInTargetState = $starredAt !== null
            ? $message->starred_at !== null
            : $message->starred_at === null;

        if (! $alreadyInTargetState) {
            $message->update(['starred_at' => $starredAt]);

            // Every open panel keeps its own copy of the message in IndexedDB;
            // this is what turns the star on there too.
            broadcast(new MessageUpdated($message));
        }

        return response()->json([
            'data' => new MessageResource($message),
        ]);
    }
}

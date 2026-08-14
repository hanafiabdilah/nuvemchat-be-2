<?php

namespace App\Http\Controllers\Api;

use App\Events\ConversationUpdated;
use App\Http\Controllers\Controller;
use App\Http\Resources\ConversationNoteResource;
use App\Models\Conversation;
use App\Models\ConversationNote;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Internal notes on one conversation.
 *
 * Reads and writes are gated on `Conversation::visibleTo()` — the connection
 * boundary every conversation path respects — but deliberately not on
 * `isAccessibleBy()`: that governs acting *in* a thread (sending, resolving),
 * while a note is what an agent leaves *about* one, and the threads most worth
 * annotating are often the unassigned ones still sitting in the queue.
 *
 * Editing and deleting are narrower: the author, or an owner. See
 * ConversationNote::isEditableBy().
 */
class ConversationNoteController extends Controller
{
    /** Newest first — a note written now is the one being looked for. */
    public function index(int $id): JsonResponse
    {
        $conversation = $this->findConversation($id);

        return response()->json([
            'data' => ConversationNoteResource::collection(
                $conversation->notes()->with('user')->latest()->latest('id')->get()
            ),
        ]);
    }

    public function store(Request $request, int $id): JsonResponse
    {
        $conversation = $this->findConversation($id);

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
        ]);

        $note = $conversation->notes()->create([
            'user_id' => Auth::id(),
            'body' => trim($validated['body']),
        ]);

        $this->announce($conversation);

        return response()->json([
            'data' => new ConversationNoteResource($note->load('user')),
        ], 201);
    }

    public function update(Request $request, int $id, int $note_id): JsonResponse
    {
        $note = $this->findNote($id, $note_id);

        if (! $note->isEditableBy(Auth::user())) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
        ]);

        $note->update(['body' => trim($validated['body'])]);

        return response()->json([
            'data' => new ConversationNoteResource($note->load('user')),
        ]);
    }

    public function destroy(int $id, int $note_id): JsonResponse
    {
        $note = $this->findNote($id, $note_id);

        if (! $note->isEditableBy(Auth::user())) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $conversation = $note->conversation;

        $note->delete();

        $this->announce($conversation);

        return response()->json(['message' => 'Note deleted']);
    }

    /**
     * Tell every open panel the thread's note count moved, so the marker on the
     * conversation row and in the chat header appears (or clears) for the other
     * agents too — not just for whoever wrote it. Editing a note is left out:
     * the body changed, the count did not, and the panel that shows bodies
     * re-reads them when it opens.
     */
    private function announce(Conversation $conversation): void
    {
        broadcast(new ConversationUpdated($conversation->loadCount('notes')->load('contact')));
    }

    private function findConversation(int $id): Conversation
    {
        return Conversation::visibleTo(Auth::user())->findOrFail($id);
    }

    /** Scoped through the conversation, so a note id alone reaches nothing. */
    private function findNote(int $id, int $noteId): ConversationNote
    {
        return $this->findConversation($id)->notes()->where('id', $noteId)->firstOrFail();
    }
}

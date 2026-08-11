<?php

namespace App\Http\Controllers\Api;

use App\Events\ConversationUpdated;
use App\Events\GroupRemoved;
use App\Http\Controllers\Controller;
use App\Http\Resources\ContactResource;
use App\Models\Contact;
use App\Models\Conversation;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/**
 * Removing a group takes its inbound messages out of the inbox for good — the
 * webhook handlers drop them at ingest — without deleting anything: the group's
 * contact stays, keeps its name and photo in sync, and its past threads are
 * still on disk. Restoring it puts both back.
 */
class GroupController extends Controller
{
    /** The removed-groups list itself: every group flagged for this tenant. */
    public function removed(): JsonResponse
    {
        $groups = Contact::where('tenant_id', Auth::user()->tenant_id)
            ->where('is_group', true)
            ->whereNotNull('group_removed_at')
            ->orderByDesc('group_removed_at')
            ->get();

        return response()->json([
            'data' => $groups->map(fn (Contact $group) => array_merge(
                (new ContactResource($group))->resolve(),
                ['removed_at' => $group->group_removed_at?->timestamp],
            )),
        ]);
    }

    /**
     * Stop this group's messages from reaching the inbox and take its open
     * threads off every panel. Nothing is deleted — see restore().
     */
    public function remove(int $id): JsonResponse
    {
        $group = $this->findGroup($id);

        $group->update(['group_removed_at' => now()]);

        $conversationIds = Conversation::where('contact_id', $group->id)->pluck('id')->all();

        // Delta sync only ever adds rows, so an open panel would keep showing
        // the thread until a reload — this is what tells it to drop them.
        broadcast(new GroupRemoved($group->tenant_id, $group->id, $conversationIds));

        return response()->json([
            'message' => 'Group removed',
            'data' => new ContactResource($group->fresh()),
        ]);
    }

    /** Let the group back in; its earlier threads come back with it. */
    public function restore(int $id): JsonResponse
    {
        $group = $this->findGroup($id);

        $group->update(['group_removed_at' => null]);

        // Touch and re-broadcast so the threads reappear immediately, and are
        // picked up again by the next delta sync (which filters on updated_at).
        Conversation::where('contact_id', $group->id)
            ->with('contact')
            ->get()
            ->each(function (Conversation $conversation) {
                $conversation->touch();
                broadcast(new ConversationUpdated($conversation));
            });

        return response()->json([
            'message' => 'Group restored',
            'data' => new ContactResource($group->fresh()),
        ]);
    }

    /** Tenant-scoped, and only ever a group — never a person's contact row. */
    private function findGroup(int $id): Contact
    {
        return Contact::where('tenant_id', Auth::user()->tenant_id)
            ->where('is_group', true)
            ->findOrFail($id);
    }
}

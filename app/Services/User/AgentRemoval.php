<?php

namespace App\Services\User;

use App\Models\Broadcast;
use App\Models\Conversation;
use App\Models\InstagramPost;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Removing a person from a workspace without removing what they worked on.
 *
 * Deleting the user row used to be the whole of it, and the schema did the
 * rest: `conversations.user_id` is ON DELETE CASCADE (and so is
 * `messages.conversation_id`), so every conversation the person had ever been
 * assigned — resolved ones included — vanished with all its messages, and
 * `broadcasts.created_by` / `instagram_posts.created_by` took their campaigns
 * and scheduled posts with them. Rebuilding those foreign keys on tables this
 * size is not an online operation, so the rows are let go of here, in code,
 * before the user row is deleted:
 *
 *  - open conversations move to the person chosen (when they can reach that
 *    conversation's connection) or back to the queue — the customer is
 *    mid-conversation and someone has to pick it up;
 *  - everything else they were assigned stays, unassigned — history;
 *  - campaigns and Instagram posts keep existing with no author.
 *
 * Left to the database on purpose, because the database already does the
 * right thing: `messages.sent_by_user_id`, `conversations.resolved_by_user_id`,
 * conversation notes, gallery uploads and flow assistant turns are all ON
 * DELETE SET NULL. Personal quick messages
 * (`quick_messages.user_id`, cascade) go with the person: nobody else could
 * ever see them, so no one loses anything they had.
 */
class AgentRemoval
{
    public function __construct(
        private readonly AvatarStorage $avatars,
        private readonly OpenConversationHandover $handover,
    ) {}

    /**
     * @return array{transferred: int, returned_to_queue: int, kept_in_history: int}
     */
    public function remove(User $agent, User $actor, ?User $reassignTo = null): array
    {
        $summary = ['transferred' => 0, 'returned_to_queue' => 0, 'kept_in_history' => 0];
        $moved = [];

        DB::transaction(function () use ($agent, $actor, $reassignTo, &$summary, &$moved) {
            // Holding the row makes any write that would point at this person
            // wait for the commit and then fail its foreign key, instead of
            // landing between the moves below and the delete and being
            // cascaded away with it.
            User::query()->whereKey($agent->id)->lockForUpdate()->first();

            $handed = $this->handover->handOver($agent, $actor, $reassignTo, 'agent_removed');
            $summary['transferred'] = $handed['transferred'];
            $summary['returned_to_queue'] = $handed['returned_to_queue'];
            $moved = $handed['ids'];

            // Through the query builder, so neither `updated_at` nor the
            // observer fires: a closed thread changes hands with nobody. The
            // timestamp is the dashboards' delta-sync cursor, and bumping years
            // of history would push all of it to every open tab for a change
            // no one can see.
            $summary['kept_in_history'] = Conversation::query()
                ->where('user_id', $agent->id)
                ->toBase()
                ->update(['user_id' => null]);

            Broadcast::query()->where('created_by', $agent->id)->toBase()->update(['created_by' => null]);
            InstagramPost::query()->where('created_by', $agent->id)->toBase()->update(['created_by' => null]);

            // Access ends now, not when their token next expires.
            $agent->tokens()->delete();

            $agent->delete();
        });

        // Outside the transaction: a file deleted for a rollback cannot come
        // back, and nothing else ever revisits the avatar directory.
        $this->avatars->forget($agent);

        $this->handover->broadcast($moved);

        return $summary;
    }
}

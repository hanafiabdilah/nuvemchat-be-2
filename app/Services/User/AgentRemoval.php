<?php

namespace App\Services\User;

use App\Enums\Connection\Channel;
use App\Enums\Conversation\Status;
use App\Events\ConversationUpdated;
use App\Http\Controllers\Api\ConversationController;
use App\Models\Broadcast;
use App\Models\Conversation;
use App\Models\InstagramPost;
use App\Models\User;
use App\Services\Conversation\SystemMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
 * lead owners and stage events, conversation notes, gallery uploads and flow
 * assistant turns are all ON DELETE SET NULL. Personal quick messages
 * (`quick_messages.user_id`, cascade) go with the person: nobody else could
 * ever see them, so no one loses anything they had.
 */
class AgentRemoval
{
    public function __construct(private readonly AvatarStorage $avatars) {}

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

            $open = Conversation::query()
                ->with('connection')
                ->where('user_id', $agent->id)
                ->where('status', '!=', Status::Resolved->value)
                ->orderBy('id')
                ->get();

            foreach ($open as $conversation) {
                $summary[$this->move($conversation, $actor, $reassignTo)]++;
                $moved[] = $conversation->id;
            }

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

        Conversation::query()
            ->with(['contact', 'agent'])
            ->whereIn('id', $moved)
            ->get()
            ->each(fn (Conversation $conversation) => broadcast(new ConversationUpdated($conversation)));

        return $summary;
    }

    /**
     * Where one open conversation goes. Returns the summary bucket it lands in.
     */
    private function move(Conversation $conversation, User $actor, ?User $reassignTo): string
    {
        // NB: `$conversation->connection` is Eloquent's DB connection name.
        $connection = $conversation->getRelationValue('connection');
        $previous = $conversation->user_id;

        // E-mail is a shared inbox: nobody owns a thread there, so the only
        // thing to undo is the name on it.
        if ($connection?->channel === Channel::Email) {
            $conversation->user_id = null;
            $conversation->save();

            return 'returned_to_queue';
        }

        // Only to someone who can reach it: a thread assigned to a person whose
        // inbox never shows it is stranded, not transferred.
        if ($reassignTo && $reassignTo->canAccessConnection($connection)) {
            $conversation->user_id = $reassignTo->id;
            $conversation->needs_human = false;
            $conversation->save();

            // The same note a transfer from the conversation writes, credited to
            // whoever removed the person — they are the one who moved it.
            SystemMessage::info(
                $conversation,
                "{$actor->name} transferred this conversation to {$reassignTo->name}.",
                ConversationController::INFO_TRANSFERRED,
                ['from' => $actor->name, 'to' => $reassignTo->name],
            );

            $this->log('transferred', $conversation, $actor, $previous, $reassignTo->id);

            return 'transferred';
        }

        // Back to the queue. An Active thread becomes Pending so every agent's
        // queue shows it; ConversationObserver writes the usual status note,
        // with the signed-in person as the one who changed it. The flow does
        // not restart — it was stopped when the thread was accepted, and only
        // a running flow resumes.
        $conversation->user_id = null;

        if ($conversation->status === Status::Active) {
            $conversation->status = Status::Pending;
        }

        $conversation->save();

        $this->log('returned to the queue', $conversation, $actor, $previous, null);

        return 'returned_to_queue';
    }

    /** Same shape as ConversationController::logAssignmentChange(), plus why. */
    private function log(string $action, Conversation $conversation, User $actor, ?int $from, ?int $to): void
    {
        Log::info("Conversation {$action}", [
            'conversation_id' => $conversation->id,
            'connection_id' => $conversation->connection_id,
            'tenant_id' => $conversation->getRelationValue('connection')?->tenant_id,
            'actor_id' => $actor->id,
            'from_user_id' => $from,
            'to_user_id' => $to,
            'reason' => 'agent_removed',
        ]);
    }
}

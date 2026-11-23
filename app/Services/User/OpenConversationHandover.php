<?php

namespace App\Services\User;

use App\Enums\Connection\Channel;
use App\Enums\Conversation\Status;
use App\Events\ConversationUpdated;
use App\Http\Controllers\Api\ConversationController;
use App\Models\Conversation;
use App\Models\User;
use App\Services\Conversation\SystemMessage;
use Illuminate\Support\Facades\Log;

/**
 * Where a leaving person's open conversations go: to the person chosen (when
 * they can reach that conversation's connection) or back to the queue. The
 * customer is mid-conversation and someone has to pick it up.
 *
 * Shared by deleting an agent (AgentRemoval) and deactivating one
 * (AgentDeactivation): the two differ in what happens to the person and to
 * their history, never in what happens to the customers they were answering.
 */
class OpenConversationHandover
{
    /**
     * Move every unresolved conversation assigned to `$agent`. Call inside the
     * caller's transaction, then broadcast() the returned ids after it commits.
     *
     * @return array{transferred: int, returned_to_queue: int, ids: list<int>}
     */
    public function handOver(User $agent, User $actor, ?User $reassignTo, string $reason): array
    {
        $summary = ['transferred' => 0, 'returned_to_queue' => 0, 'ids' => []];

        $open = Conversation::query()
            ->with('connection')
            ->where('user_id', $agent->id)
            ->where('status', '!=', Status::Resolved->value)
            ->orderBy('id')
            ->get();

        foreach ($open as $conversation) {
            $summary[$this->move($conversation, $actor, $reassignTo, $reason)]++;
            $summary['ids'][] = $conversation->id;
        }

        return $summary;
    }

    /**
     * Tell every open dashboard where the moved conversations went.
     *
     * @param  list<int>  $ids
     */
    public function broadcast(array $ids): void
    {
        if ($ids === []) {
            return;
        }

        Conversation::query()
            ->with(['contact', 'agent'])
            ->whereIn('id', $ids)
            ->get()
            ->each(fn (Conversation $conversation) => broadcast(new ConversationUpdated($conversation)));
    }

    /**
     * Where one open conversation goes. Returns the summary bucket it lands in.
     */
    private function move(Conversation $conversation, User $actor, ?User $reassignTo, string $reason): string
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

            $this->log('transferred', $conversation, $actor, $previous, $reassignTo->id, $reason);

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

        $this->log('returned to the queue', $conversation, $actor, $previous, null, $reason);

        return 'returned_to_queue';
    }

    /** Same shape as ConversationController::logAssignmentChange(), plus why. */
    private function log(string $action, Conversation $conversation, User $actor, ?int $from, ?int $to, string $reason): void
    {
        Log::info("Conversation {$action}", [
            'conversation_id' => $conversation->id,
            'connection_id' => $conversation->connection_id,
            'tenant_id' => $conversation->getRelationValue('connection')?->tenant_id,
            'actor_id' => $actor->id,
            'from_user_id' => $from,
            'to_user_id' => $to,
            'reason' => $reason,
        ]);
    }
}

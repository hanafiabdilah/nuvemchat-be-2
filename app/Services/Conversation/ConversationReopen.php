<?php

namespace App\Services\Conversation;

use App\Enums\Connection\Channel;
use App\Enums\Conversation\Status;
use App\Events\ConversationTakenOver;
use App\Events\ConversationUpdated;
use App\Http\Controllers\Api\ConversationController;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\User;
use App\Observers\ConversationObserver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Open a closed conversation again, by hand.
 *
 * The automatic half of this already existed: a contact who writes again a
 * minute after being closed is sent back to the agent who was helping them
 * (see LastAgentRouter). What was missing is the case where the *agent* is the
 * one who notices — they closed the thread too early, or the customer said one
 * more thing on the phone — and had no way back in. Their only options were to
 * wait for the customer to write or to start a conversation from scratch, which
 * loses the thread the answer belongs to.
 *
 * So this is the same feature seen from the other side, and it deliberately
 * borrows the same gate: **the connection's "return to the last agent" switch
 * and its tolerance**. One rule decides how long a closed conversation is still
 * "the same visit", and both the customer's message and the agent's button obey
 * it. A workspace that turned the routing off is saying closed means closed,
 * and a button that ignored that would be a second, contradictory answer to a
 * question the connection has already answered.
 *
 * What it is not: a way to reopen last month's thread. Past the tolerance the
 * button is refused and the honest move is a fresh conversation, which is what
 * the channel would have produced anyway.
 *
 * ⚠️ The SPA mirrors the window rule in `lib/reopenWindow.ts` so it can draw
 * the button enabled or disabled without asking the server. The two have to
 * stay in step — but this file is the one that decides: the endpoint re-checks
 * everything under a lock, because the window can run out (and a colleague can
 * open a thread with the same contact) between the render and the click.
 */
class ConversationReopen
{
    /**
     * Code for the note left in the thread. Always written, even when the same
     * agent reopens their own conversation: a thread that was closed and is
     * open again with no explanation reads like the resolution never happened.
     */
    public const INFO_REOPENED = 'conversation_reopened';

    /** The connection never switched the feature on. Closed means closed here. */
    public const BLOCKED_DISABLED = 'return_to_last_agent_disabled';

    /** Groups have no assignee and e-mail is a shared inbox — nothing to return to. */
    public const BLOCKED_CHANNEL = 'channel_not_supported';

    /** Already open — a colleague got there first, or this is a stale screen. */
    public const BLOCKED_NOT_RESOLVED = 'conversation_not_resolved';

    /** Closed longer ago than the connection's tolerance. */
    public const BLOCKED_EXPIRED = 'reopen_window_expired';

    /**
     * This contact already has a live thread on this connection — usually
     * because they wrote back and the routing opened one. Reopening would give
     * one person two inboxes and split the next answer between them.
     */
    public const BLOCKED_ALREADY_OPEN = 'conversation_already_open';

    /**
     * Whether this thread may be reopened right now, and until when.
     *
     * @param  bool  $includeOpenThread  Skip the "is another thread already open"
     *                                   query on paths that only need the window —
     *                                   it is one query per conversation and the
     *                                   endpoint re-runs it under the lock anyway.
     */
    public static function check(Conversation $conversation, bool $includeOpenThread = true): ReopenCheck
    {
        $connection = $conversation->connection;

        if (! $connection instanceof Connection || ! $connection->return_to_last_agent) {
            return ReopenCheck::refuse(self::BLOCKED_DISABLED);
        }

        // Same exclusions as the automatic routing: a group is not "a contact
        // who came back" and nobody owns it, and e-mail is a shared inbox that
        // never had an assignee to hand the thread back to.
        if ($conversation->isGroup() || $connection->channel === Channel::Email) {
            return ReopenCheck::refuse(self::BLOCKED_CHANNEL);
        }

        if ($conversation->status !== Status::Resolved) {
            return ReopenCheck::refuse(self::BLOCKED_NOT_RESOLVED);
        }

        $deadline = self::deadline($conversation, $connection);

        if ($deadline === null || $deadline->isPast()) {
            return ReopenCheck::refuse(self::BLOCKED_EXPIRED, $deadline);
        }

        if ($includeOpenThread && ($open = self::openThreadFor($conversation))) {
            return ReopenCheck::refuse(self::BLOCKED_ALREADY_OPEN, $deadline, $open);
        }

        return ReopenCheck::allow($deadline);
    }

    /**
     * Reopen the thread and hand it to whoever asked.
     *
     * Returns the same check the button drew itself from — `allowed` false
     * means nothing was written and `reason` says what the endpoint should
     * answer. Everything is re-read under a lock: the render that enabled the
     * button is at least one round trip old, and in that time the window can
     * have run out or a colleague can have opened a thread with this contact.
     */
    public static function reopen(Conversation $conversation, User $actor): ReopenCheck
    {
        // One reopen at a time per contact+connection. The row lock below only
        // protects *this* thread; two agents reopening two different closed
        // threads of the same contact would each find no open thread and both
        // win. This is the only shared thing between them.
        $lock = Cache::lock("conversation-reopen:{$conversation->connection_id}:{$conversation->contact_id}", 10);

        if (! $lock->get()) {
            return ReopenCheck::refuse(self::BLOCKED_ALREADY_OPEN);
        }

        // Captured inside the transaction, from the row as it stood before the
        // new assignee was written: after the save there is no "before" left to
        // read, and the caller's own instance may already be stale.
        $previousAgentId = null;

        try {
            $check = DB::transaction(function () use ($conversation, $actor, &$previousAgentId) {
                $locked = Conversation::query()
                    ->with('connection')
                    ->lockForUpdate()
                    ->find($conversation->id);

                if (! $locked) {
                    return ReopenCheck::refuse(self::BLOCKED_NOT_RESOLVED);
                }

                $check = self::check($locked);

                if (! $check->allowed) {
                    return $check;
                }

                $previousAgentId = $locked->user_id === null ? null : (int) $locked->user_id;

                $locked->status = Status::Active;
                $locked->user_id = $actor->id;
                // Assigned by definition: nothing is waiting to be picked up.
                $locked->needs_human = false;
                // Cleared, not kept. `resolved_at` is what statistics read as
                // "this conversation was closed at", and a thread that is open
                // again was not. The trade is real — a reopen removes the old
                // closure from the period it happened in — but a row that is
                // Active while claiming a resolution time is a worse lie, and
                // markResolved() stamps a fresh one the next time it closes.
                $locked->resolved_at = null;
                $locked->resolved_by_user_id = null;

                // The automatic "resolved → active" note is suppressed because
                // the note written below says more: it names who reopened it,
                // which the transition only implies. Two notes for one click is
                // noise — the same reason accept() suppresses it.
                ConversationObserver::withoutStatusNote(fn () => $locked->save());

                return ReopenCheck::allow($check->deadline);
            });
        } finally {
            $lock->release();
        }

        if (! $check->allowed) {
            return $check;
        }

        // The caller's instance is now behind the row that was just written.
        $conversation->refresh()->load(['connection', 'contact', 'agent']);

        $previousAgent = self::previousAgent($previousAgentId, $actor);

        SystemMessage::info(
            $conversation,
            "{$actor->name} reopened this conversation.",
            self::INFO_REOPENED,
            ['by' => $actor->name],
        );

        // A second note, and only when somebody actually lost the thread. The
        // reopen note already names the agent who now holds it, so repeating
        // "X took this conversation" under it would say nothing new — unless
        // the thread was another agent's, which is the one fact the person who
        // closed it needs to find when they come back to it.
        if ($previousAgent) {
            SystemMessage::info(
                $conversation,
                "{$actor->name} took over this conversation from {$previousAgent->name}.",
                ConversationController::INFO_TAKEN_OVER,
                ['from' => $previousAgent->name, 'to' => $actor->name],
            );
        }

        broadcast(new ConversationUpdated($conversation));

        // Same event the Take over button fires, for the same reader: the agent
        // who is no longer holding this thread.
        if ($previousAgent) {
            broadcast(new ConversationTakenOver($conversation, $previousAgent, $actor));
        }

        Log::info('Conversation reopened', [
            'conversation_id' => $conversation->id,
            'connection_id' => $conversation->connection_id,
            'tenant_id' => $conversation->connection?->tenant_id,
            // Ids, not names: names change and only the id joins back.
            'actor_id' => $actor->id,
            'from_user_id' => $previousAgent?->id,
        ]);

        return $check;
    }

    /**
     * The agent this thread is being taken from, or null when it is nobody's
     * loss — it was unassigned, or it is the same person reopening their own.
     */
    private static function previousAgent(?int $previousId, User $actor): ?User
    {
        if ($previousId === null || (int) $previousId === (int) $actor->id) {
            return null;
        }

        return User::find($previousId);
    }

    /** The moment the reopen window closes, or null when there is nothing to measure from. */
    public static function deadline(Conversation $conversation, ?Connection $connection = null): ?Carbon
    {
        $connection ??= $conversation->connection;

        if (! $connection instanceof Connection || ! $connection->return_to_last_agent) {
            return null;
        }

        return self::closedAt($conversation)?->copy()->addMinutes($connection->returnToLastAgentMinutes());
    }

    /**
     * When the conversation ended.
     *
     * `resolved_at` is the honest answer but only exists for threads closed
     * after it was added, so the last message is the fallback: for a thread
     * that is over, "when did anyone last say anything" is the same instant
     * within seconds. `updated_at` is the last resort and the least trustworthy
     * — tagging or muting a closed thread moves it — but it can only ever make
     * the window look *more* recent, and the worst case is one conversation
     * being reopenable for a few minutes longer than it should have been.
     *
     * Shared with LastAgentRouter so the agent's button and the customer's
     * message measure the same window from the same instant.
     */
    public static function closedAt(Conversation $conversation): ?Carbon
    {
        return $conversation->resolved_at
            ?? $conversation->last_message_at
            ?? $conversation->updated_at;
    }

    /**
     * Another live thread with this contact on this connection, if there is one.
     *
     * This is the guarantee that one contact never has two inboxes open at
     * once: every other path already refuses (the webhook handlers reuse an
     * open thread, POST /conversations answers 409, the outbound resolver
     * continues the existing one), and reopening was the one door left.
     */
    public static function openThreadFor(Conversation $conversation): ?Conversation
    {
        return Conversation::query()
            ->where('connection_id', $conversation->connection_id)
            ->where('contact_id', $conversation->contact_id)
            ->where('id', '!=', $conversation->id)
            ->whereIn('status', OutboundConversationResolver::OPEN_STATUSES)
            ->latest('id')
            ->first();
    }
}

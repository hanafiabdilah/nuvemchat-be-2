<?php

namespace App\Services\Conversation;

use App\Enums\Connection\Channel;
use App\Enums\Conversation\Status;
use App\Events\ConversationUpdated;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Send a contact who comes straight back to the agent who was just helping
 * them, instead of through the chatbot and the unassigned queue.
 *
 * An inbound message never re-opens a resolved conversation — the chat handlers
 * start a fresh one — which is correct for the record and wrong for the person:
 * someone who remembers one more question thirty seconds after the thread was
 * closed is not a new customer, but every channel treats them as one. They get
 * the greeting again, answer the menu again, and land at the back of a queue
 * that the agent who already has their context is not necessarily watching.
 *
 * So this runs at exactly one moment — a brand-new thread, before the flow gets
 * a chance to start — and answers one question: is this the same visit? Three
 * things have to hold, and each one failing means the normal path is the better
 * path, not that something went wrong:
 *
 *   - the contact's last *served* conversation closed within the tolerance;
 *   - the agent who served it can still reach this connection;
 *   - that agent is online right now.
 *
 * The last one is what stops this from being worse than the bot: an unattended
 * assignment is a customer sitting in someone's inbox with no one coming, while
 * the queue they would otherwise have joined is watched by everyone.
 *
 * The customer is sent nothing. The connection's accept message is a greeting,
 * and a greeting is exactly the wrong thing to say to someone you were talking
 * to a minute ago — the same reason take-over stays silent.
 */
class LastAgentRouter
{
    /** Code the SPA translates for the note this leaves in the thread. */
    public const INFO_RETURNED = 'conversation_returned_to_agent';

    /**
     * Route a freshly created conversation back to its contact's last agent.
     *
     * @return bool true when the thread was assigned — the caller must then
     *              skip the flow, since the point is not to run the bot.
     */
    public static function route(Conversation $conversation): bool
    {
        $connection = $conversation->connection;

        if (! $connection instanceof Connection || ! $connection->return_to_last_agent) {
            return false;
        }

        // A group is not "a contact who came back" — nobody owns it and the
        // sender changes message to message. E-mail is a shared inbox that
        // never assigns anyone, so there is no last agent to return to.
        if ($conversation->isGroup() || $connection->channel === Channel::Email) {
            return false;
        }

        // Only ever the opening move of a new thread. Anything already assigned
        // or already being handled is somewhere on purpose.
        if ($conversation->user_id !== null || $conversation->status !== Status::Pending) {
            return false;
        }

        $previous = self::lastServedConversation($conversation);

        if (! $previous) {
            return false;
        }

        // The same window the Reopen button obeys, measured from the same
        // instant — see ConversationReopen. One rule decides how long a closed
        // conversation is still "the same visit"; the customer's message and
        // the agent's button are two doors into it.
        $minutes = $connection->returnToLastAgentMinutes();
        $deadline = ConversationReopen::deadline($previous, $connection);

        if ($deadline === null || $deadline->isPast()) {
            return false;
        }

        $agent = $previous->agent;

        if (! self::agentIsAvailable($agent, $connection)) {
            return false;
        }

        $conversation->user_id = $agent->id;
        $conversation->status = Status::Active;
        // Assigned by definition: nothing is waiting to be picked up.
        $conversation->needs_human = false;
        $conversation->save();

        // Written before the broadcast so the inbox row lands on its final
        // preview instead of flickering through the one it had a moment ago —
        // the same ordering transfer and take-over use.
        SystemMessage::info(
            $conversation,
            "Reopened with {$agent->name}, who last spoke with this contact.",
            self::INFO_RETURNED,
            ['agent' => $agent->name],
        );

        broadcast(new ConversationUpdated($conversation->load('contact')));

        Log::info('Conversation returned to last agent', [
            'conversation_id' => $conversation->id,
            'previous_conversation_id' => $previous->id,
            'connection_id' => $connection->id,
            'tenant_id' => $connection->tenant_id,
            'agent_id' => $agent->id,
            // Ids, not names: names change and only the id joins back.
            'minutes_since_close' => (int) ConversationReopen::closedAt($previous)?->diffInMinutes(now()),
            'tolerance_minutes' => $minutes,
        ]);

        return true;
    }

    /**
     * The contact's most recent conversation on this connection that an agent
     * actually handled.
     *
     * Skipping past bot-only threads is deliberate. If the last visit was
     * answered entirely by the flow there is nobody to return to, but a visit
     * before it may still have an agent — and that agent is the one the
     * customer means by "the person I was talking to". Whether it was recent
     * enough is a separate question, answered by the tolerance below; picking
     * the newest served thread and then checking its age is what keeps the two
     * from being conflated.
     */
    private static function lastServedConversation(Conversation $conversation): ?Conversation
    {
        return Conversation::query()
            ->where('connection_id', $conversation->connection_id)
            ->where('contact_id', $conversation->contact_id)
            ->where('id', '!=', $conversation->id)
            ->whereNotNull('user_id')
            ->with('agent')
            ->orderByDesc('id')
            ->first();
    }

    private static function agentIsAvailable(?User $agent, Connection $connection): bool
    {
        if (! $agent) {
            return false;
        }

        // Access can be revoked between visits; an assignment the agent cannot
        // open is a thread that disappears from everyone's inbox.
        if ((int) $agent->tenant_id !== (int) $connection->tenant_id
            || ! $agent->canAccessConnection($connection->id)) {
            return false;
        }

        return $agent->isOnline();
    }
}

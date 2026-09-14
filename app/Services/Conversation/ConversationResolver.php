<?php

namespace App\Services\Conversation;

use App\Events\ConversationUpdated;
use App\Events\MessageReceived;
use App\Models\Conversation;
use App\Models\User;
use App\Services\AutomatedMessageService;
use App\Services\Message\MessageService;
use Illuminate\Support\Facades\Log;

/**
 * What "resolve" means, in one place: send the connection's closing message,
 * close the thread on behalf of someone, and tell every open dashboard.
 *
 * Lifted out of ConversationController so an inbox send that resolves each
 * thread after writing to it goes through exactly the Resolve button's path.
 * A customer should not be able to tell a chat closed from the bulk bar from
 * one closed by hand — same closing message, same record of who closed it.
 *
 * Eligibility (Active, accessible by the actor) is the caller's to check: the
 * button and the queued send answer it at different moments.
 */
class ConversationResolver
{
    public function __construct(
        private AutomatedMessageService $automatedMessages,
        private MessageService $messages,
    ) {}

    public function resolve(Conversation $conversation, User $actor): void
    {
        $closingMessage = $this->automatedMessages->getClosingMessage(
            $conversation->getRelationValue('connection'),
            $actor,
        );

        $closingMsg = null;

        if ($closingMessage) {
            try {
                $closingMsg = $this->messages->sendMessage($conversation, ['message' => $closingMessage]);
                $closingMsg?->update(['sent_by_user_id' => $actor->id]);
            } catch (\Throwable $th) {
                Log::error('ConversationResolver: Failed to send closing message', [
                    'conversation_id' => $conversation->id,
                    'error' => $th->getMessage(),
                ]);
            }
        }

        $conversation->markResolved($actor->id);

        broadcast(new ConversationUpdated($conversation));

        // Broadcast closing message AFTER conversation status update
        if ($closingMsg) {
            broadcast(new MessageReceived($closingMsg));
            broadcast(new ConversationUpdated($closingMsg->conversation));
        }
    }
}

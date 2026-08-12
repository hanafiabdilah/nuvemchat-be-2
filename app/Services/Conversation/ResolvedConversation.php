<?php

namespace App\Services\Conversation;

use App\Models\Conversation;

/**
 * What OutboundConversationResolver hands back.
 *
 * `wasCreated` matters to callers that have to undo themselves when the send
 * that follows fails: deleting a thread we just opened is a cleanup, deleting
 * one that already existed destroys history.
 */
class ResolvedConversation
{
    public function __construct(
        public readonly Conversation $conversation,
        public readonly bool $wasCreated,
    ) {}
}

<?php

namespace App\Jobs;

use App\Models\Conversation;
use App\Models\Message;
use App\Services\Message\MessageService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Tell the channel that a thread has been read.
 *
 * Queued rather than inline because reading is not a request the agent waits
 * for: GET /conversations/{id}/read fires the moment a thread is opened and
 * again on every scroll to the bottom, and hanging a Graph call (or an IMAP
 * login) off each of those puts a third party's latency in front of a click
 * that should be instant.
 *
 * One try. MessageService::markAsRead() swallows and logs whatever the channel
 * does, so a retry would only repeat a call that already reported itself; and a
 * receipt is worth nothing by the time a backoff has elapsed — the next thread
 * the agent opens will send a fresher one anyway.
 */
class SendReadReceipt implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    /**
     * @param  array<int, int>  $messageIds  The inbound messages that just
     *         flipped to read. Ids rather than models: by the time this runs the
     *         rows may have been edited or deleted, and the handler wants what
     *         is true now, not a snapshot of the request.
     */
    public function __construct(
        public Conversation $conversation,
        public array $messageIds,
    ) {}

    public function handle(): void
    {
        // Re-asked here rather than trusted from the dispatch: a connection can
        // be deleted or swapped while this sits in the queue, and a channel that
        // is gone has nobody left to tell.
        if (! $this->conversation->connection?->channel->supportsReadReceipt()) {
            return;
        }

        $messages = Message::whereIn('id', $this->messageIds)
            ->orderBy('id')
            ->get();

        if ($messages->isEmpty()) {
            return;
        }

        (new MessageService())->markAsRead($this->conversation, $messages);
    }
}

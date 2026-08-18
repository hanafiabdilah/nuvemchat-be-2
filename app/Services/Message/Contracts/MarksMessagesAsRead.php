<?php

namespace App\Services\Message\Contracts;

use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Support\Collection;

/**
 * A send handler whose channel can be told that we have read the customer.
 *
 * Marking a thread read in the panel only ever moved a badge on our own side:
 * the person who wrote the message is looking at WhatsApp, not at us, and there
 * the message stayed on one grey tick forever. This is the other half — the
 * ticks turn blue, "Visto" appears under the Instagram bubble, the mail stops
 * being bold in the mailbox — and it is a capability interface for the same
 * reason as SendsTypingIndicator: three channels genuinely cannot do it (a
 * Telegram bot has no read API at all, a Discord bot may not ack, TikTok
 * publishes read events but accepts none), and empty methods claiming
 * otherwise would read as an oversight rather than as the fact they are.
 *
 * Callers go through MessageService::markAsRead(), which checks for this
 * interface; Channel::supportsReadReceipt() is the same answer asked of the
 * channel rather than of the handler, so a caller can skip the work entirely.
 *
 * Every implementation is **best-effort and silent**. A receipt is a courtesy
 * to the person on the other side: it must never block, delay, or fail the act
 * of reading a thread, which is why the whole thing runs from a queued job.
 */
interface MarksMessagesAsRead
{
    /**
     * Tell the channel that these inbound messages have been read.
     *
     * @param  Collection<int, Message>  $messages  The messages that just flipped
     *         to read locally, oldest first — possibly empty when the caller has
     *         no list to offer. Most channels carry a single watermark and only
     *         need the newest (WhatsApp marks everything before it read too), so
     *         they may ignore this and ask the thread for its last inbound
     *         message instead. The ones that flag messages one by one — IMAP,
     *         and our own widget — need exactly this list and nothing wider.
     *
     * @return bool Whether the channel accepted it. False is not an error; it is
     *              the honest answer when there was nothing to send.
     */
    public function handleMarkAsRead(Conversation $conversation, Collection $messages): bool;
}

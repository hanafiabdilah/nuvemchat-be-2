<?php

namespace App\Services\Message\Contracts;

use App\Models\Conversation;

/**
 * A send handler whose channel can show the customer that an agent is typing.
 *
 * A capability interface rather than a method on MessageHandlerInterface,
 * because two channels genuinely cannot do this — TikTok has no such API and
 * e-mail has no such idea — and three empty methods claiming otherwise would
 * read as an oversight rather than as the fact it is. Callers go through
 * MessageService::sendTyping(), which checks for this interface.
 *
 * Every implementation is **best-effort and silent**: the indicator is a
 * courtesy, and an agent must never be shown an error, or have a reply blocked,
 * because a decoration failed to render on the far side.
 */
interface SendsTypingIndicator
{
    /**
     * Assert or withdraw the typing indicator on the channel.
     *
     * `$typing = false` is a real request to stop on the channels that can
     * (API Way's `paused`, Meta's `typing_off`, the widget's own event) and a
     * no-op on the ones that only expire — Discord in particular offers no way
     * to clear it early, so there the stop is simply the absence of the next
     * refresh.
     *
     * @return bool Whether the channel accepted it. False is not an error; it
     *              is the honest answer when there was nothing to send.
     */
    public function handleTyping(Conversation $conversation, bool $typing = true): bool;
}

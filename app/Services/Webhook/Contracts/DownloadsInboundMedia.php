<?php

namespace App\Services\Webhook\Contracts;

use App\Models\Message;

/**
 * A chat handler that can fetch a stored message's media after the fact.
 *
 * Downloads used to run inline, between storing the message and broadcasting
 * it, so every agent watched an image take as long to appear as it took the
 * channel's CDN to hand it over — and the webhook request was held open for
 * the same stretch. Implementations read everything they need back out of
 * `messages.meta` (the raw channel payload), which is what makes the work
 * replayable from a queue: see App\Jobs\DownloadInboundMedia.
 */
interface DownloadsInboundMedia
{
    /**
     * Fetch this message's media and store it on `attachment`.
     *
     * Leaving `attachment` unset signals failure — the job retries a couple of
     * times before marking the message failed, so implementations are free to
     * either throw or log-and-return.
     */
    public function downloadMedia(Message $message): void;
}

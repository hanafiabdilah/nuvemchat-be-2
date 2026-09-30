<?php

namespace App\Observers;

use App\Jobs\RegisterGalleryAttachment;
use App\Models\Message;
use App\Services\Gallery\GalleryLibrary;

/**
 * Lists the files agents send in the workspace's gallery.
 *
 * An observer for the same reason MessageAttachmentObserver is one: about
 * twenty handlers write `attachment`, usually as create-then-update, and
 * `sent_by_user_id` is stamped by yet another dozen paths. Waiting for whichever
 * save completes the picture is the only place that sees every one of them.
 *
 * Only what an agent sent — see GalleryLibrary::isAgentAttachment(). Files a
 * customer sends never appear in the gallery.
 */
class MessageGalleryObserver
{
    public function created(Message $message): void
    {
        $this->register($message);
    }

    /**
     * ⚠️ `created` and `updated`, not `saved`: `wasRecentlyCreated` stays true
     * on the instance for every later save, so a `saved` hook that trusted it
     * would re-register the file each time the same object was touched again.
     */
    public function updated(Message $message): void
    {
        if ($message->wasChanged('attachment') && empty($message->attachment)) {
            // The e-mail strip and anything else that removes a file through
            // the model. `media:purge` writes past events and calls
            // GalleryLibrary::forgetMessages() itself.
            app(GalleryLibrary::class)->forgetMessages([$message->id]);

            return;
        }

        if ($message->wasChanged('attachment') || $message->wasChanged('sent_by_user_id')) {
            $this->register($message);
        }
    }

    private function register(Message $message): void
    {
        if (! GalleryLibrary::isAgentAttachment($message)) {
            return;
        }

        // Queued, because registering reads the whole file to hash it, and
        // that must not stand between an agent and the send they just made.
        RegisterGalleryAttachment::dispatch($message->id)
            ->onQueue(config('queue.media', 'default'))
            ->afterCommit();
    }
}

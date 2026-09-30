<?php

namespace App\Jobs;

use App\Models\Message;
use App\Services\Gallery\GalleryLibrary;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Lists one agent attachment in the gallery. See MessageGalleryObserver.
 *
 * One try: the work is a hash and an insert, both idempotent on the path, and a
 * file that could not be read now is a missing tile — not worth a retry loop on
 * the queue that also carries inbound media.
 */
class RegisterGalleryAttachment implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public int $messageId) {}

    public function handle(GalleryLibrary $library): void
    {
        // Read again rather than serialized: the attachment may have been
        // replaced or purged between the send and this job.
        $message = Message::with('conversation.connection')->find($this->messageId);

        if ($message !== null) {
            $library->registerMessageAttachment($message);
        }
    }
}

<?php

namespace App\Observers;

use App\Models\Message;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Keeps `messages.attachment_size` in step with `messages.attachment`.
 *
 * Storage is the platform's only monotonically increasing cost, and nothing
 * recorded how much of it any customer was using. Measuring after the fact
 * means a stat() per row across every message ever sent, which is not a query
 * a dashboard can run — so the size is captured once, at the moment the file
 * is attached.
 *
 * It lives in an observer rather than in the handlers because there are about
 * twenty places that write an attachment (one per channel per media type), and
 * a rule enforced in twenty places is a rule that will be missed in the
 * twenty-first.
 */
class MessageAttachmentObserver
{
    public function saved(Message $message): void
    {
        if (! $message->wasChanged('attachment')) {
            return;
        }

        $this->sync($message);
    }

    public function created(Message $message): void
    {
        if ($message->attachment === null) {
            return;
        }

        $this->sync($message);
    }

    private function sync(Message $message): void
    {
        $size = $this->measure($message->attachment);

        if ($size === $message->attachment_size) {
            return;
        }

        // Written straight through the query builder: an update inside a saved
        // hook would re-enter this observer, and `updated_at` is the cursor the
        // SPA's delta sync reads — a byte count is not a change any client
        // needs to be woken up for. Same reasoning as `media:purge`.
        Message::withoutEvents(fn () => Message::whereKey($message->getKey())
            ->toBase()
            ->update(['attachment_size' => $size]));

        $message->setAttribute('attachment_size', $size)->syncOriginalAttribute('attachment_size');
    }

    /**
     * Bytes on our disk, or null when there is nothing of ours to measure —
     * no attachment, an absolute URL (the file lives in someone else's
     * storage), or a path that has already been purged.
     */
    private function measure(?string $path): ?int
    {
        if ($path === null || $path === '' || Str::startsWith($path, ['http://', 'https://'])) {
            return null;
        }

        try {
            $disk = Storage::disk('local');

            return $disk->exists($path) ? $disk->size($path) : null;
        } catch (\Throwable $e) {
            // Never let accounting break a message write.
            Log::warning('MessageAttachmentObserver: could not measure attachment', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}

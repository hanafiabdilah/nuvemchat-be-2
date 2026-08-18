<?php

namespace App\Console\Commands\Media;

use App\Models\Message;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Measure attachments that predate `messages.attachment_size`.
 *
 * New files are measured as they land (MessageAttachmentObserver), but every
 * file already on disk has a null size, and until they are measured the storage
 * page is reporting a fraction of the truth. One stat() per file, chunked and
 * capped, so it can be run repeatedly until it stops finding work rather than
 * held open across a whole backlog.
 *
 *     php artisan media:backfill-sizes --limit=5000
 */
class BackfillMediaSizes extends Command
{
    protected $signature = 'media:backfill-sizes
        {--limit=5000 : Maximum messages to measure this pass}
        {--chunk=500 : Rows per query}';

    protected $description = 'Record the byte size of attachments stored before size tracking existed';

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $chunk = max(1, min((int) $this->option('chunk'), 1000));

        $disk = Storage::disk('local');
        $measured = 0;
        $missing = 0;
        $bytes = 0;
        $seen = 0;

        // Ordered by id so repeated passes advance instead of re-reading the
        // same rows: every row touched here stops matching the filter.
        Message::query()
            ->whereNull('attachment_size')
            ->whereNotNull('attachment')
            ->orderBy('id')
            ->limit($limit)
            ->chunkById($chunk, function ($messages) use ($disk, &$measured, &$missing, &$bytes, &$seen) {
                foreach ($messages as $message) {
                    $seen++;
                    $path = $message->attachment;

                    // Absolute URLs live in someone else's storage and cost us
                    // nothing — skipped, but marked 0 so they stop being
                    // re-examined on every pass.
                    if (Str::startsWith($path, ['http://', 'https://'])) {
                        $this->write($message->id, 0);

                        continue;
                    }

                    if (! $disk->exists($path)) {
                        // Already purged, or never finished downloading. Left
                        // null: it is not a measurement, it is an absence, and
                        // recording 0 would make the storage page claim these
                        // files were free rather than gone.
                        $missing++;

                        continue;
                    }

                    $size = $disk->size($path);
                    $this->write($message->id, $size);
                    $measured++;
                    $bytes += $size;
                }
            });

        $this->info(sprintf(
            'Examined %d, measured %d (%s), missing on disk %d.',
            $seen,
            $measured,
            $this->humanBytes($bytes),
            $missing,
        ));

        if ($seen >= $limit) {
            $this->comment('Limit reached — run again to continue.');
        }

        return self::SUCCESS;
    }

    /** Bypasses events and timestamps: this is bookkeeping, not a message edit. */
    private function write(int $id, int $size): void
    {
        Message::whereKey($id)->toBase()->update(['attachment_size' => $size]);
    }

    private function humanBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;

        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return round($bytes, 1).' '.$units[$i];
    }
}

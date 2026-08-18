<?php

namespace App\Console\Commands\Media;

use App\Enums\Conversation\Type;
use App\Enums\Message\AttachmentStatus;
use App\Models\Message;
use App\Services\Media\MediaRetention;
use App\Support\Heartbeat;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Deletes message media once it is past its retention window.
 *
 * Storage is the one cost here that only ever grows: every photo, video and
 * audio note any customer ever sent stays on the disk forever, and a group
 * thread multiplies that by its member count for files nobody in the company
 * ever opens. Group media is therefore kept for 30 days and private media for
 * 90 (config/media.php).
 *
 * Only the file goes. The message row, its caption, its reactions and its
 * place in the thread stay put — the bubble turns into an "expired media"
 * marker, which is why this is a purge and not a delete.
 *
 * Two things are deliberate:
 *
 *   - Timestamps are NOT bumped. Writing `attachment = null` through the model
 *     would move `updated_at`, and `updated_at` is the cursor every client's
 *     message delta sync reads: the first pass over a year of backlog would
 *     hand every open dashboard tens of thousands of rows to re-download for
 *     no visible change. Clients converge without being told, because the
 *     signed URL they cached expires on exactly this date (MediaRetention).
 *
 *   - Candidates are walked oldest-first with a per-pass limit, so a large
 *     backlog drains over several runs instead of holding a lock and a worker
 *     for an hour on the first night.
 */
class PurgeExpiredMedia extends Command
{
    protected $signature = 'media:purge
                            {--limit=1000 : Messages to purge per conversation type in one pass}
                            {--dry-run : Report what would be freed without deleting anything}';

    protected $description = 'Delete message media past its retention window (group 30d / private 90d by default)';

    public function handle(): int
    {
        Heartbeat::ping('media:purge');

        if (! MediaRetention::enabled()) {
            $this->warn('Media retention is disabled (MEDIA_RETENTION_ENABLED=false) — nothing to do.');

            return self::SUCCESS;
        }

        $limit = max(1, (int) $this->option('limit'));
        $dryRun = (bool) $this->option('dry-run');

        $messages = 0;
        $files = 0;
        $bytes = 0;

        foreach ([Type::Group, Type::Private] as $type) {
            $days = MediaRetention::daysFor($type);

            if ($days === null) {
                $this->line("{$type->value}: retention disabled, skipped.");

                continue;
            }

            $result = $this->purgeType($type, $days, $limit, $dryRun);

            $messages += $result['messages'];
            $files += $result['files'];
            $bytes += $result['bytes'];
        }

        $orphans = $this->purgeOrphanWidgetUploads($limit, $dryRun);
        $files += $orphans['files'];
        $bytes += $orphans['bytes'];

        $summary = sprintf(
            '%s %d file(s) from %d message(s) plus %d orphan upload(s), %s.',
            $dryRun ? 'Would free' : 'Freed',
            $files - $orphans['files'],
            $messages,
            $orphans['files'],
            $this->humanBytes($bytes),
        );

        $this->info($summary);

        if (! $dryRun && $files > 0) {
            Log::info('media:purge '.$summary);
        }

        return self::SUCCESS;
    }

    /**
     * @return array{messages: int, files: int, bytes: int}
     */
    private function purgeType(Type $type, int $days, int $limit, bool $dryRun): array
    {
        $cutoff = now()->subDays($days);

        $messages = Message::query()
            ->whereNotNull('attachment')
            // Media we never downloaded (sent to us as a URL) costs us nothing
            // and is not ours to delete.
            ->where('attachment', 'not like', 'http%')
            ->where('created_at', '<', $cutoff)
            ->whereHas('conversation', fn ($q) => $q->where('type', $type))
            ->orderBy('id')
            ->limit($limit)
            ->get(['id', 'attachment', 'attachment_status', 'meta']);

        $purgedIds = [];
        $files = 0;
        $bytes = 0;

        foreach ($messages as $message) {
            foreach (MediaRetention::localPathsFor($message) as $path) {
                $size = $this->deleteFile($path, $dryRun);

                if ($size === null) {
                    continue;
                }

                $files++;
                $bytes += $size;
            }

            if ($dryRun) {
                continue;
            }

            if ($this->stripEmailAttachmentPaths($message)) {
                continue;
            }

            $purgedIds[] = $message->id;
        }

        if ($purgedIds !== []) {
            // Bulk, and without touching updated_at — see the class docblock.
            Message::withoutTimestamps(fn () => Message::whereIn('id', $purgedIds)->update([
                'attachment' => null,
                'attachment_status' => AttachmentStatus::Expired,
            ]));
        }

        $this->line(sprintf(
            '  %s: %d message(s) older than %d day(s), %d file(s), %s',
            $type->value,
            $messages->count(),
            $days,
            $files,
            $this->humanBytes($bytes),
        ));

        if ($messages->count() === $limit) {
            $this->line("  {$type->value}: hit the {$limit}-per-pass limit — more media is still waiting, the next run continues.");
        }

        return ['messages' => $messages->count(), 'files' => $files, 'bytes' => $bytes];
    }

    /**
     * E-mails keep one entry per attachment in meta and mirror only the first
     * into `attachment`, so those rows need their JSON rewritten rather than a
     * bulk update. The entries stay — the reading pane still lists what came
     * with the e-mail — they just lose the path and gain a marker.
     *
     * The HTML body (meta.email.html_path) is deliberately left alone: it is
     * the message itself, measured in kilobytes, not the media this command
     * exists to reclaim.
     */
    private function stripEmailAttachmentPaths(Message $message): bool
    {
        $meta = $message->meta;

        if (! is_array($meta['email']['attachments'] ?? null)) {
            return false;
        }

        $meta['email']['attachments'] = array_map(function ($attachment) {
            unset($attachment['path']);
            $attachment['expired'] = true;

            return $attachment;
        }, $meta['email']['attachments']);

        Message::withoutTimestamps(fn () => $message->forceFill([
            'attachment' => null,
            'attachment_status' => AttachmentStatus::Expired,
            'meta' => $meta,
        ])->save());

        return true;
    }

    /**
     * Widget visitors upload a file first and send it in a second call. An
     * upload that never became a message is referenced by nothing, so it would
     * otherwise sit on disk forever.
     *
     * @return array{files: int, bytes: int}
     */
    private function purgeOrphanWidgetUploads(int $limit, bool $dryRun): array
    {
        $disk = Storage::disk('local');

        if (! $disk->exists('widget-uploads')) {
            return ['files' => 0, 'bytes' => 0];
        }

        $ttlHours = max(1, (int) config('media.widget_upload_ttl_hours', 24));
        $cutoff = now()->subHours($ttlHours)->getTimestamp();

        $stale = collect($disk->allFiles('widget-uploads'))
            ->filter(fn (string $path) => rescue(fn () => $disk->lastModified($path), 0, false) < $cutoff)
            ->take($limit)
            ->values();

        if ($stale->isEmpty()) {
            return ['files' => 0, 'bytes' => 0];
        }

        // An upload that did become a message is covered by the retention
        // sweep above, on that message's own clock.
        $referenced = Message::whereIn('attachment', $stale->all())->pluck('attachment')->all();
        $orphans = $stale->diff($referenced);

        $files = 0;
        $bytes = 0;

        foreach ($orphans as $path) {
            $size = $this->deleteFile($path, $dryRun);

            if ($size === null) {
                continue;
            }

            $files++;
            $bytes += $size;
        }

        if (! $dryRun) {
            foreach ($disk->directories('widget-uploads') as $directory) {
                if ($disk->allFiles($directory) === []) {
                    $disk->deleteDirectory($directory);
                }
            }
        }

        $this->line(sprintf(
            '  widget uploads: %d orphan(s) older than %dh, %s',
            $files,
            $ttlHours,
            $this->humanBytes($bytes),
        ));

        return ['files' => $files, 'bytes' => $bytes];
    }

    /** Size of the file that was (or would be) deleted, or null if it was already gone. */
    private function deleteFile(string $path, bool $dryRun): ?int
    {
        $disk = Storage::disk('local');

        if (! $disk->exists($path)) {
            return null;
        }

        $size = (int) rescue(fn () => $disk->size($path), 0, false);

        if (! $dryRun) {
            $disk->delete($path);
        }

        return $size;
    }

    private function humanBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $unit = 0;
        $value = (float) $bytes;

        while ($value >= 1024 && $unit < count($units) - 1) {
            $value /= 1024;
            $unit++;
        }

        return sprintf($unit === 0 ? '%d %s' : '%.1f %s', $value, $units[$unit]);
    }
}

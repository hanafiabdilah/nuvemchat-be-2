<?php

namespace App\Console\Commands\Media;

use App\Models\Message;
use App\Services\Media\MediaFilename;
use App\Services\Media\MediaStorage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Give already-stored files their names back.
 *
 * Three shapes are on disk from three eras, and only the newest is readable:
 *
 *   media/4812_68d1a2f3b4c5d.pdf              — all code, no name
 *   media/Comprovante-de-Pagamento_4812.pdf   — name plus a code
 *   media/4812/Comprovante de Pagamento.pdf   — the name, and nothing else
 *
 * What makes the first one recoverable is `messages.meta.filename`: the channel
 * told us the real name at the time and we kept it, so this is not a rename so
 * much as a restore — accents, spaces and capitals come back with it.
 *
 * ⚠️ The file is **moved into the new directory shape**, not renamed in place.
 * Renaming `media/Comprovante_4812.pdf` to `media/Comprovante.pdf` would drop a
 * bare name into the folder every tenant shares, which is the collision the
 * directory exists to prevent. Its own directory is the whole point.
 *
 * ⚠️ `uploads/` is deliberately left alone. Those addresses are written into
 * flow nodes, carousel cards, campaigns and Instagram post items — JSON we
 * would have to rewrite in lockstep — and `media:scan-unsafe-uploads` already
 * exists because that folder's URLs can be sitting in places we do not control.
 * Renaming them is the same class of risk; this command counts them and says so.
 */
class RestoreMediaFilenames extends Command
{
    protected $signature = 'media:restore-filenames
        {--dry-run : List what would move and change nothing}
        {--tenant= : Only this workspace}
        {--limit=500 : Most messages to move in one pass}';

    protected $description = 'Strip the unique code from stored media names, restoring the real filename';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit = max(1, (int) $this->option('limit'));
        $disk = MediaStorage::disk();

        $moved = 0;
        $skipped = 0;
        $missing = 0;

        $query = Message::query()
            ->whereNotNull('attachment')
            ->when($this->option('tenant'), fn ($q, $tenant) => $q->whereHas(
                'conversation.connection',
                fn ($c) => $c->where('tenant_id', $tenant),
            ))
            ->orderBy('id');

        foreach ($query->lazyById(200) as $message) {
            if ($moved + $skipped >= $limit) {
                break;
            }

            $from = (string) $message->attachment;

            // Media sent by URL lives on somebody else's storage.
            if (\App\Services\Media\MediaRetention::isExternal($from)) {
                continue;
            }

            // ⚠️ `media/` only. A widget upload lives under
            // `widget-uploads/{session}/`, has its own lifecycle and its own
            // orphan sweep in media:purge — relocating one into `media/` is a
            // bigger action than renaming it, and not one this command was
            // asked to take.
            if (! str_starts_with($from, 'media/')) {
                continue;
            }

            $to = $this->target($message, $from);

            if ($to === null || $to === $from) {
                continue;
            }

            if (! $disk->exists($from)) {
                // Purged by retention, or still on the legacy disk mid-migration.
                // Either way its name is not this command's business.
                $missing++;

                continue;
            }

            if ($disk->exists($to)) {
                $this->warn("  skipped {$from}: {$to} already exists");
                $skipped++;

                continue;
            }

            $this->line(($dryRun ? '  would move ' : '  moved ')."{$from} → {$to}");

            if (! $dryRun) {
                // The move first, then the row. A row pointing at a file that is
                // not there yet is a broken bubble; a file whose row still points
                // at the old name is invisible, and the next pass fixes it.
                if (! $disk->move($from, $to)) {
                    Log::warning('media:restore-filenames could not move a file', [
                        'message_id' => $message->id,
                        'from' => $from,
                        'to' => $to,
                    ]);
                    $skipped++;

                    continue;
                }

                // ⚠️ `updated_at` IS bumped, unlike media:purge. The delta-sync
                // cursor is the only way a dashboard hears about this: the SPA
                // writes attachment URLs into IndexedDB and never asks for one
                // again, so a client holding the old path would keep a broken
                // bubble forever. Purge can stay silent because the client can
                // tell from the `expires` in the URL it already has; a moved
                // file it cannot infer at all.
                //
                // The volume worry that argued for silence does not survive
                // contact with the data — a full pass over production is a few
                // hundred rows, and `--limit` is what bounds a batch anyway.
                Message::whereKey($message->getKey())
                    ->toBase()
                    ->update(['attachment' => $to, 'updated_at' => now()]);
            }

            $moved++;
        }

        $this->newLine();
        $this->line(sprintf(
            '%s %d file(s); %d skipped, %d not on this disk.',
            $dryRun ? 'Would move' : 'Moved',
            $moved,
            $skipped,
            $missing,
        ));

        $this->reportUploads();

        return self::SUCCESS;
    }

    /**
     * Where this attachment belongs, or null when its name is already right or
     * there is nothing to recover.
     */
    private function target(Message $message, string $from): ?string
    {
        $extension = pathinfo($from, PATHINFO_EXTENSION);
        $name = $this->desiredName($message, $from);

        if ($name === null) {
            return null;
        }

        return MediaFilename::path(
            'media',
            (string) $message->id,
            $name,
            $extension,
            $message->message_type?->value,
        );
    }

    private function desiredName(Message $message, string $from): ?string
    {
        $base = pathinfo($from, PATHINFO_FILENAME);

        // The pristine name, where the channel gave us one. This is the branch
        // that recovers `Comprovante de Pagamento.pdf` from a path that never
        // held anything but a hash.
        $pristine = $message->meta['filename'] ?? null;

        if (is_string($pristine) && trim($pristine) !== '') {
            $candidate = pathinfo(basename(str_replace('\\', '/', trim($pristine))), PATHINFO_FILENAME);

            // ⚠️ The widget writes `meta.filename` as the basename of the path
            // it just stored, so on that channel this field *is* the code we are
            // trying to remove. Caught in a dry run against production, where it
            // wanted to move thousands of files to rewrite a UUID into itself.
            // A recovered name has to say something the path does not.
            return $candidate === $base || self::isBareCode($candidate) ? null : $candidate;
        }

        // ⚠️ Only the two suffixes this platform actually wrote: the message id,
        // or a 12-hex token. A looser pattern would eat the end of a real name —
        // `Relatorio_2026.pdf` is not a coded file, and on message 512 it is left
        // exactly as it is.
        $stripped = preg_replace(
            '/_(?:'.preg_quote((string) $message->id, '/').'|[0-9a-f]{12})$/',
            '',
            $base,
        ) ?? $base;

        // Unchanged, or nothing but code — an old `4812_68d1a2f3b4c5d` with no
        // recorded name has no name to go back to, and inventing one is worse
        // than leaving the hash.
        return $stripped === $base || trim($stripped) === '' || self::isBareCode($stripped)
            ? null
            : $stripped;
    }

    /**
     * A UUID, or a long run of nothing but hex and separators: a code with no
     * human part to recover.
     *
     * Deliberately narrow — `comprovante2026-09-01_142423` carries letters well
     * past `f`, so a real name never looks like this.
     */
    private static function isBareCode(string $name): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $name) === 1
            || preg_match('/^[0-9a-f][0-9a-f_-]{15,}$/i', $name) === 1;
    }

    /**
     * Count what is left in `uploads/` and say why it stays.
     */
    private function reportUploads(): void
    {
        $published = MediaStorage::published();

        $coded = 0;

        foreach (rescue(fn () => $published->listContents('uploads', true), [], false) as $item) {
            // A file directly under `uploads/` is the oldest shape (a bare
            // hashName); one under `uploads/{12-hex}/` carries a token folder.
            // Both are pre-workspace-folder.
            if ($item->isFile() && ! preg_match('~^uploads/\d+/[^/]+$~', $item->path())) {
                $coded++;
            }
        }

        if ($coded === 0) {
            return;
        }

        $this->newLine();
        $this->warn("{$coded} file(s) in uploads/ predate the per-workspace folder and are left alone:");
        $this->line('  their URLs are stored inside flow nodes, carousel cards, campaigns and');
        $this->line('  Instagram post items, so moving them means rewriting that JSON in lockstep.');
        $this->line('  They keep working exactly where they are.');
    }
}

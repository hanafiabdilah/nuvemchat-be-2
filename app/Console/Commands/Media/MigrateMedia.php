<?php

namespace App\Console\Commands\Media;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\StorageAttributes;
use Throwable;

/**
 * Copies media from one disk to another — the local disks to the bucket.
 *
 * Built to be run more than once. It lists the target first, in one paginated
 * listing rather than a HEAD per file (the bucket is a continent away), and
 * copies only what is missing or has a different size. So the first run does
 * the bulk, a second run right after switching MEDIA_DISK catches what arrived
 * in between, and `--dry-run` doubles as the verification that nothing is left.
 *
 * It never deletes anything. Freeing the local disk is a separate, deliberate
 * step once the new disk has been serving for a while.
 */
class MigrateMedia extends Command
{
    protected $signature = 'media:migrate
                            {from : Source disk, e.g. local or public}
                            {to : Target disk, e.g. media or media_public}
                            {--prefix= : Only copy files under this path}
                            {--dry-run : Report what is missing or different without copying}
                            {--limit=0 : Stop after copying this many files (0 = all)}';

    protected $description = 'Copy media files between disks (idempotent; skips files already copied)';

    public function handle(): int
    {
        $fromName = (string) $this->argument('from');
        $toName = (string) $this->argument('to');

        if ($fromName === $toName) {
            $this->error('Source and target are the same disk.');

            return self::FAILURE;
        }

        $from = Storage::disk($fromName);
        $to = Storage::disk($toName);
        $prefix = trim((string) $this->option('prefix'), '/');
        $dryRun = (bool) $this->option('dry-run');
        $limit = max(0, (int) $this->option('limit'));

        $this->line("Listing {$toName}…");
        $existing = [];

        foreach ($to->listContents($prefix, true) as $item) {
            if ($item->isFile()) {
                $existing[$item->path()] = $item->fileSize();
            }
        }

        $this->line(sprintf('  %d file(s) already on %s.', count($existing), $toName));
        $this->line("Comparing with {$fromName}…");

        $present = 0;
        $pending = 0;
        $pendingBytes = 0;
        $copied = 0;
        $copiedBytes = 0;
        $failed = 0;

        foreach ($from->listContents($prefix, true) as $item) {
            if (! $this->isMedia($item)) {
                continue;
            }

            $path = $item->path();
            $size = (int) $item->fileSize();

            if (array_key_exists($path, $existing) && $existing[$path] === $size) {
                $present++;

                continue;
            }

            $pending++;
            $pendingBytes += $size;

            if ($dryRun) {
                continue;
            }

            if ($this->copy($from, $to, $path)) {
                $copied++;
                $copiedBytes += $size;

                if ($copied % 500 === 0) {
                    $this->line(sprintf('  … %d copied (%s)', $copied, $this->humanBytes($copiedBytes)));
                }
            } else {
                $failed++;
            }

            if ($limit > 0 && $copied >= $limit) {
                $this->warn("Stopped at --limit={$limit}; run again to continue.");

                break;
            }
        }

        $this->table(['', 'files', 'size'], [
            ['already on '.$toName, $present, ''],
            [$dryRun ? 'missing or different' : 'needed copying', $pending, $this->humanBytes($pendingBytes)],
            ['copied', $dryRun ? '—' : $copied, $dryRun ? '' : $this->humanBytes($copiedBytes)],
            ['failed', $failed, ''],
        ]);

        if ($dryRun && $pending === 0) {
            $this->info("Nothing left to copy: {$toName} holds every file from {$fromName}.");
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** Files only: dotfiles (a storage directory's .gitignore) are not media. */
    private function isMedia(StorageAttributes $item): bool
    {
        return $item->isFile() && ! str_starts_with(basename($item->path()), '.');
    }

    private function copy($from, $to, string $path): bool
    {
        try {
            $stream = $from->readStream($path);

            if (! is_resource($stream)) {
                throw new \RuntimeException('could not open the source file');
            }

            try {
                $written = $to->writeStream($path, $stream);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }

            if (! $written) {
                throw new \RuntimeException('the target refused the write');
            }

            return true;
        } catch (Throwable $e) {
            $this->error("  ✗ {$path}: {$e->getMessage()}");

            return false;
        }
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

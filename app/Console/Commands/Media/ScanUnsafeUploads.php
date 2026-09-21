<?php

namespace App\Console\Commands\Media;

use App\Models\GalleryAsset;
use App\Services\Media\MediaStorage;
use App\Services\Media\UploadPolicy;
use Illuminate\Console\Command;

/**
 * Find files uploaded before the type allow-list existed that a browser would
 * still render as a document.
 *
 * Input validation fixes the next upload; it can do nothing about the ones
 * already on disk. Until this has been run and come back clean, the honest
 * answer to "were we exploited" is "we have not looked".
 *
 *     php artisan media:scan-unsafe-uploads
 *
 * ⚠️ Reports only, and deliberately so. The same address may be written into a
 * live flow node, a carousel card or a scheduled campaign, so deleting a file
 * silently breaks a customer's automation — which is a decision for whoever can
 * see what that automation is, not for a scan. What to do with a hit is in
 * docs/upload-type-policy.md.
 */
class ScanUnsafeUploads extends Command
{
    protected $signature = 'media:scan-unsafe-uploads
        {--prefix=* : Limit the disk sweep to these path prefixes (default: uploads/, instagram/)}';

    protected $description = 'List uploaded files a browser would render as a document (HTML, SVG, XML, JS)';

    /** Extensions that make a stored file a page rather than a picture. */
    private const RISKY_EXTENSIONS = [
        'html', 'htm', 'xhtml', 'shtml', 'xml', 'svg', 'svgz',
        'js', 'mjs', 'php', 'phtml', 'phar', 'swf',
    ];

    public function handle(): int
    {
        $found = $this->scanGallery() + $this->scanPublishedDisk();

        $this->newLine();

        if ($found === 0) {
            $this->info('Nothing found. No uploaded file would be served as a document.');

            return self::SUCCESS;
        }

        $this->warn("{$found} file(s) need a decision — see docs/upload-type-policy.md.");
        $this->line('Nothing was deleted: these addresses may be referenced by live flows or campaigns.');

        return self::FAILURE;
    }

    /**
     * Gallery rows, matched on the type we recorded at upload.
     *
     * The serving side already refuses to echo these back as documents
     * (UploadPolicy::safeContentType), so a hit here is no longer exploitable —
     * but it is still a file somebody uploaded to be a script, and that is
     * worth knowing about.
     */
    private function scanGallery(): int
    {
        $rows = GalleryAsset::query()
            ->orderBy('tenant_id')
            ->get(['id', 'tenant_id', 'uuid', 'name', 'path', 'mime_type', 'created_at'])
            ->filter(fn (GalleryAsset $asset) => UploadPolicy::isRenderable($asset->mime_type)
                || in_array($this->extensionOf($asset->path), self::RISKY_EXTENSIONS, true));

        $this->line('<options=bold>Gallery</>');

        if ($rows->isEmpty()) {
            $this->line('  clean');

            return 0;
        }

        foreach ($rows as $asset) {
            $this->line(sprintf(
                '  <fg=red>hit</>  asset #%d  tenant %d  %s  (%s)  uploaded %s',
                $asset->id,
                $asset->tenant_id,
                $asset->name,
                $asset->mime_type,
                $asset->created_at?->toDateString() ?? 'unknown',
            ));
        }

        return $rows->count();
    }

    /**
     * The published disk, matched on the stored extension.
     *
     * Laravel names an upload after the extension it guesses from the content,
     * so a `.html` here is a file whose *content* was HTML — not merely one
     * that was named that way.
     */
    private function scanPublishedDisk(): int
    {
        $prefixes = (array) ($this->option('prefix') ?: ['uploads', 'instagram']);
        $disk = MediaStorage::published();
        $hits = 0;

        $this->line('<options=bold>Published disk ('.MediaStorage::publishedDiskName().')</>');

        foreach ($prefixes as $prefix) {
            foreach ($disk->allFiles(trim($prefix, '/')) as $path) {
                if (! in_array($this->extensionOf($path), self::RISKY_EXTENSIONS, true)) {
                    continue;
                }

                $this->line(sprintf('  <fg=red>hit</>  %s  (%s)', $path, MediaStorage::publishedUrl($path)));
                $hits++;
            }
        }

        if ($hits === 0) {
            $this->line('  clean');
        }

        return $hits;
    }

    private function extensionOf(string $path): string
    {
        return strtolower(pathinfo($path, PATHINFO_EXTENSION));
    }
}

<?php

namespace App\Console\Commands\Gallery;

use App\Enums\Gallery\AssetOrigin;
use App\Enums\Gallery\AssetType;
use App\Models\AiMediaGeneration;
use App\Models\GalleryAsset;
use App\Services\Media\MediaRetention;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Lists the files the AI media node made before Nov 2026 in each workspace's
 * gallery, so they count against its storage like the ones made since.
 *
 * Built from `ai_media_generations`, not from the disk: the table already knows
 * each file's path, size and type, and reading tens of gigabytes back from the
 * bucket to learn what it says would be the expensive way to the same rows.
 *
 * ⚠️ The checksum is a placeholder (a hash of the generation id), not a hash of
 * the bytes — for the same reason. It only feeds "identical bytes are listed
 * once", and no two generations are identical.
 *
 * Only files still inside `media.retention.ai_days`: older ones are the next
 * `media:purge`'s, and listing them first would show the customer a meter that
 * drops by itself an hour later. Idempotent — a path already listed is skipped.
 */
class IndexAiMedia extends Command
{
    protected $signature = 'gallery:index-ai-media
                            {--tenant= : Only this workspace}
                            {--dry-run : Count what would be listed, write nothing}';

    protected $description = 'List existing AI media node files in each workspace gallery (they count against its storage)';

    public function handle(): int
    {
        $only = $this->option('tenant') !== null ? (int) $this->option('tenant') : null;
        $dryRun = (bool) $this->option('dry-run');
        $days = MediaRetention::aiDays();

        $query = AiMediaGeneration::query()
            ->where('status', AiMediaGeneration::STATUS_COMPLETED)
            ->whereNotNull('path')
            ->when($only, fn ($q) => $q->where('tenant_id', $only))
            ->when($days !== null, fn ($q) => $q->where('created_at', '>=', now()->subDays($days)))
            ->orderBy('id');

        $listed = 0;
        $known = 0;
        $bytes = 0;

        $query->chunkById(500, function ($generations) use ($dryRun, &$listed, &$known, &$bytes) {
            $existing = GalleryAsset::query()
                ->whereIn('path', $generations->pluck('path'))
                ->pluck('path')
                ->flip();

            $rows = [];

            foreach ($generations as $generation) {
                if (isset($existing[$generation->path])) {
                    $known++;

                    continue;
                }

                $listed++;
                $bytes += (int) $generation->size_bytes;

                $extension = strtolower(pathinfo($generation->path, PATHINFO_EXTENSION));
                $mime = $generation->mime_type ?: 'application/octet-stream';
                $name = 'ai-'.$generation->id.($extension !== '' ? ".{$extension}" : '');
                $at = $generation->completed_at ?? $generation->created_at ?? now();

                $rows[] = [
                    'tenant_id' => $generation->tenant_id,
                    'origin' => AssetOrigin::Ai->value,
                    'uuid' => (string) Str::uuid(),
                    'public_filename' => $name,
                    'name' => $name,
                    'path' => $generation->path,
                    'mime_type' => Str::limit($mime, 150, ''),
                    'type' => AssetType::classify($mime, $extension)->value,
                    'size_bytes' => max(0, (int) $generation->size_bytes),
                    'checksum' => hash('sha256', 'ai-media:'.$generation->id),
                    'created_at' => $at,
                    'updated_at' => $at,
                ];
            }

            if (! $dryRun && $rows !== []) {
                GalleryAsset::query()->insertOrIgnore($rows);
            }
        });

        $this->info(sprintf(
            '%s %d AI file(s) (%s); %d already listed.',
            $dryRun ? 'Would list' : 'Listed',
            $listed,
            GalleryAsset::formatBytes($bytes),
            $known,
        ));

        return self::SUCCESS;
    }
}

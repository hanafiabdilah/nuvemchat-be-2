<?php

namespace App\Console\Commands\Gallery;

use App\Enums\Gallery\AssetOrigin;
use App\Models\Broadcast;
use App\Models\FlowNode;
use App\Models\GalleryAsset;
use App\Models\Product;
use App\Models\Tenant;
use App\Services\Gallery\GalleryLibrary;
use App\Services\Media\MediaStorage;
use Illuminate\Console\Command;

/**
 * Lists the flow, campaign and catalog files uploaded before the gallery
 * showed them.
 *
 * New uploads are listed as they happen (PublishedUpload + GalleryLibrary);
 * this is the one pass for everything already on the published disk.
 *
 * What each file was for is not recorded anywhere, so it is inferred from
 * where its URL appears — a flow node, a campaign payload, a product. A file
 * nothing refers to is still listed, as `upload` ("other").
 *
 * ⚠️ Files from the era before `uploads/{tenant}/` sit in the root of the
 * folder with no owner in the path. Those are only listed when a reference
 * names their workspace; one nothing refers to has no workspace to be shown
 * to, and is skipped — never guessed.
 *
 * Idempotent: registration is keyed on the path, and identical bytes are
 * listed once. Nothing on disk is touched.
 */
class IndexUploads extends Command
{
    protected $signature = 'gallery:index-uploads
        {--tenant= : Only this workspace}
        {--dry-run : Report what would be listed, write nothing}';

    protected $description = 'List existing flow, campaign and catalog uploads in each workspace gallery';

    /** Checked in this order: a file used by a flow is a flow file first. */
    private const ORIGINS = [AssetOrigin::Flow, AssetOrigin::Campaign, AssetOrigin::Catalog];

    public function handle(GalleryLibrary $library): int
    {
        $onlyTenant = $this->option('tenant') !== null ? (int) $this->option('tenant') : null;
        $dryRun = (bool) $this->option('dry-run');
        $disk = MediaStorage::published();

        $tenants = Tenant::query()
            ->when($onlyTenant, fn ($q) => $q->whereKey($onlyTenant))
            ->get()
            ->keyBy('id');

        $haystacks = $tenants->map(fn (Tenant $tenant) => $this->haystacks($tenant->id));

        $counts = ['listed' => 0, 'known' => 0, 'unowned' => 0, 'failed' => 0];

        foreach ($disk->listContents('uploads', true) as $item) {
            if (! $item->isFile() || str_starts_with(basename($item->path()), '.')) {
                continue;
            }

            $path = $item->path();
            [$tenantId, $origin] = $this->classify($path, $haystacks);

            if ($tenantId === null || ! $tenants->has($tenantId)) {
                $counts['unowned']++;

                continue;
            }

            if (GalleryAsset::forTenant($tenantId)->where('path', $path)->exists()) {
                $counts['known']++;

                continue;
            }

            if ($dryRun) {
                $this->line("  would list {$path} as {$origin->value} for workspace {$tenantId}");
                $counts['listed']++;

                continue;
            }

            try {
                $library->registerPublished($tenants[$tenantId], $path, $origin);
                $counts['listed']++;
            } catch (\Throwable $e) {
                $counts['failed']++;
                $this->warn("  could not list {$path}: {$e->getMessage()}");
            }
        }

        $this->info(sprintf(
            '%s %d file(s); %d already listed; %d with no workspace (skipped); %d failed.',
            $dryRun ? 'Would list' : 'Listed',
            $counts['listed'],
            $counts['known'],
            $counts['unowned'],
            $counts['failed'],
        ));

        return $counts['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, array<string, string>>  $haystacks
     * @return array{0: int|null, 1: AssetOrigin}
     */
    private function classify(string $path, $haystacks): array
    {
        $segments = explode('/', $path);
        $owner = count($segments) >= 3 && ctype_digit($segments[1]) ? (int) $segments[1] : null;

        // The URLs were written in encoded form, and JSON may have escaped the
        // slashes; the haystacks are unescaped, so both spellings are tried.
        $needles = array_unique([$path, MediaStorage::encodePath($path)]);

        $candidates = $owner !== null
            ? ($haystacks->has($owner) ? [$owner => $haystacks[$owner]] : [])
            : $haystacks->all();

        foreach ($candidates as $tenantId => $byOrigin) {
            foreach (self::ORIGINS as $origin) {
                foreach ($needles as $needle) {
                    if (str_contains($byOrigin[$origin->value], $needle)) {
                        return [$tenantId, $origin];
                    }
                }
            }
        }

        return [$owner, AssetOrigin::Upload];
    }

    /** Every URL a workspace has written down, per origin, as one string each. */
    private function haystacks(int $tenantId): array
    {
        $unescape = fn (?string $text) => str_replace('\\/', '/', (string) $text);

        return [
            AssetOrigin::Flow->value => $unescape(FlowNode::query()
                ->whereHas('flow', fn ($q) => $q->where('tenant_id', $tenantId))
                ->toBase()
                ->pluck('data')
                ->implode("\n")),
            AssetOrigin::Campaign->value => $unescape(Broadcast::where('tenant_id', $tenantId)
                ->toBase()
                ->pluck('payload')
                ->implode("\n")),
            AssetOrigin::Catalog->value => $unescape(Product::where('tenant_id', $tenantId)
                ->whereNotNull('image_url')
                ->pluck('image_url')
                ->implode("\n")),
        ];
    }
}

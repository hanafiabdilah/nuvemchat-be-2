<?php

namespace App\Services\Gallery;

use App\Enums\Broadcast\Status as BroadcastStatus;
use App\Enums\Gallery\AssetOrigin;
use App\Models\Broadcast;
use App\Models\Flow;
use App\Models\FlowNode;
use App\Models\GalleryAsset;
use App\Models\Product;
use App\Services\Media\MediaStorage;

/**
 * Where a gallery file is still being sent from.
 *
 * Asked before a delete, because deleting now really deletes (every file counts
 * against the quota) and the bytes behind a flow node, a campaign or a product
 * are the ones it sends to customers. A file found here is refused with the
 * list, so the person can swap it out where it is used first.
 *
 * Read the same way the gallery backfill classifies uploads (IndexUploads):
 * flows and campaigns keep URLs inside JSON, written encoded and with their
 * slashes possibly escaped, so every spelling of the address is tried.
 *
 * Deliberately not consulted for:
 *  - sent messages — they are history, not something that will send the file
 *    again, and the delete dialog already says their bubble loses the file;
 *  - campaigns that finished, failed or were canceled — they will not send it
 *    again either;
 *  - `message` rows — the file *is* that message's attachment.
 */
class GalleryAssetUsage
{
    /** Enough for a person to act on; the rest is "and more". */
    public const LIMIT = 10;

    /** Campaign states that may still send their media. */
    private const LIVE_CAMPAIGNS = [
        BroadcastStatus::Draft,
        BroadcastStatus::Scheduled,
        BroadcastStatus::Running,
        BroadcastStatus::Paused,
    ];

    /**
     * @return list<array{type: 'flow'|'campaign'|'product', id: int, name: string}>
     */
    public function find(GalleryAsset $asset): array
    {
        if ($asset->origin === AssetOrigin::Message) {
            return [];
        }

        $needles = $this->needles($asset);

        if ($needles === []) {
            return [];
        }

        $found = [];

        foreach ($this->flows($asset->tenant_id, $needles) as $row) {
            $found[] = $row;
        }

        foreach ($this->campaigns($asset->tenant_id, $needles) as $row) {
            $found[] = $row;
        }

        foreach ($this->products($asset, $needles) as $row) {
            $found[] = $row;
        }

        return array_slice($found, 0, self::LIMIT);
    }

    /**
     * Every spelling the file's address can have where it was written down.
     *
     * @return list<string>
     */
    private function needles(GalleryAsset $asset): array
    {
        // A library file is only ever reached through its signed route, and
        // its uuid is the one part of that URL nothing re-encodes.
        if (! $asset->isLinked()) {
            return [$asset->uuid];
        }

        $spellings = array_unique([$asset->path, MediaStorage::encodePath($asset->path)]);
        $needles = [];

        foreach ($spellings as $spelling) {
            $needles[] = $spelling;
            // As a JSON column holds it: slashes escaped, non-ASCII as \u….
            $needles[] = substr((string) json_encode($spelling), 1, -1);
        }

        return array_values(array_unique(array_filter($needles, fn ($n) => $n !== '')));
    }

    /** @param  list<string>  $needles */
    private function contains(?string $haystack, array $needles): bool
    {
        if ($haystack === null || $haystack === '') {
            return false;
        }

        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    /** @param  list<string>  $needles */
    private function flows(int $tenantId, array $needles): array
    {
        $names = Flow::where('tenant_id', $tenantId)->pluck('name', 'id');

        if ($names->isEmpty()) {
            return [];
        }

        $hits = [];

        FlowNode::whereIn('flow_id', $names->keys())
            ->orderBy('id')
            ->select(['id', 'flow_id', 'data'])
            ->toBase()
            ->chunk(500, function ($nodes) use ($needles, $names, &$hits) {
                foreach ($nodes as $node) {
                    if (! isset($hits[$node->flow_id]) && $this->contains($node->data, $needles)) {
                        $hits[$node->flow_id] = [
                            'type' => 'flow',
                            'id' => (int) $node->flow_id,
                            'name' => (string) ($names[$node->flow_id] ?? ''),
                        ];
                    }
                }
            });

        return array_values($hits);
    }

    /** @param  list<string>  $needles */
    private function campaigns(int $tenantId, array $needles): array
    {
        return Broadcast::where('tenant_id', $tenantId)
            ->whereIn('status', array_map(fn ($s) => $s->value, self::LIVE_CAMPAIGNS))
            ->toBase()
            ->get(['id', 'name', 'payload'])
            ->filter(fn ($row) => $this->contains($row->payload, $needles))
            ->map(fn ($row) => ['type' => 'campaign', 'id' => (int) $row->id, 'name' => (string) $row->name])
            ->values()
            ->all();
    }

    /** @param  list<string>  $needles */
    private function products(GalleryAsset $asset, array $needles): array
    {
        return Product::where('tenant_id', $asset->tenant_id)
            ->where(fn ($q) => $q->where('gallery_asset_id', $asset->id)->orWhereNotNull('image_url'))
            ->toBase()
            ->get(['id', 'name', 'image_url', 'gallery_asset_id'])
            ->filter(fn ($row) => (int) $row->gallery_asset_id === $asset->id || $this->contains($row->image_url, $needles))
            ->map(fn ($row) => ['type' => 'product', 'id' => (int) $row->id, 'name' => (string) $row->name])
            ->values()
            ->all();
    }
}

<?php

namespace App\Services\Gallery;

use App\Exceptions\Gallery\GalleryFolderException;
use App\Models\GalleryAsset;
use App\Models\GalleryFolder;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The folder tree of a workspace's media library.
 *
 * Folders only organise: putting a file in one changes a column, never a path
 * or a URL, so nothing already sent with the file can break because somebody
 * tidied the library.
 *
 * The rules that shape the rest:
 *
 *  1. Deleting a folder never deletes a file. What it held moves up to its
 *     parent. A file deleted from the library loses its picture in every
 *     message that used it, and that is a decision somebody has to make about
 *     that file — not a side effect of removing the box it was in.
 *
 *  2. Two folders side by side never share a name (case-insensitively). A
 *     breadcrumb that reads "Catálogos › Catálogos" is a tree nobody can find
 *     their way around, and the person who made the second one almost always
 *     meant the first.
 *
 *  3. The tree is bounded — MAX_DEPTH levels, MAX_FOLDERS per workspace — so
 *     every rule here can be checked against the whole tree in memory. That is
 *     a few hundred short rows at worst, and it is what keeps "is this folder
 *     inside the one I am moving?" a loop rather than a recursive query.
 *
 * Writes run under a per-workspace lock, for the same reason GalleryService
 * stores under one: two people creating "Catálogos" in the same second would
 * otherwise both pass a name check neither could pass alone.
 */
class GalleryFolderService
{
    public const MAX_DEPTH = 5;

    public const MAX_FOLDERS = 500;

    public const MAX_NAME_LENGTH = 120;

    /**
     * Every folder of the workspace, keyed by id.
     *
     * @return Collection<int, GalleryFolder>
     */
    public function all(int $tenantId): Collection
    {
        return GalleryFolder::forTenant($tenantId)
            ->get(['id', 'tenant_id', 'parent_id', 'name'])
            ->keyBy('id');
    }

    /** @throws GalleryFolderException */
    public function create(Tenant $tenant, string $name, ?GalleryFolder $parent = null, ?User $by = null): GalleryFolder
    {
        $name = $this->normalizeName($name);

        return $this->locked($tenant->id, function () use ($tenant, $name, $parent, $by) {
            $folders = $this->all($tenant->id);

            if ($folders->count() >= self::MAX_FOLDERS) {
                throw GalleryFolderException::limitReached(self::MAX_FOLDERS);
            }

            if ($parent !== null && $this->depth($folders, $parent->id) + 1 > self::MAX_DEPTH) {
                throw GalleryFolderException::tooDeep(self::MAX_DEPTH);
            }

            if ($this->nameTaken($folders, $parent?->id, $name)) {
                throw GalleryFolderException::nameTaken($name);
            }

            return GalleryFolder::create([
                'tenant_id' => $tenant->id,
                'parent_id' => $parent?->id,
                'name' => $name,
                'created_by_user_id' => $by?->id,
            ]);
        });
    }

    /**
     * Rename a folder, move it under another one, or both.
     *
     * Moving and renaming are checked together, against the folder's
     * destination: renaming "Fotos" to "Catálogos" while moving it next to an
     * existing "Catálogos" must be refused even though neither change would be
     * on its own.
     *
     * @param  bool  $move  whether $parent is a requested destination at all —
     *                      null is a real one (the top level), so it cannot
     *                      double as "not moving"
     *
     * @throws GalleryFolderException
     */
    public function update(GalleryFolder $folder, ?string $name, bool $move = false, ?GalleryFolder $parent = null): GalleryFolder
    {
        $name = $name !== null ? $this->normalizeName($name) : null;

        return $this->locked($folder->tenant_id, function () use ($folder, $name, $move, $parent) {
            $folders = $this->all($folder->tenant_id);
            $parentId = $move ? $parent?->id : $folder->parent_id;
            $finalName = $name ?? $folder->name;

            if ($move && $parentId !== $folder->parent_id) {
                if ($parentId !== null && ($parentId === $folder->id || $this->descendantIds($folders, $folder->id)->contains($parentId))) {
                    throw GalleryFolderException::intoItself();
                }

                $parentDepth = $parentId !== null ? $this->depth($folders, $parentId) : 0;

                if ($parentDepth + $this->height($folders, $folder->id) > self::MAX_DEPTH) {
                    throw GalleryFolderException::tooDeep(self::MAX_DEPTH);
                }
            }

            if ($this->nameTaken($folders, $parentId, $finalName, $folder->id)) {
                throw GalleryFolderException::nameTaken($finalName);
            }

            $folder->update(['name' => $finalName, 'parent_id' => $parentId]);

            return $folder->fresh();
        });
    }

    /**
     * Remove a folder. Its files and subfolders move up to its parent.
     *
     * A subfolder that would land next to a folder of the same name is given a
     * numbered name ("Fotos (2)") rather than merged: merging two folders is a
     * decision about which files belong together, and this is only the removal
     * of a box.
     *
     * @return array{files: int, folders: int} what moved up, for the sentence
     *                                         that tells the person where it went
     */
    public function delete(GalleryFolder $folder): array
    {
        return $this->locked($folder->tenant_id, function () use ($folder) {
            return DB::transaction(function () use ($folder) {
                $folders = $this->all($folder->tenant_id);
                $parentId = $folder->parent_id;

                $files = GalleryAsset::where('tenant_id', $folder->tenant_id)
                    ->where('folder_id', $folder->id)
                    ->update(['folder_id' => $parentId]);

                $taken = $folders
                    ->filter(fn (GalleryFolder $f) => $f->parent_id === $parentId && $f->id !== $folder->id)
                    ->map(fn (GalleryFolder $f) => mb_strtolower($f->name))
                    ->values()
                    ->all();

                $children = $folders->filter(fn (GalleryFolder $f) => $f->parent_id === $folder->id);

                foreach ($children as $child) {
                    $name = $this->uniqueName($child->name, $taken);
                    $taken[] = mb_strtolower($name);

                    GalleryFolder::whereKey($child->id)->update(['parent_id' => $parentId, 'name' => $name]);
                }

                $folder->delete();

                return ['files' => $files, 'folders' => $children->count()];
            });
        });
    }

    /**
     * File some of the workspace's assets into a folder (null = the top level).
     *
     * Ids that are not this workspace's are ignored rather than refused: the
     * query is scoped, so a foreign id simply matches nothing, and failing the
     * whole move over one stale tile would undo the ones that were fine.
     *
     * @param  list<int>  $assetIds
     * @return int how many moved
     */
    public function moveAssets(Tenant $tenant, array $assetIds, ?GalleryFolder $folder): int
    {
        if ($assetIds === []) {
            return 0;
        }

        return GalleryAsset::forTenant($tenant->id)
            ->whereIn('id', $assetIds)
            ->update(['folder_id' => $folder?->id]);
    }

    /**
     * Each folder's full path, for places that show a file's location in one
     * line ("Catálogos / 2026").
     *
     * @return array<int, string>
     */
    public function paths(int $tenantId): array
    {
        $folders = $this->all($tenantId);
        $paths = [];

        foreach ($folders as $id => $folder) {
            $paths[$id] = $this->ancestry($folders, $id)
                ->map(fn (GalleryFolder $f) => $f->name)
                ->implode(' / ');
        }

        return $paths;
    }

    /** One name, one spelling: trimmed and with inner runs of spaces collapsed. */
    private function normalizeName(string $name): string
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));

        return Str::limit($name, self::MAX_NAME_LENGTH, '');
    }

    /** @param Collection<int, GalleryFolder> $folders */
    private function nameTaken(Collection $folders, ?int $parentId, string $name, ?int $exceptId = null): bool
    {
        $needle = mb_strtolower($name);

        return $folders->contains(fn (GalleryFolder $f) => $f->parent_id === $parentId
            && $f->id !== $exceptId
            && mb_strtolower($f->name) === $needle);
    }

    /**
     * The folder and every folder above it, top first.
     *
     * Bounded by the number of folders, so a cycle written into the table by
     * hand cannot spin this forever.
     *
     * @param  Collection<int, GalleryFolder>  $folders
     * @return Collection<int, GalleryFolder>
     */
    private function ancestry(Collection $folders, int $id): Collection
    {
        $chain = collect();
        $current = $folders->get($id);

        while ($current !== null && $chain->count() <= $folders->count()) {
            $chain->prepend($current);
            $current = $current->parent_id !== null ? $folders->get($current->parent_id) : null;
        }

        return $chain;
    }

    /** Levels from the top down to this folder: a top-level folder is 1. */
    private function depth(Collection $folders, int $id): int
    {
        return $this->ancestry($folders, $id)->count();
    }

    /** Levels from this folder down to its deepest descendant: a leaf is 1. */
    private function height(Collection $folders, int $id, int $guard = 0): int
    {
        if ($guard > self::MAX_FOLDERS) {
            return 1;
        }

        $children = $folders->filter(fn (GalleryFolder $f) => $f->parent_id === $id);

        return 1 + ($children->isEmpty()
            ? 0
            : $children->map(fn (GalleryFolder $f) => $this->height($folders, $f->id, $guard + 1))->max());
    }

    /** @return Collection<int, int> */
    private function descendantIds(Collection $folders, int $id): Collection
    {
        $found = collect();
        $frontier = [$id];

        while ($frontier !== [] && $found->count() <= $folders->count()) {
            $next = $folders
                ->filter(fn (GalleryFolder $f) => in_array($f->parent_id, $frontier, true))
                ->keys()
                ->all();

            $found = $found->merge($next);
            $frontier = $next;
        }

        return $found;
    }

    /** @param list<string> $taken lowercased names already at the destination */
    private function uniqueName(string $name, array $taken): string
    {
        if (! in_array(mb_strtolower($name), $taken, true)) {
            return $name;
        }

        for ($n = 2; ; $n++) {
            $suffix = " ({$n})";
            $candidate = Str::limit($name, self::MAX_NAME_LENGTH - mb_strlen($suffix), '').$suffix;

            if (! in_array(mb_strtolower($candidate), $taken, true)) {
                return $candidate;
            }
        }
    }

    /**
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    private function locked(int $tenantId, callable $work): mixed
    {
        $lock = Cache::lock("gallery:folders:{$tenantId}", 10);

        try {
            $lock->block(5);
        } catch (\Throwable $e) {
            // Same trade as GalleryService: a lock that could not be had is a
            // rare race on a name check, and refusing the change outright over
            // it would be the lock leaking into the product.
            Log::warning('GalleryFolderService: could not acquire the folder lock, writing without it', [
                'tenant_id' => $tenantId,
                'error' => $e->getMessage(),
            ]);
            $lock = null;
        }

        try {
            return $work();
        } finally {
            $lock?->release();
        }
    }
}

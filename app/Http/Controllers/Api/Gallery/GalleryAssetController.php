<?php

namespace App\Http\Controllers\Api\Gallery;

use App\Enums\Gallery\AssetType;
use App\Exceptions\Gallery\GalleryQuotaExceededException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Gallery\GalleryAssetResource;
use App\Models\GalleryAsset;
use App\Services\Gallery\GalleryService;
use App\Services\Gallery\GalleryStorage;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The tenant's media library: listing it, adding to it, and taking things out.
 *
 * Every failure a person can reach here answers with something they can act on.
 * Running out of space says how many bytes are missing and what the workspace
 * has, because "storage full" alone leaves the customer to guess whether the
 * fix is deleting a video or renting a gigabyte.
 */
class GalleryAssetController extends Controller
{
    public function __construct(
        private readonly GalleryService $gallery,
        private readonly GalleryStorage $storage,
    ) {}

    /**
     * The library, newest first.
     *
     * Sorted by upload rather than by last use: the file somebody is looking
     * for right after adding it is the one they just added, and a list that
     * reorders itself every time a message is sent is one nobody can learn.
     */
    public function index(Request $request)
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:200'],
            'type' => ['nullable', Rule::in(AssetType::values())],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $assets = GalleryAsset::query()
            ->forTenant($request->user()->tenant_id)
            ->when($validated['search'] ?? null, fn ($q, $search) => $q->where('name', 'like', "%{$search}%"))
            ->when($validated['type'] ?? null, fn ($q, $type) => $q->where('type', $type))
            ->with('uploader:id,name')
            ->orderByDesc('id')
            ->paginate($validated['per_page'] ?? 40);

        return GalleryAssetResource::collection($assets)
            ->additional(['storage' => $this->storage->summary($request->user()->tenant)]);
    }

    /**
     * Add a file.
     *
     * Returns 200 rather than 201 when the workspace already had these exact
     * bytes: nothing was created, and the caller gets the row it already owns.
     * That distinction is what lets the frontend say "already in your gallery"
     * instead of reporting a second upload that never happened.
     */
    public function store(Request $request)
    {
        $maxKb = max(1, (int) config('gallery.max_upload_mb', 64)) * 1024;

        $request->validate([
            'file' => ['required', 'file', "max:{$maxKb}"],
            'name' => ['nullable', 'string', 'max:250'],
        ]);

        $file = $request->file('file');
        $extension = strtolower($file->getClientOriginalExtension());

        if (in_array($extension, (array) config('gallery.blocked_extensions', []), true)) {
            return response()->json([
                'message' => 'Este tipo de arquivo não pode ser guardado na galeria.',
                'code' => 'blocked_file_type',
                'errors' => ['file' => ['Este tipo de arquivo não pode ser guardado na galeria.']],
            ], 422);
        }

        $tenant = $request->user()->tenant;
        $before = GalleryAsset::forTenant($tenant->id)->count();

        try {
            $asset = $this->gallery->store($tenant, $file, $request->user(), $request->input('name'));
        } catch (GalleryQuotaExceededException $e) {
            return response()->json([
                'message' => 'A galeria está sem espaço. Libere arquivos ou contrate mais armazenamento.',
                'code' => 'gallery_quota_exceeded',
                'used_bytes' => $e->usedBytes,
                'limit_bytes' => $e->limitBytes,
                'required_bytes' => $e->requestedBytes,
                'shortfall_bytes' => $e->shortfallBytes(),
            ], 422);
        }

        $created = GalleryAsset::forTenant($tenant->id)->count() > $before;

        return response()->json([
            'data' => new GalleryAssetResource($asset->load('uploader:id,name')),
            'duplicate' => ! $created,
            'storage' => $this->storage->summary($tenant),
        ], $created ? 201 : 200);
    }

    /** Rename. The public URL is untouched — see GalleryService::rename(). */
    public function update(Request $request, int $id)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:250'],
        ]);

        $asset = $this->find($request, $id);

        return response()->json([
            'data' => new GalleryAssetResource($this->gallery->rename($asset, $validated['name'])),
        ]);
    }

    /**
     * Delete the file for good.
     *
     * ⚠️ Messages already sent with it point at its URL and lose their picture.
     * The dialog in front of this says so; there is no undo, because keeping
     * the bytes around to allow one would mean the customer kept paying for a
     * file the product told them was deleted.
     */
    public function destroy(Request $request, int $id)
    {
        $asset = $this->find($request, $id);

        $this->gallery->delete($asset);

        return response()->json([
            'message' => 'Arquivo removido da galeria.',
            'storage' => $this->storage->summary($request->user()->tenant),
        ]);
    }

    private function find(Request $request, int $id): GalleryAsset
    {
        return GalleryAsset::forTenant($request->user()->tenant_id)->findOrFail($id);
    }
}

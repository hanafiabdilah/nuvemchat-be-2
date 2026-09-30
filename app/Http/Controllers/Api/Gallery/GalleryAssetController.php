<?php

namespace App\Http\Controllers\Api\Gallery;

use App\Enums\Gallery\AssetOrigin;
use App\Enums\Gallery\AssetType;
use App\Exceptions\Gallery\GalleryQuotaExceededException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Gallery\GalleryAssetResource;
use App\Models\GalleryAsset;
use App\Services\Gallery\GalleryService;
use App\Services\Gallery\GalleryStorage;
use App\Services\Media\UploadPolicy;
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
            // `kept` = the library files the quota counts; `linked` = everything
            // the gallery lists without storing (flow, campaign, catalog and
            // agent attachments); or one origin by name.
            'origin' => ['nullable', Rule::in([...AssetOrigin::values(), 'kept', 'linked'])],
            // For pickers that write the URL somewhere it is sent again later —
            // a campaign, a flow node, a product. Leaves out attachments, whose
            // URL dies with the message's media.
            'permanent' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $assets = GalleryAsset::query()
            ->forTenant($request->user()->tenant_id)
            ->when($validated['search'] ?? null, fn ($q, $search) => $q->where('name', 'like', "%{$search}%"))
            ->when($validated['type'] ?? null, fn ($q, $type) => $q->where('type', $type))
            ->when($validated['origin'] ?? null, fn ($q, $origin) => match ($origin) {
                'kept' => $q->counted(),
                'linked' => $q->where('origin', '!=', AssetOrigin::Gallery->value),
                default => $q->where('origin', $origin),
            })
            ->when($request->boolean('permanent'), fn ($q) => $q->permanent())
            ->with(['uploader:id,name', 'message.conversation'])
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

        $file = $request->file('file');

        // Checked ahead of validation so the named types keep their own,
        // more specific refusal — the allow-list below would otherwise answer
        // first with the generic "unsupported type" for a file the product has
        // a real sentence about.
        //
        // ⚠️ Against BOTH extensions now. It used to read only
        // getClientOriginalExtension(), which is the name the browser sent, so
        // uploading `payload.html` as "invoice.png" walked straight past a list
        // whose entire purpose was to stop that file.
        $claimed = $file ? strtolower($file->getClientOriginalExtension()) : '';
        $detected = $file ? strtolower((string) $file->guessExtension()) : '';
        $blocked = (array) config('gallery.blocked_extensions', []);

        if ($file && (in_array($claimed, $blocked, true) || in_array($detected, $blocked, true))) {
            return response()->json([
                'message' => 'Este tipo de arquivo não pode ser guardado na galeria.',
                'code' => 'blocked_file_type',
                'errors' => ['file' => ['Este tipo de arquivo não pode ser guardado na galeria.']],
            ], 422);
        }

        // ⚠️ The allow-list is what actually decides this, and it matches on
        // the file's content rather than on its name. A gallery asset is served
        // from our own domain, so an HTML or SVG file here became a live page on
        // the origin that also serves the dashboard and /webmin. See UploadPolicy.
        $request->validate([
            'file' => UploadPolicy::rules($maxKb),
            'name' => ['nullable', 'string', 'max:250'],
        ], [
            'file.mimes' => UploadPolicy::message(),
        ]);

        $tenant = $request->user()->tenant;

        try {
            $asset = $this->gallery->store($tenant, $file, $request->user(), $request->input('name'));
        } catch (GalleryQuotaExceededException $e) {
            return $this->quotaExceeded($e);
        }

        // Not a before/after count: storing a file the gallery already listed
        // as a flow upload creates one row and removes the linked one.
        $created = $asset->wasRecentlyCreated;

        return response()->json([
            'data' => new GalleryAssetResource($asset->load('uploader:id,name')),
            'duplicate' => ! $created,
            'storage' => $this->storage->summary($tenant),
        ], $created ? 201 : 200);
    }

    /**
     * "Salvar na galeria": copy a linked file into the library so it is kept
     * past its message's retention. Counts against the quota, and is refused
     * with the same payload as an upload when there is no room.
     */
    public function keep(Request $request, int $id)
    {
        $asset = $this->find($request, $id);
        $tenant = $request->user()->tenant;

        if (! $asset->isLinked()) {
            return response()->json([
                'data' => new GalleryAssetResource($asset->load('uploader:id,name')),
                'storage' => $this->storage->summary($tenant),
            ]);
        }

        try {
            $kept = $this->gallery->keep($asset, $request->user());
        } catch (GalleryQuotaExceededException $e) {
            return $this->quotaExceeded($e);
        } catch (\RuntimeException) {
            return response()->json([
                'message' => 'Este arquivo não está mais disponível para ser guardado.',
                'code' => 'gallery_source_missing',
            ], 422);
        }

        return response()->json([
            'data' => new GalleryAssetResource($kept->load('uploader:id,name')),
            'storage' => $this->storage->summary($tenant),
        ], 201);
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
        $linked = $asset->isLinked();

        $this->gallery->delete($asset);

        return response()->json([
            // A linked file is only taken off the list; whatever uploaded it
            // still has it. The sentence says which of the two happened.
            'message' => $linked ? 'Arquivo ocultado da galeria.' : 'Arquivo removido da galeria.',
            'storage' => $this->storage->summary($request->user()->tenant),
        ]);
    }

    private function quotaExceeded(GalleryQuotaExceededException $e)
    {
        return response()->json([
            'message' => 'A galeria está sem espaço. Libere arquivos ou contrate mais armazenamento.',
            'code' => 'gallery_quota_exceeded',
            'used_bytes' => $e->usedBytes,
            'limit_bytes' => $e->limitBytes,
            'required_bytes' => $e->requestedBytes,
            'shortfall_bytes' => $e->shortfallBytes(),
        ], 422);
    }

    private function find(Request $request, int $id): GalleryAsset
    {
        return GalleryAsset::forTenant($request->user()->tenant_id)->findOrFail($id);
    }
}

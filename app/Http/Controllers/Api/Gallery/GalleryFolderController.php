<?php

namespace App\Http\Controllers\Api\Gallery;

use App\Exceptions\Gallery\GalleryFolderException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Gallery\GalleryFolderResource;
use App\Models\GalleryFolder;
use App\Services\Gallery\GalleryFolderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Folders in the media library: the tree, and the four things done to it.
 *
 * Every refusal carries a stable `code` (see GalleryFolderException) so the
 * dashboard can say it in the reader's language, and an `errors` entry under
 * the field that caused it.
 */
class GalleryFolderController extends Controller
{
    public function __construct(
        private readonly GalleryFolderService $folders,
    ) {}

    /** The whole tree, flat, by name. */
    public function index(Request $request)
    {
        $folders = GalleryFolder::forTenant($request->user()->tenant_id)
            ->withCount('assets')
            ->orderBy('name')
            ->get();

        return GalleryFolderResource::collection($folders)->additional([
            'limits' => [
                'max_depth' => GalleryFolderService::MAX_DEPTH,
                'max_folders' => GalleryFolderService::MAX_FOLDERS,
            ],
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:'.GalleryFolderService::MAX_NAME_LENGTH],
            'parent_id' => ['nullable', 'integer', $this->ownFolder($request)],
        ]);

        $parent = isset($validated['parent_id']) ? $this->find($request, (int) $validated['parent_id']) : null;

        try {
            $folder = $this->folders->create($request->user()->tenant, $validated['name'], $parent, $request->user());
        } catch (GalleryFolderException $e) {
            return $this->refused($e);
        }

        return response()->json(['data' => new GalleryFolderResource($folder)], 201);
    }

    /**
     * Rename and/or move. `parent_id` present-and-null means "to the top
     * level"; absent means "where it is".
     */
    public function update(Request $request, int $id)
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:'.GalleryFolderService::MAX_NAME_LENGTH],
            'parent_id' => ['sometimes', 'nullable', 'integer', $this->ownFolder($request)],
        ]);

        $folder = $this->find($request, $id);
        $move = array_key_exists('parent_id', $validated);
        $parent = $move && $validated['parent_id'] !== null ? $this->find($request, (int) $validated['parent_id']) : null;

        try {
            $folder = $this->folders->update($folder, $validated['name'] ?? null, $move, $parent);
        } catch (GalleryFolderException $e) {
            return $this->refused($e);
        }

        return response()->json([
            'data' => new GalleryFolderResource($folder->loadCount('assets')),
        ]);
    }

    /** Remove the folder; what it held moves up a level. Never deletes a file. */
    public function destroy(Request $request, int $id)
    {
        $moved = $this->folders->delete($this->find($request, $id));

        return response()->json([
            'message' => 'Pasta excluída. O conteúdo foi movido para o nível acima.',
            'moved' => $moved,
        ]);
    }

    private function refused(GalleryFolderException $e): JsonResponse
    {
        return response()->json([
            'message' => $e->getMessage(),
            'code' => $e->errorCode,
            'errors' => [$e->field => [$e->getMessage()]],
        ], 422);
    }

    private function ownFolder(Request $request)
    {
        return Rule::exists('gallery_folders', 'id')->where('tenant_id', $request->user()->tenant_id);
    }

    private function find(Request $request, int $id): GalleryFolder
    {
        return GalleryFolder::forTenant($request->user()->tenant_id)->findOrFail($id);
    }
}

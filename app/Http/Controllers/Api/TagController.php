<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TagResource;
use App\Models\Tag;
use Illuminate\Http\Request;

class TagController extends Controller
{
    public function index()
    {
        $tags = Tag::where('tenant_id', request()->user()->tenant_id)->orderBy('created_at', 'DESC')->get();

        return response()->json([
            'data' => $tags->toResourceCollection(TagResource::class),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'color' => ['nullable', 'hex_color', 'max:7'],
        ]);

        $tag = Tag::create(array_merge($validated, ['tenant_id' => request()->user()->tenant_id]));

        return response()->json([
            'message' => 'Tag created successfully',
            'data' => $tag->toResource(TagResource::class),
        ], 201);
    }

    public function update(int $id, Request $request)
    {
        $tag = Tag::where('tenant_id', request()->user()->tenant_id)->findOrFail($id);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'color' => ['nullable', 'hex_color', 'max:7'],
        ]);

        $tag->update($validated);

        return response()->json([
            'message' => 'Tag updated successfully',
            'data' => $tag->toResource(TagResource::class),
        ], 200);
    }

    public function destroy(int $id)
    {
        $tag = Tag::where('tenant_id', request()->user()->tenant_id)->findOrFail($id);

        $tag->delete();

        return response()->json([
            'message' => 'Tag deleted successfully',
        ], 200);
    }
}

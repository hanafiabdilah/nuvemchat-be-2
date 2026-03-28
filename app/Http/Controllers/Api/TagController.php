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
        $tags = request()->user()->tags()->get();

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

        $tag = request()->user()->tags()->create($validated);

        return response()->json([
            'message' => 'Tag created successfully',
            'data' => $tag->toResource(TagResource::class),
        ], 201);
    }

    public function update(int $id, Request $request)
    {
        $tag = Tag::where('user_id', request()->user()->id)->findOrFail($id);

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
        $tag = Tag::where('user_id', request()->user()->id)->findOrFail($id);

        $tag->delete();

        return response()->json([
            'message' => 'Tag deleted successfully',
        ], 200);
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ConversationResource;
use App\Http\Resources\MessageResource;
use App\Models\Conversation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ConversationController extends Controller
{
    public function index()
    {
        $conversations = Conversation::whereHas('connection', function($q){
            $q->where('user_id', Auth::id());
        })->get();

        return response()->json([
            'data' => $conversations->toResourceCollection(ConversationResource::class),
        ]);
    }

    public function show(int $id)
    {
        $conversation = Conversation::whereHas('connection', function($q){
            $q->where('user_id', Auth::id());
        })->findOrFail($id);

        return response()->json([
            'data' => $conversation->toResource(ConversationResource::class),
        ]);
    }

    public function messages(Request $request, int $id)
    {
        $page = $request->query('page', 1);
        $per_page = min($request->query('per_page', 100), 1000);

        $conversation = Conversation::whereHas('connection', function($q){
            $q->where('user_id', Auth::id());
        })->findOrFail($id);

        $messages = $conversation->messages()->orderBy('created_at', 'DESC')->paginate($per_page, ['*'], 'page', $page);

        return MessageResource::collection($messages)->response();
    }
}

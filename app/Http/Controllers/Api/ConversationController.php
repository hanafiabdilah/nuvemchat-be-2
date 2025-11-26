<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ConversationResource;
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
}

<?php

namespace App\Http\Controllers\Api;

use App\Events\ConversationUpdated;
use App\Http\Controllers\Controller;
use App\Http\Resources\ConversationResource;
use App\Http\Resources\MessageResource;
use App\Models\Conversation;
use App\Services\Message\MessageService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class ConversationController extends Controller
{
    public function index()
    {
        $conversations = Conversation::with('contact')->whereHas('connection', function($q){
            $q->where('user_id', Auth::id());
        })->orderBy('last_message_at', 'DESC')->orderBy('id', 'DESC')->cursorPaginate(50);

        return ConversationResource::collection($conversations)->response();
    }

    public function show(int $id)
    {
        $conversation = Conversation::with('contact')->whereHas('connection', function($q){
            $q->where('user_id', Auth::id());
        })->findOrFail($id);

        return response()->json([
            'data' => $conversation->toResource(ConversationResource::class),
        ]);
    }

    public function messages(int $id)
    {
        $conversation = Conversation::whereHas('connection', function($q){
            $q->where('user_id', Auth::id());
        })->findOrFail($id);

        $messages = $conversation->messages()->orderBy('created_at', 'DESC')->orderBy('id', 'DESC')->cursorPaginate(50);

        return MessageResource::collection($messages)->response();
    }

    public function read(int $id)
    {
        $conversation = Conversation::whereHas('connection', function($q){
            $q->where('user_id', Auth::id());
        })->findOrFail($id);

        $conversation->messages()->whereNull('read_at')->update(['read_at' => now()]);
        broadcast(new ConversationUpdated($conversation));

        return response()->json([
            'message' => 'Conversation marked as read',
        ]);
    }

    public function sendMessage(Request $request, int $id)
    {
        $conversation = Conversation::whereHas('connection', function($q){
            $q->where('user_id', Auth::id());
        })->findOrFail($id);

        $messageService = new MessageService();

        try {
            $message = $messageService->sendMessage($conversation, $request->all());

            broadcast(new ConversationUpdated($conversation));

            return response()->json([
                'data' => new MessageResource($message),
            ]);
        } catch(ValidationException $th){
            throw $th;
        } catch (\Throwable $th) {
            return response()->json([
                'message' => 'Failed to send message',
            ], 500);
        }

    }
}

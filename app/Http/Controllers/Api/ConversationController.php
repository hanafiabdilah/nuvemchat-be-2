<?php

namespace App\Http\Controllers\Api;

use App\Enums\Conversation\Status;
use App\Enums\Message\MessageType;
use App\Enums\Message\SenderType;
use App\Events\ConversationUpdated;
use App\Events\MessageReceived;
use App\Http\Controllers\Controller;
use App\Http\Resources\ConversationResource;
use App\Http\Resources\MessageResource;
use App\Models\Conversation;
use App\Models\Tag;
use App\Services\Message\MessageService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ConversationController extends Controller
{
    public function index(Request $request)
    {
        $since = $request->input('since');

        $conversations = Conversation::with('contact')->whereHas('connection', function($q){
            $q->where('tenant_id', Auth::user()->tenant_id);
        })->where('updated_at', '>', $since)->orderBy('last_message_at', 'DESC')->orderBy('id', 'DESC')->get();

        return response()->json([
            'data' => ConversationResource::collection($conversations),
            'server_time' => now()->toIso8601String(),
        ]);
    }

    public function show(int $id)
    {
        $conversation = Conversation::with('contact')->whereHas('connection', function($q){
            $q->where('tenant_id', Auth::user()->tenant_id);
        })->findOrFail($id);

        return response()->json([
            'data' => $conversation->toResource(ConversationResource::class),
        ]);
    }

    public function messages(int $id)
    {
        $conversation = Conversation::whereHas('connection', function($q){
            $q->where('tenant_id', Auth::user()->tenant_id);
        })->findOrFail($id);

        $messages = $conversation->messages()->orderBy('created_at', 'DESC')->orderBy('id', 'DESC')->get();

        return MessageResource::collection($messages)->response();
    }

    public function read(int $id)
    {
        $conversation = Conversation::whereHas('connection', function($q){
            $q->where('tenant_id', Auth::user()->tenant_id);
        })->findOrFail($id);

        if(Auth::user()->role === 'agent' && $conversation->user_id !== Auth::id()){
            return response()->json([
                'message' => 'Unauthorized',
            ], 403);
        }

        $conversation->messages()->where('sender_type', SenderType::Incoming)->whereNull('read_at')->update(['read_at' => now()]);
        broadcast(new ConversationUpdated($conversation));

        return response()->json([
            'message' => 'Conversation marked as read',
        ]);
    }

    public function sendMessage(Request $request, int $id)
    {
        $conversation = Conversation::whereHas('connection', function($q){
            $q->where('tenant_id', Auth::user()->tenant_id);
        })->findOrFail($id);

        if(Auth::user()->role === 'agent' && $conversation->user_id !== Auth::id()){
            return response()->json([
                'message' => 'Unauthorized',
            ], 403);
        }

        if($conversation->status !== Status::Active){
            return response()->json([
                'message' => 'Conversation is not active',
            ], 400);
        }

        $messageService = new MessageService();

        try {
            $message = $messageService->sendMessage($conversation, $request->all());

            broadcast(new ConversationUpdated($conversation));
            broadcast(new MessageReceived($message));

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

    public function sendImage(Request $request, int $id)
    {
        $conversation = Conversation::whereHas('connection', function($q){
            $q->where('tenant_id', Auth::user()->tenant_id);
        })->findOrFail($id);

        if(Auth::user()->role === 'agent' && $conversation->user_id !== Auth::id()){
            return response()->json([
                'message' => 'Unauthorized',
            ], 403);
        }

        if($conversation->status !== Status::Active){
            return response()->json([
                'message' => 'Conversation is not active',
            ], 400);
        }

        $messageService = new MessageService();

        try {
            $message = $messageService->sendImage($conversation, $request->all());

            broadcast(new ConversationUpdated($conversation));
            broadcast(new MessageReceived($message));

            return response()->json([
                'data' => new MessageResource($message),
            ]);
        } catch(ValidationException $th){
            throw $th;
        } catch (\Throwable $th) {
            return response()->json([
                'message' => 'Failed to send image',
            ], 500);
        }
    }

    public function sendAudio(Request $request, int $id)
    {
        $conversation = Conversation::whereHas('connection', function($q){
            $q->where('tenant_id', Auth::user()->tenant_id);
        })->findOrFail($id);

        if(Auth::user()->role === 'agent' && $conversation->user_id !== Auth::id()){
            return response()->json([
                'message' => 'Unauthorized',
            ], 403);
        }

        if($conversation->status !== Status::Active){
            return response()->json([
                'message' => 'Conversation is not active',
            ], 400);
        }

        $messageService = new MessageService();

        try {
            $message = $messageService->sendAudio($conversation, $request->all());

            broadcast(new ConversationUpdated($conversation));
            broadcast(new MessageReceived($message));

            return response()->json([
                'data' => new MessageResource($message),
            ]);
        } catch(ValidationException $th){
            throw $th;
        } catch (\Throwable $th) {
            return response()->json([
                'message' => 'Failed to send audio',
            ], 500);
        }
    }

    public function sendVideo(Request $request, int $id)
    {
        $conversation = Conversation::whereHas('connection', function($q){
            $q->where('tenant_id', Auth::user()->tenant_id);
        })->findOrFail($id);

        if(Auth::user()->role === 'agent' && $conversation->user_id !== Auth::id()){
            return response()->json([
                'message' => 'Unauthorized',
            ], 403);
        }

        if($conversation->status !== Status::Active){
            return response()->json([
                'message' => 'Conversation is not active',
            ], 400);
        }

        $messageService = new MessageService();

        try {
            $message = $messageService->sendVideo($conversation, $request->all());

            broadcast(new ConversationUpdated($conversation));
            broadcast(new MessageReceived($message));

            return response()->json([
                'data' => new MessageResource($message),
            ]);
        } catch(ValidationException $th){
            throw $th;
        } catch (\Throwable $th) {
            return response()->json([
                'message' => 'Failed to send video',
            ], 500);
        }
    }

    public function sendDocument(Request $request, int $id)
    {
        $conversation = Conversation::whereHas('connection', function($q){
            $q->where('tenant_id', Auth::user()->tenant_id);
        })->findOrFail($id);

        if(Auth::user()->role === 'agent' && $conversation->user_id !== Auth::id()){
            return response()->json([
                'message' => 'Unauthorized',
            ], 403);
        }

        if($conversation->status !== Status::Active){
            return response()->json([
                'message' => 'Conversation is not active',
            ], 400);
        }

        $messageService = new MessageService();

        try {
            $message = $messageService->sendDocument($conversation, $request->all());

            broadcast(new ConversationUpdated($conversation));
            broadcast(new MessageReceived($message));

            return response()->json([
                'data' => new MessageResource($message),
            ]);
        } catch(ValidationException $th){
            throw $th;
        } catch (\Throwable $th) {
            return response()->json([
                'message' => 'Failed to send document',
            ], 500);
        }
    }

    public function accept(int $id)
    {
        $conversation = Conversation::whereHas('connection', function($q){
            $q->where('tenant_id', Auth::user()->tenant_id);
        })->findOrFail($id);

        if($conversation->status !== Status::Pending){
            return response()->json([
                'message' => 'Conversation is not pending',
            ], 400);
        }

        $conversation->user_id = Auth::id();
        $conversation->status = Status::Active;
        $conversation->save();

        broadcast(new ConversationUpdated($conversation));

        return response()->json([
            'message' => 'Conversation accepted',
        ]);
    }

    public function resolve(int $id)
    {
        $conversation = Conversation::whereHas('connection', function($q){
            $q->where('tenant_id', Auth::user()->tenant_id);
        })->findOrFail($id);

        if(Auth::user()->role === 'agent' && $conversation->user_id !== Auth::id()){
            return response()->json([
                'message' => 'Unauthorized',
            ], 403);
        }

        if($conversation->status !== Status::Active){
            return response()->json([
                'message' => 'Conversation is not active',
            ], 400);
        }

        $conversation->status = Status::Resolved;
        $conversation->save();

        broadcast(new ConversationUpdated($conversation));

        return response()->json([
            'message' => 'Conversation resolved',
        ]);
    }

    public function syncTags(int $id, Request $request)
    {
        $conversation = Conversation::whereHas('connection', function($q){
            $q->where('tenant_id', Auth::user()->tenant_id);
        })->findOrFail($id);

        if(Auth::user()->role === 'agent' && $conversation->user_id !== Auth::id()){
            return response()->json([
                'message' => 'Unauthorized',
            ], 403);
        }

        if($conversation->status !== Status::Active){
            return response()->json([
                'message' => 'Conversation is not active',
            ], 400);
        }

        $tags = $request->input('tags', []);

        $validTagIds = Tag::where('tenant_id', Auth::user()->tenant_id)
            ->whereIn('id', $tags)
            ->pluck('id')
            ->toArray();

        $conversation->tags()->sync($validTagIds);

        broadcast(new ConversationUpdated($conversation));

        return response()->json([
            'message' => 'Conversation tags updated',
        ]);
    }
}

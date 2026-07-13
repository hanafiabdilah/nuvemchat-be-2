<?php

namespace App\Http\Controllers\Api;

use App\Enums\Connection\Channel;
use App\Enums\Conversation\Status;
use App\Enums\Message\MessageType;
use App\Enums\Message\SenderType;
use App\Events\ConversationUpdated;
use App\Events\MessageReceived;
use App\Http\Controllers\Controller;
use App\Http\Resources\ConversationResource;
use App\Http\Resources\MessageResource;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Tag;
use App\Services\AutomatedMessageService;
use App\Services\Message\MessageService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ConversationController extends Controller
{
    public function index(Request $request)
    {
        $since = $request->input('since');

        $conversations = Conversation::with('contact')->whereHas('connection', function($q){
            $q->where('tenant_id', Auth::user()->tenant_id);
        })
            // Skip conversations with no message yet (e.g. a Live Chat Widget session
            // that was opened but the visitor never typed) — they would otherwise show
            // as empty rows with a null "1970" date in the list.
            ->whereHas('messages')
            ->where('updated_at', '>', $since)->orderBy('last_message_at', 'DESC')->orderBy('id', 'DESC')->get();

        return response()->json([
            'data' => ConversationResource::collection($conversations),
            'server_time' => now()->toIso8601String(),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'contact_id' => 'required|integer|exists:contacts,id',
            'connection_id' => 'required|integer|exists:connections,id',
            'message' => 'required|string|max:5000',
        ]);

        // Verify contact belongs to the same tenant
        $contact = Contact::where('id', $validated['contact_id'])
            ->where('tenant_id', Auth::user()->tenant_id)
            ->firstOrFail();

        // Verify connection belongs to the same tenant
        $connection = Connection::where('id', $validated['connection_id'])
            ->where('tenant_id', Auth::user()->tenant_id)
            ->firstOrFail();

        if(!Auth::user()->hasRole('owner') && !$connection->users->contains(Auth::id())){
            return response()->json([
                'message' => 'Unauthorized',
            ], 403);
        }

        // Check if an open conversation (active / pending / AI-handling) already exists
        $existingConversation = Conversation::where('contact_id', $contact->id)
            ->where('connection_id', $connection->id)
            ->whereIn('status', [Status::Active, Status::Pending, Status::AiHandling])
            ->first();

        if ($existingConversation) {
            return response()->json([
                'message' => 'Active conversation already exists for this contact and connection',
                'data' => new ConversationResource($existingConversation->load('contact')),
            ], 409);
        }

        // Get last conversation to retrieve external_id
        $lastConversation = Conversation::where('contact_id', $contact->id)
            ->where('connection_id', $connection->id)
            ->orderBy('created_at', 'DESC')
            ->first();

        // Determine external_id based on channel
        $externalId = null;

        if ($lastConversation) {
            // Use external_id from last conversation
            $externalId = $lastConversation->external_id;
        } else {
            // No previous conversation - handle based on channel
            if (in_array($connection->channel, [Channel::WhatsappWApi, Channel::WhatsappProxyhub], true)) {
                // For W-API / ProxyHub, use contact's external_id (phone number)
                $externalId = $contact->external_id;
            } else {
                // For other channels, we need a previous conversation
                return response()->json([
                    'message' => 'No previous conversation found for this contact and connection. Cannot create new conversation without external_id reference.',
                ], 400);
            }
        }

        try {
            // Create new conversation
            $conversation = Conversation::create([
                'contact_id' => $contact->id,
                'connection_id' => $connection->id,
                'external_id' => $externalId,
                'user_id' => Auth::id(),
                'status' => Status::Active,
                'last_message_at' => now(),
            ]);

            // Send the message
            $messageService = new MessageService();
            $message = $messageService->sendMessage($conversation, [
                'message' => $validated['message']
            ]);

            $message?->update(['sent_by_user_id' => Auth::id()]);

            // Broadcast events
            broadcast(new MessageReceived($message));
            broadcast(new ConversationUpdated($conversation->load('contact')));

            return response()->json([
                'message' => 'Conversation created and message sent successfully',
                'data' => new ConversationResource($conversation->load('contact')),
                'message' => new MessageResource($message),
            ], 201);
        } catch(ValidationException $th){
            throw $th;
        } catch (\Throwable $th) {
            Log::error('ConversationController: Failed to create conversation or send message', [
                'contact_id' => $contact->id,
                'connection_id' => $connection->id,
                'error' => $th->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to create conversation or send message',
            ], 500);
        }
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

    /**
     * Return the {{variable}} tokens available for a conversation, so the chat
     * composer can live-resolve them. Includes the flow's collected variables
     * (state_data, internal underscore-prefixed keys excluded) plus a few
     * contact/conversation fields, keyed to match the flow template convention
     * (bare key = flow variable; contact.*, conversation.*).
     */
    public function variables(int $id)
    {
        $conversation = Conversation::with('contact')->whereHas('connection', function ($q) {
            $q->where('tenant_id', Auth::user()->tenant_id);
        })->findOrFail($id);

        // Latest flow run for this conversation (state is preserved after it ends).
        $flowState = $conversation->flowState()->latest('id')->first();

        $variables = collect($flowState?->state_data ?? [])
            ->reject(fn ($value, $key) => str_starts_with((string) $key, '_'))
            ->map(fn ($value) => is_array($value) ? json_encode($value) : $value)
            ->all();

        $variables['contact.name'] = $conversation->contact?->name;
        $variables['contact.username'] = $conversation->contact?->username;
        $variables['contact.phone'] = $conversation->contact?->external_id;
        $variables['conversation.status'] = $conversation->status?->value;

        // Drop null-valued fields so the composer only offers resolvable tokens.
        $variables = collect($variables)->reject(fn ($value) => $value === null)->all();

        return response()->json(['data' => $variables]);
    }

    public function messages(int $id)
    {
        $conversation = Conversation::whereHas('connection', function($q){
            $q->where('tenant_id', Auth::user()->tenant_id);
        })->findOrFail($id);

        $messages = $conversation->messages()
            ->with(['sentByUser', 'sentByFlow', 'sentByAiHubAgent'])
            ->orderBy('created_at', 'DESC')->orderBy('id', 'DESC')->get();

        return MessageResource::collection($messages)->response();
    }

    public function sendInteractive(Request $request, int $id)
    {
        $conversation = Conversation::whereHas('connection', function($q){
            $q->where('tenant_id', Auth::user()->tenant_id);
        })->findOrFail($id);

        if(!Auth::user()->hasRole('owner') && $conversation->user_id !== Auth::id()){
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if($conversation->status !== Status::Active){
            return response()->json(['message' => 'Conversation is not active'], 400);
        }

        try {
            $message = (new MessageService())->sendInteractive($conversation, $request->all());

            $message?->update(['sent_by_user_id' => Auth::id()]);

            broadcast(new ConversationUpdated($conversation));
            broadcast(new MessageReceived($message));

            return response()->json([
                'data' => new MessageResource($message),
            ]);
        } catch(ValidationException $th){
            throw $th;
        } catch (\Throwable $th) {
            return response()->json([
                'message' => 'Failed to send interactive message: ' . $th->getMessage(),
            ], 500);
        }
    }

    public function read(int $id)
    {
        $conversation = Conversation::whereHas('connection', function($q){
            $q->where('tenant_id', Auth::user()->tenant_id);
        })->findOrFail($id);

        $conversation->messages()->where('sender_type', SenderType::Incoming)->whereNull('read_at')->update(['read_at' => now()]);
        broadcast(new ConversationUpdated($conversation));

        // Best-effort: reflect the read receipt to the customer on WhatsApp
        // Cloud API (blue ticks). No-op for other channels; never fatal.
        try {
            (new MessageService())->markAsRead($conversation);
        } catch (\Throwable $th) {
            Log::warning('Failed to send WhatsApp read receipt', [
                'conversation_id' => $conversation->id,
                'error' => $th->getMessage(),
            ]);
        }

        return response()->json([
            'message' => 'Conversation marked as read',
        ]);
    }

    /**
     * Emit a typing indicator to the customer (WhatsApp Cloud API). Called by
     * the composer while an agent is typing. Best-effort and no-op for channels
     * without native typing support.
     */
    public function typing(int $id)
    {
        $conversation = Conversation::whereHas('connection', function($q){
            $q->where('tenant_id', Auth::user()->tenant_id);
        })->findOrFail($id);

        if(!Auth::user()->hasRole('owner') && $conversation->user_id !== Auth::id()){
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        try {
            (new MessageService())->markAsRead($conversation, typing: true);
        } catch (\Throwable $th) {
            Log::warning('Failed to send WhatsApp typing indicator', [
                'conversation_id' => $conversation->id,
                'error' => $th->getMessage(),
            ]);
        }

        return response()->json(['message' => 'ok']);
    }

    public function sendMessage(Request $request, int $id)
    {
        $conversation = Conversation::whereHas('connection', function($q){
            $q->where('tenant_id', Auth::user()->tenant_id);
        })->findOrFail($id);

        if(!Auth::user()->hasRole('owner') && $conversation->user_id !== Auth::id()){
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

            $message?->update(['sent_by_user_id' => Auth::id()]);

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

        if(!Auth::user()->hasRole('owner') && $conversation->user_id !== Auth::id()){
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

            $message?->update(['sent_by_user_id' => Auth::id()]);

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

        if(!Auth::user()->hasRole('owner') && $conversation->user_id !== Auth::id()){
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

            $message?->update(['sent_by_user_id' => Auth::id()]);

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

        if(!Auth::user()->hasRole('owner') && $conversation->user_id !== Auth::id()){
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

            $message?->update(['sent_by_user_id' => Auth::id()]);

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

        if(!Auth::user()->hasRole('owner') && $conversation->user_id !== Auth::id()){
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

            $message?->update(['sent_by_user_id' => Auth::id()]);

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

        // An agent can pick up a conversation from the unassigned Pending queue
        // or take it over from the AI while it is being handled.
        if(!in_array($conversation->status, [Status::Pending, Status::AiHandling], true)){
            return response()->json([
                'message' => 'Conversation is not pending',
            ], 400);
        }

        $wasAiHandling = $conversation->status === Status::AiHandling;

        $conversation->user_id = Auth::id();
        $conversation->status = Status::Active;
        $conversation->needs_human = false;
        $conversation->save();

        // Taking over from the AI: stop the running flow so it no longer auto-replies.
        if ($wasAiHandling) {
            (new \App\Services\Flow\FlowExecutor())->stopFlow($conversation);
        }

        broadcast(new ConversationUpdated($conversation));

        // Send accept message AFTER broadcasting conversation update
        $automatedMessageService = new AutomatedMessageService();
        $acceptMessage = $automatedMessageService->getAcceptMessage($conversation->connection, Auth::user());

        if ($acceptMessage) {
            try {
                $messageService = new MessageService();
                $acceptMsg = $messageService->sendMessage($conversation, ['message' => $acceptMessage]);

                $acceptMsg?->update(['sent_by_user_id' => Auth::id()]);

                if ($acceptMsg) {
                    broadcast(new MessageReceived($acceptMsg));
                    broadcast(new ConversationUpdated($acceptMsg->conversation));
                }
            } catch (\Throwable $th) {
                Log::error('ConversationController: Failed to send accept message', [
                    'conversation_id' => $conversation->id,
                    'error' => $th->getMessage(),
                ]);
            }
        }

        return response()->json([
            'message' => 'Conversation accepted',
        ]);
    }

    public function resolve(int $id)
    {
        $conversation = Conversation::whereHas('connection', function($q){
            $q->where('tenant_id', Auth::user()->tenant_id);
        })->findOrFail($id);

        if(!Auth::user()->hasRole('owner') && $conversation->user_id !== Auth::id()){
            return response()->json([
                'message' => 'Unauthorized',
            ], 403);
        }

        if($conversation->status !== Status::Active){
            return response()->json([
                'message' => 'Conversation is not active',
            ], 400);
        }

        // Send closing message before resolving
        $automatedMessageService = new AutomatedMessageService();
        $closingMessage = $automatedMessageService->getClosingMessage($conversation->connection, Auth::user());

        $closingMsg = null;
        if ($closingMessage) {
            try {
                $messageService = new MessageService();
                $closingMsg = $messageService->sendMessage($conversation, ['message' => $closingMessage]);
                $closingMsg?->update(['sent_by_user_id' => Auth::id()]);
            } catch (\Throwable $th) {
                Log::error('ConversationController: Failed to send closing message', [
                    'conversation_id' => $conversation->id,
                    'error' => $th->getMessage(),
                ]);
            }
        }

        $conversation->status = Status::Resolved;
        $conversation->save();

        broadcast(new ConversationUpdated($conversation));

        // Broadcast closing message AFTER conversation status update
        if ($closingMsg) {
            broadcast(new MessageReceived($closingMsg));
            broadcast(new ConversationUpdated($closingMsg->conversation));
        }

        return response()->json([
            'message' => 'Conversation resolved',
        ]);
    }

    public function syncTags(int $id, Request $request)
    {
        $conversation = Conversation::whereHas('connection', function($q){
            $q->where('tenant_id', Auth::user()->tenant_id);
        })->findOrFail($id);

        if(!Auth::user()->hasRole('owner') && $conversation->user_id !== Auth::id()){
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

    public function editMessage(int $id, int $message_id, Request $request)
    {
        $conversation = Conversation::whereHas('connection', function($q){
            $q->where('tenant_id', Auth::user()->tenant_id);
        })->findOrFail($id);

        if(!Auth::user()->hasRole('owner') && $conversation->user_id !== Auth::id()){
            return response()->json([
                'message' => 'Unauthorized',
            ], 403);
        }

        if($conversation->status !== Status::Active){
            return response()->json([
                'message' => 'Conversation is not active',
            ], 400);
        }

        $message = $conversation->messages()->where('id', $message_id)->firstOrFail();

        if($message->sender_type !== SenderType::Outgoing){
            return response()->json([
                'message' => 'Only outgoing messages can be edited',
            ], 400);
        }

        try {
            $messageService = new MessageService();
            $editedMessage = $messageService->editMessage($message, $request->all());

            broadcast(new ConversationUpdated($conversation));
            broadcast(new MessageReceived($editedMessage));

            return response()->json([
                'data' => new MessageResource($editedMessage),
            ]);
        } catch(ValidationException $th){
            throw $th;
        } catch (\Throwable $th) {
            return response()->json([
                'message' => 'Failed to edit message',
            ], 500);
        }
    }

    public function deleteMessage(int $id, int $message_id)
    {
        $conversation = Conversation::whereHas('connection', function($q){
            $q->where('tenant_id', Auth::user()->tenant_id);
        })->findOrFail($id);

        if(!Auth::user()->hasRole('owner') && $conversation->user_id !== Auth::id()){
            return response()->json([
                'message' => 'Unauthorized',
            ], 403);
        }

        if($conversation->status !== Status::Active){
            return response()->json([
                'message' => 'Conversation is not active',
            ], 400);
        }

        $message = $conversation->messages()->where('id', $message_id)->firstOrFail();

        if($message->sender_type !== SenderType::Outgoing){
            return response()->json([
                'message' => 'Only outgoing messages can be deleted',
            ], 400);
        }

        try {
            $messageService = new MessageService();
            $messageService->deleteMessage($message);

            broadcast(new ConversationUpdated($conversation));

            return response()->json([
                'data' => new MessageResource($message),
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'message' => $th->getMessage(),
            ], 500);
        }
    }
}

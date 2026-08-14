<?php

namespace App\Http\Controllers\Api;

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status as ConnectionStatus;
use App\Enums\Conversation\Status;
use App\Enums\Message\MessageType;
use App\Enums\Message\SenderType;
use App\Events\ConversationTransferred;
use App\Events\ConversationUpdated;
use App\Events\MessageReceived;
use App\Exceptions\ConnectionException;
use App\Http\Controllers\Controller;
use App\Http\Resources\ContactResource;
use App\Http\Resources\ConversationResource;
use App\Http\Resources\MessageResource;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Tag;
use App\Services\AutomatedMessageService;
use App\Services\Conversation\OutboundConversationResolver;
use App\Services\Message\Handlers\EmailHandler;
use App\Services\Message\MessageService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ConversationController extends Controller
{
    public function index(Request $request)
    {
        // Parsed, not passed through as the ISO-8601 string the client sends: the
        // bound value has to be in the driver's own datetime format, or the
        // comparison below is a plain text comparison that never matches.
        $since = rescue(
            fn () => filled($request->input('since')) ? Carbon::parse($request->input('since')) : null,
            null,
            report: false,
        );
        $before = $request->input('before');
        $connectionId = $request->input('connection_id');
        $limit = (int) $request->input('limit', 100);
        $limit = max(1, min($limit, 500));

        // Stamped before the queries run, not after: anything that changes while
        // this request is being served then falls after the cursor and is picked
        // up by the next delta (a re-sent row is harmless — the client upserts).
        $serverTime = now()->toIso8601String();

        // Eager-load everything ConversationResource touches (incl. the nested
        // MessageResource on last_message) — without this a 500-row sync page
        // explodes into thousands of lazy queries.
        $query = Conversation::with([
                'contact',
                'tags',
                'agent',
                'flowState.currentNode',
                'lastMessage.repliedMessage',
                'lastMessage.reactions.contact',
                'lastMessage.contact',
                'lastMessage.sentByUser',
                'lastMessage.sentByFlow',
                'lastMessage.sentByAiHubAgent',
                'lastMessage.conversation.connection',
            ])
            ->withCount(['messages as unread_count' => function ($q) {
                $q->where('sender_type', SenderType::Incoming)->whereNull('read_at');
            }, 'notes'])
            ->visibleTo(Auth::user())
            // Skip conversations with no message yet (e.g. a Live Chat Widget session
            // that was opened but the visitor never typed) — they would otherwise show
            // as empty rows with a null "1970" date in the list.
            ->whereHas('messages')
            // Removed groups stay on disk (restoring brings their history back)
            // but never appear in the panel. Written as "doesn't have a removed
            // contact" so conversations without a contact are unaffected.
            ->whereDoesntHave('contact', function ($q) {
                $q->whereNotNull('group_removed_at');
            })
            // Ordered by id (not last_message_at) so the `before` cursor is a plain
            // unique key — the client sorts by lastMessageAt locally from IndexedDB.
            ->orderBy('id', 'DESC');

        // Delta sync: only conversations touched since the last sync.
        if ($since !== null) {
            $query->where('updated_at', '>', $since);
        }

        // Optional: restrict to one connection. The client uses this to pull
        // the full history of a connection it was just granted access to,
        // without resetting the delta cursor it holds for the others.
        if (filled($connectionId)) {
            $query->where('connection_id', $connectionId);
        }

        // Cursor pagination. Client walks backwards by passing `before` =
        // smallest id it already holds, until has_more is false.
        if ($before !== null && $before !== '') {
            $query->where('id', '<', $before);
        }

        // Fetch one extra row to detect whether more conversations remain.
        $conversations = $query->limit($limit + 1)->get();
        $hasMore = $conversations->count() > $limit;
        $conversations = $conversations->take($limit);

        return response()->json([
            'data' => ConversationResource::collection($conversations),
            'removed_ids' => $this->removedGroupConversationIds($before),
            // The connections this user may hold locally, so the client can
            // drop the cache of any it no longer has. Sent on the first page
            // only, like removed_ids, and for the same reason: it is a
            // convergence statement about the whole cache, not a delta.
            'connection_ids' => $this->accessibleConnectionIds($before),
            'has_more' => $hasMore,
            'next_before' => $hasMore ? $conversations->last()?->id : null,
            'server_time' => $serverTime,
        ]);
    }

    /**
     * Every connection id this user is allowed to see, whether or not it has
     * any conversations.
     *
     * The client mirrors messages into IndexedDB, and IndexedDB survives having
     * access taken away — so revocation has to be an instruction the server
     * sends, not something the client is trusted to infer from an absence of
     * rows. Anything outside this list gets purged locally.
     *
     * @return array<int, string>|null  null on later pages (nothing to apply)
     */
    private function accessibleConnectionIds(?string $before): ?array
    {
        if ($before !== null && $before !== '') {
            return null;
        }

        $user = Auth::user();

        $ids = $user->canAccessAllConnections()
            ? $user->tenant->connections()->pluck('id')->all()
            : $user->accessibleConnectionIds();

        return array_map('strval', $ids);
    }

    /**
     * Conversations the client must drop: they belong to a group that was removed
     * from the inbox.
     *
     * The rows stay on disk (restoring brings the history back), so the delta
     * above — which only ever adds — can never tell a client about a removal.
     * The live `group-removed` event does that for whoever is connected at the
     * time; this is how everyone else finds out. Without it a panel that was
     * closed during the removal keeps the dead thread forever, since IndexedDB
     * survives the reload and the next sync only adds to it.
     *
     * Deliberately NOT filtered by the `since` cursor: this is the complete list
     * of what must not be in the panel, so a client converges on it no matter how
     * far its own bookkeeping has drifted — including one that synced straight
     * past a removal before this existed. It stays small (it is bounded by the
     * groups a tenant has removed, which is a handful), and the client applies it
     * to rows it mostly doesn't have, which costs nothing.
     *
     * @return array<int, string>
     */
    private function removedGroupConversationIds(?string $before): array
    {
        // Only on the first page — the client applies these once per sync, after
        // every page has landed.
        if ($before !== null && $before !== '') {
            return [];
        }

        return Conversation::query()
            ->visibleTo(Auth::user())
            ->whereHas('contact', function ($q) {
                $q->whereNotNull('group_removed_at');
            })
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->all();
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

        if(!Auth::user()->canAccessConnection($connection)){
            return response()->json([
                'message' => 'Unauthorized',
            ], 403);
        }

        // Channels where only the customer can open a conversation. Sending
        // first is refused outright rather than left to the session-window
        // guard: even inside an open window this endpoint would be starting a
        // second thread on a channel that never grants the business the first
        // word. WhatsApp Official re-engages through an approved template
        // instead (POST /api/templates/send).
        if (!$connection->channel->canStartConversation()) {
            return response()->json([
                'message' => 'This channel does not allow starting a conversation — the customer has to message first.',
                'code' => 'channel_cannot_start_conversation',
            ], 422);
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
            if ($connection->channel === Channel::WhatsappApiway) {
                // For API Way, use contact's external_id (phone number)
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

    /**
     * Compose and send a brand-new e-mail (no existing conversation). Creates
     * (or reuses) the recipient contact, opens an Active conversation on the
     * e-mail connection, sends via SMTP and stores the outgoing message. The
     * e-mail inbox has no accept step, so the conversation starts Active.
     */
    public function composeEmail(Request $request)
    {
        $validated = $request->validate([
            'connection_id' => ['required', 'integer'],
            'to' => ['required', 'email'],
            'subject' => ['nullable', 'string', 'max:255'],
            'message' => ['required_without:attachments', 'nullable', 'string'],
            'attachments' => ['nullable', 'array', 'max:20'],
            'attachments.*' => ['file', 'max:25600'],
        ]);

        $connection = Connection::where('id', $validated['connection_id'])
            ->where('tenant_id', Auth::user()->tenant_id)
            ->where('channel', Channel::Email)
            ->firstOrFail();

        if (!Auth::user()->canAccessConnection($connection)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if ($connection->status !== ConnectionStatus::Active) {
            return response()->json(['message' => 'Connection is not active'], 400);
        }

        $recipient = strtolower(trim($validated['to']));
        $subject = trim((string) ($validated['subject'] ?? ''));
        if ($subject === '') {
            $subject = '(no subject)';
        }

        $contact = Contact::createFromExternalData($connection, $recipient, $recipient);

        // Reuse an open thread with the same recipient+subject instead of always
        // creating a new one — composing twice must not fork the conversation
        // (the inbound sync threads replies into a single external_id too). The
        // resolver owns that key; see OutboundConversationResolver.
        $resolved = (new OutboundConversationResolver())->resolve($connection, $contact, emailSubject: $subject);

        $conversation = $resolved->conversation;
        $createdConversation = $resolved->wasCreated;

        try {
            $message = (new EmailHandler())->sendNewEmail(
                $conversation,
                $subject,
                (string) ($validated['message'] ?? ''),
                $request->file('attachments', [])
            );
        } catch (\Throwable $th) {
            // Roll back the empty conversation we just opened so a failed send
            // doesn't leave a dangling thread — but never delete a pre-existing
            // thread that was merely reused.
            if ($createdConversation) {
                $conversation->delete();
            }

            if ($th instanceof ValidationException) {
                throw $th;
            }
            if ($th instanceof ConnectionException) {
                $status = $th->getHttpStatusCode() ?: 502;
                return response()->json(['message' => $th->getMessage()], $status);
            }

            Log::error('ConversationController: Failed to compose email', [
                'connection_id' => $connection->id,
                'error' => $th->getMessage(),
            ]);

            return response()->json(['message' => 'Failed to send email'], 500);
        }

        $message?->update(['sent_by_user_id' => Auth::id()]);

        broadcast(new MessageReceived($message));
        broadcast(new ConversationUpdated($conversation->load('contact')));

        return response()->json([
            'data' => new ConversationResource($conversation->load('contact')),
            'sent_message' => new MessageResource($message),
        ], 201);
    }

    public function show(int $id)
    {
        $conversation = Conversation::with('contact')->visibleTo(Auth::user())->findOrFail($id);

        return response()->json([
            'data' => $conversation->toResource(ConversationResource::class),
        ]);
    }

    /**
     * The members of a group conversation, for the contact-details panel.
     *
     * Sourced from conversation_participants, which records everyone who has
     * sent a message here — silent members are therefore absent. That is a
     * deliberate trade: it behaves identically on every channel and costs no
     * external call, whereas only WhatsApp exposes a real roster endpoint.
     *
     * Served on its own route rather than folded into ConversationResource so
     * a 500-row sync page doesn't drag every group's membership with it.
     */
    public function participants(int $id)
    {
        $conversation = Conversation::visibleTo(Auth::user())->findOrFail($id);

        if (! $conversation->isGroup()) {
            return response()->json(['data' => []]);
        }

        $participants = $conversation->participants()
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => ContactResource::collection($participants),
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
        $conversation = Conversation::with('contact')->visibleTo(Auth::user())->findOrFail($id);

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
        // The composing agent — mirrors {{agent_name}} in accept/closing messages.
        $variables['agent_name'] = Auth::user()->name;

        // Drop null-valued fields so the composer only offers resolvable tokens.
        $variables = collect($variables)->reject(fn ($value) => $value === null)->all();

        return response()->json(['data' => $variables]);
    }

    public function messages(int $id)
    {
        $conversation = Conversation::visibleTo(Auth::user())->findOrFail($id);

        $messages = $conversation->messages()
            ->with(['contact', 'sentByUser', 'sentByFlow', 'sentByAiHubAgent'])
            ->orderBy('created_at', 'DESC')->orderBy('id', 'DESC')->get();

        return MessageResource::collection($messages)->response();
    }

    /**
     * Raw HTML body of an inbound e-mail. Stored on disk by
     * EmailInboxSynchronizer (too large for broadcasts/IndexedDB) and pulled
     * on demand when the reading pane opens. The SPA sanitizes it before
     * rendering — this returns the message exactly as received.
     */
    public function emailHtml(int $id, int $message_id)
    {
        $conversation = Conversation::visibleTo(Auth::user())->findOrFail($id);

        if(!$conversation->isAccessibleBy(Auth::user())){
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $message = $conversation->messages()->findOrFail($message_id);
        $path = $message->meta['email']['html_path'] ?? null;

        if (!$path || !Storage::disk('local')->exists($path)) {
            return response()->json(['message' => 'This message has no HTML body'], 404);
        }

        return response()->json([
            'html' => Storage::disk('local')->get($path),
        ]);
    }

    public function sendInteractive(Request $request, int $id)
    {
        $conversation = Conversation::visibleTo(Auth::user())->findOrFail($id);

        if(!$conversation->isAccessibleBy(Auth::user())){
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
        $conversation = Conversation::visibleTo(Auth::user())->findOrFail($id);

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
        $conversation = Conversation::visibleTo(Auth::user())->findOrFail($id);

        if(!$conversation->isAccessibleBy(Auth::user())){
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
        $conversation = Conversation::visibleTo(Auth::user())->findOrFail($id);

        if(!$conversation->isAccessibleBy(Auth::user())){
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
        } catch (ConnectionException $th) {
            $status = $th->getHttpStatusCode();

            if (in_array($status, [401, 419], true)) {
                $status = 502;
            }

            return response()->json([
                'message' => $th->getMessage(),
            ], $status);
        } catch (\Throwable $th) {
            return response()->json([
                'message' => 'Failed to send message',
            ], 500);
        }
    }

    public function sendImage(Request $request, int $id)
    {
        $conversation = Conversation::visibleTo(Auth::user())->findOrFail($id);

        if(!$conversation->isAccessibleBy(Auth::user())){
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
        $conversation = Conversation::visibleTo(Auth::user())->findOrFail($id);

        if(!$conversation->isAccessibleBy(Auth::user())){
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
        $conversation = Conversation::visibleTo(Auth::user())->findOrFail($id);

        if(!$conversation->isAccessibleBy(Auth::user())){
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
        $conversation = Conversation::visibleTo(Auth::user())->findOrFail($id);

        if(!$conversation->isAccessibleBy(Auth::user())){
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
        $conversation = Conversation::visibleTo(Auth::user())->findOrFail($id);

        // An agent can pick up a conversation from the unassigned Pending queue
        // or take it over from the AI while it is being handled.
        if(!in_array($conversation->status, [Status::Pending, Status::AiHandling], true)){
            return response()->json([
                'message' => 'Conversation is not pending',
            ], 400);
        }

        $this->applyAccept($conversation);

        return response()->json([
            'message' => 'Conversation accepted',
        ]);
    }

    public function resolve(int $id)
    {
        $conversation = Conversation::visibleTo(Auth::user())->findOrFail($id);

        if(!$conversation->isAccessibleBy(Auth::user())){
            return response()->json([
                'message' => 'Unauthorized',
            ], 403);
        }

        if($conversation->status !== Status::Active){
            return response()->json([
                'message' => 'Conversation is not active',
            ], 400);
        }

        $this->applyResolve($conversation);

        return response()->json([
            'message' => 'Conversation resolved',
        ]);
    }

    /**
     * Silence a conversation: it keeps arriving and keeps its unread badge, but
     * raises no toast and plays no sound in anyone's inbox.
     *
     * Deliberately NOT gated on isAccessibleBy: the thread most worth muting is
     * a busy unassigned group, which no agent "owns" yet. Muting destroys
     * nothing and any tenant member can undo it.
     */
    public function mute(int $id)
    {
        return $this->setMuted($id, true);
    }

    public function unmute(int $id)
    {
        return $this->setMuted($id, false);
    }

    private function setMuted(int $id, bool $muted)
    {
        $conversation = Conversation::visibleTo(Auth::user())->findOrFail($id);

        $conversation->update(['muted_at' => $muted ? now() : null]);

        // Every tab and every agent follows the same flag, so the bell in the
        // header flips everywhere at once.
        broadcast(new ConversationUpdated($conversation->fresh()->load('contact')));

        return response()->json([
            'message' => $muted ? 'Conversation muted' : 'Conversation unmuted',
            'data' => new ConversationResource($conversation->fresh()),
        ]);
    }

    /**
     * Agents the current conversation can be transferred to: users of the
     * tenant who can reach this conversation's connection, minus the current
     * assignee. Only someone who can act on the conversation (owner / assignee
     * / email-inbox member) may list them.
     *
     * Filtered by connection access because the alternative is a transfer that
     * silently strands the thread: assigned to someone whose inbox will never
     * show it.
     */
    public function transferTargets(int $id)
    {
        $conversation = Conversation::visibleTo(Auth::user())->findOrFail($id);

        if(!$conversation->isAccessibleBy(Auth::user())){
            return response()->json([
                'message' => 'Unauthorized',
            ], 403);
        }

        $agents = Auth::user()->tenant->users()
            ->where('users.id', '!=', $conversation->user_id)
            ->where(function ($q) use ($conversation) {
                // Owners hold no connection_user rows — they qualify by role.
                $q->whereHas('roles', fn ($r) => $r->where('name', 'owner'))
                    ->orWhereHas('connections', fn ($c) => $c->where('connections.id', $conversation->connection_id));
            })
            ->orderBy('name')
            ->get(['users.id', 'users.name', 'users.email']);

        return response()->json([
            'data' => $agents,
        ]);
    }

    /**
     * Transfer an Active conversation to another agent of the tenant. Allowed
     * for whoever can act on the conversation (owner / current assignee /
     * email-inbox member). Broadcasts ConversationUpdated for state sync plus
     * ConversationTransferred so the receiving agent gets a notification.
     */
    public function transfer(Request $request, int $id)
    {
        $validated = $request->validate([
            'agent_id' => ['required', 'integer'],
        ]);

        $conversation = Conversation::visibleTo(Auth::user())->findOrFail($id);

        if(!$conversation->isAccessibleBy(Auth::user())){
            return response()->json([
                'message' => 'Unauthorized',
            ], 403);
        }

        if($conversation->status !== Status::Active){
            return response()->json([
                'message' => 'Conversation is not active',
            ], 400);
        }

        $target = Auth::user()->tenant->users()->find($validated['agent_id']);

        if(!$target){
            return response()->json([
                'message' => 'Agent not found',
            ], 404);
        }

        if((int) $conversation->user_id === (int) $target->id){
            return response()->json([
                'message' => 'Conversation is already assigned to this agent',
            ], 400);
        }

        // Assigning to someone who cannot see the connection would park the
        // thread in an inbox that never renders it.
        if(!$target->canAccessConnection($conversation->connection)){
            return response()->json([
                'message' => 'That agent does not have access to this connection',
                'code' => 'agent_missing_connection_access',
            ], 422);
        }

        $conversation->user_id = $target->id;
        $conversation->needs_human = false;
        $conversation->save();

        $conversation->load('agent');

        broadcast(new ConversationUpdated($conversation));
        broadcast(new ConversationTransferred($conversation, Auth::user(), $target));

        return response()->json([
            'message' => 'Conversation transferred',
            'data' => new ConversationResource($conversation),
        ]);
    }

    /**
     * Accept semantics (assumes the conversation is Pending or AiHandling):
     * assign to the current agent, mark Active, clear the human flag, stop any
     * running flow when taking over from the AI, then broadcast + send the
     * connection's accept message. Shared by accept() and bulkUpdateStatus().
     */
    protected function applyAccept(Conversation $conversation): void
    {
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
    }

    /**
     * Resolve semantics (assumes the conversation is Active and the caller is
     * authorised): send the connection's closing message, mark Resolved, then
     * broadcast. Shared by resolve() and bulkUpdateStatus().
     */
    protected function applyResolve(Conversation $conversation): void
    {
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
    }

    /**
     * Bulk status update: apply accept semantics (→ Active) or resolve semantics
     * (→ Resolved) to many conversations at once, scoped to the tenant. Each
     * conversation is skipped (not failed) when it isn't eligible for the
     * requested transition, so a mixed selection updates only what it can.
     */
    public function bulkUpdateStatus(Request $request)
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
            'status' => ['required', 'string', Rule::in([Status::Active->value, Status::Resolved->value])],
        ]);

        $target = Status::from($validated['status']);

        $conversations = Conversation::with('connection')
            ->visibleTo(Auth::user())
            ->whereIn('id', $validated['ids'])
            ->get();

        $updated = 0;
        $skipped = 0;

        foreach ($conversations as $conversation) {
            try {
                if ($target === Status::Active) {
                    // Accept: only from the Pending queue or from the AI.
                    if (!in_array($conversation->status, [Status::Pending, Status::AiHandling], true)) {
                        $skipped++;
                        continue;
                    }

                    $this->applyAccept($conversation);
                    $updated++;
                } else { // Status::Resolved
                    // Resolve: only Active, and only accessible conversations
                    // (own, or any e-mail shared-inbox conversation) unless owner.
                    if ($conversation->status !== Status::Active
                        || !$conversation->isAccessibleBy(Auth::user())) {
                        $skipped++;
                        continue;
                    }

                    $this->applyResolve($conversation);
                    $updated++;
                }
            } catch (\Throwable $th) {
                $skipped++;
                Log::error('ConversationController: Bulk status update failed for conversation', [
                    'conversation_id' => $conversation->id,
                    'error' => $th->getMessage(),
                ]);
            }
        }

        // Ids that were not found or belong to another tenant are also skipped.
        $skipped += count($validated['ids']) - $conversations->count();

        return response()->json([
            'message' => 'Conversations updated',
            'updated' => $updated,
            'skipped' => $skipped,
            'status' => $target->value,
        ]);
    }

    public function syncTags(int $id, Request $request)
    {
        $conversation = Conversation::visibleTo(Auth::user())->findOrFail($id);

        if(!$conversation->isAccessibleBy(Auth::user())){
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
        $conversation = Conversation::visibleTo(Auth::user())->findOrFail($id);

        if(!$conversation->isAccessibleBy(Auth::user())){
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
        $conversation = Conversation::visibleTo(Auth::user())->findOrFail($id);

        if(!$conversation->isAccessibleBy(Auth::user())){
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

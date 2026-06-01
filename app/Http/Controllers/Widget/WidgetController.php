<?php

namespace App\Http\Controllers\Widget;

use App\Enums\Connection\Status;
use App\Enums\Conversation\Status as ConversationStatus;
use App\Enums\Message\MessageType;
use App\Enums\Message\SenderType;
use App\Events\ConversationUpdated;
use App\Events\MessageReceived;
use App\Http\Controllers\Controller;
use App\Http\Resources\MessageResource;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\LiveChatSession;
use App\Services\Flow\FlowExecutor;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class WidgetController extends Controller
{
    /**
     * Public bootstrap endpoint called by the embedded SDK on page load.
     * Returns the widget configuration (template_type, theme, etc.) but
     * NOT the credentials.
     */
    public function config(Request $request, string $appId)
    {
        $connection = $this->resolveConnectionByAppId($appId);

        return response()->json([
            'app_id' => $appId,
            'template_type' => $connection->credentials['template_type'] ?? 'global',
            'connection' => [
                'id' => $connection->id,
                'name' => $connection->name,
                'color' => $connection->color,
                'accept_message' => $connection->accept_message,
            ],
            'realtime' => $this->resolveRealtimeConfig(),
        ]);
    }

    /**
     * Initialize a new chat session for an end-user visitor.
     * Creates a Contact, Conversation, and LiveChatSession bound to a
     * session_token the SDK will use for subsequent calls and Echo subscription.
     */
    public function initSession(Request $request, string $appId)
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'visitor_id' => ['nullable', 'string', 'max:255'],
            'page_url' => ['nullable', 'string', 'max:2048'],
            'meta' => ['nullable', 'array'],
        ]);

        $connection = $this->resolveConnectionByAppId($appId);

        $name = $data['name'] ?? 'Visitor';
        $externalId = $data['visitor_id'] ?? (string) Str::uuid();

        $session = DB::transaction(function () use ($connection, $data, $name, $externalId, $request) {
            $contact = Contact::createFromExternalData($connection, $externalId, $name, $data['email'] ?? null);

            $conversation = Conversation::create([
                'contact_id' => $contact->id,
                'connection_id' => $connection->id,
                'external_id' => $externalId,
                'status' => ConversationStatus::Pending,
            ]);

            return LiveChatSession::create([
                'connection_id' => $connection->id,
                'contact_id' => $contact->id,
                'conversation_id' => $conversation->id,
                'visitor_name' => $data['name'] ?? null,
                'visitor_email' => $data['email'] ?? null,
                'visitor_ip' => $request->ip(),
                'page_url' => $data['page_url'] ?? null,
                'user_agent' => substr((string) $request->userAgent(), 0, 1024),
                'meta' => $data['meta'] ?? null,
                'last_seen_at' => Carbon::now(),
            ]);
        });

        broadcast(new ConversationUpdated($session->conversation->load('contact')));

        return response()->json([
            'session_token' => $session->session_token,
            'conversation_id' => $session->conversation_id,
            'contact_id' => $session->contact_id,
        ]);
    }

    /**
     * Pre-upload an attachment from the visitor. Returns a (temporary) URL
     * the SDK can use both for local preview and to attach to a subsequent
     * sendMessage() call.
     *
     * Files are stored at widget-uploads/{session_token}/{uuid}.{ext}. The
     * session_token in the path is what lets sendMessage() verify ownership
     * later — visitors can only attach files they themselves uploaded.
     */
    public function upload(Request $request, string $sessionToken)
    {
        $request->validate([
            'file' => [
                'required',
                'file',
                'max:51200', // 50 MB (covers image/audio/document/short video)
                'mimes:jpeg,jpg,png,gif,webp,ogg,mp3,wav,m4a,opus,mp4,mov,webm,pdf,doc,docx,xls,xlsx,ppt,pptx,txt,zip,rar,csv',
            ],
        ]);

        $session = $this->resolveSession($sessionToken);

        $file = $request->file('file');
        $ext = $file->getClientOriginalExtension();
        $messageType = $this->inferMessageTypeFromMime($file->getClientMimeType(), $ext);

        $path = sprintf(
            'widget-uploads/%s/%s.%s',
            $session->session_token,
            (string) Str::uuid(),
            $ext,
        );

        Storage::disk('local')->put($path, file_get_contents($file->getRealPath()));

        $expiresAt = Carbon::now()->addHours(6);

        return response()->json([
            'url' => Storage::disk('local')->temporaryUrl($path, $expiresAt),
            'message_type' => $messageType->value,
            'filename' => $file->getClientOriginalName(),
            'mime_type' => $file->getClientMimeType(),
            'size' => $file->getSize(),
            'expires_at' => $expiresAt->toIso8601String(),
        ]);
    }

    /**
     * Visitor sends a message from the widget.
     * Stored as an Incoming message and broadcast to the agent dashboard.
     *
     * Body shape:
     *   { "message": "..." }                              → text
     *   { "attachment_url": "..." }                       → media (no caption)
     *   { "message": "...", "attachment_url": "..." }     → media with caption
     */
    public function sendMessage(Request $request, string $sessionToken)
    {
        $data = $request->validate([
            'message' => ['nullable', 'string', 'required_without:attachment_url'],
            'attachment_url' => ['nullable', 'string', 'required_without:message'],
        ]);

        $session = $this->resolveSession($sessionToken);

        $attachmentPath = null;
        $messageType = MessageType::Text;
        $meta = null;

        if (!empty($data['attachment_url'])) {
            $attachmentPath = $this->resolveAttachmentPath($data['attachment_url'], $session->session_token);

            if (!$attachmentPath) {
                throw ValidationException::withMessages([
                    'attachment_url' => 'Invalid or expired attachment URL.',
                ]);
            }

            $messageType = $this->inferMessageTypeFromPath($attachmentPath);
            $meta = [
                'filename' => basename($attachmentPath),
                'mime_type' => Storage::disk('local')->mimeType($attachmentPath) ?: null,
                'size' => Storage::disk('local')->size($attachmentPath),
            ];
        }

        $now = Carbon::now();

        $message = $session->conversation->messages()->create([
            'external_id' => (string) Str::uuid(),
            'sender_type' => SenderType::Incoming,
            'message_type' => $messageType,
            'body' => $data['message'] ?? null,
            'attachment' => $attachmentPath,
            'sent_at' => $now,
            'delivery_at' => $now,
            'meta' => $meta,
        ]);

        $session->update(['last_seen_at' => $now]);

        broadcast(new MessageReceived($message));
        broadcast(new ConversationUpdated($session->conversation->load('contact')));

        $this->triggerFlow($session->conversation, $message);

        return response()->json([
            'message' => (new MessageResource($message))->resolve(),
        ]);
    }

    /**
     * Run the connection's flow (chatbot) against this incoming widget message.
     *
     * Mirrors the Telegram/WhatsApp webhook handlers:
     *   - First incoming message + connection has flow_id → startFlow()
     *   - Subsequent incoming messages → resumeFlow() with the user input
     *
     * "First message" is detected by absence of a FlowState row — the same
     * signal FlowExecutor uses internally to know whether a conversation has
     * ever been touched by a flow. Counting messages would mis-handle the
     * case where a flow was started, the visitor sent N messages, and then
     * the flow was stopped (e.g. admin handover via ConversationObserver).
     *
     * Errors are logged but never propagated — a broken flow must not cause
     * the visitor's send-message call to fail.
     */
    private function triggerFlow(Conversation $conversation, $message): void
    {
        if (!$conversation->connection->flow_id) {
            return;
        }

        $userInput = $message->body ?? '';
        $isNewFlow = !$conversation->flowState()->exists();

        $flowExecutor = new FlowExecutor();

        try {
            if ($isNewFlow) {
                $flowExecutor->startFlow($conversation);
            } else {
                $flowExecutor->resumeFlow($conversation, $userInput);
            }
        } catch (\Throwable $th) {
            Log::error('WidgetController: Failed to execute flow', [
                'conversation_id' => $conversation->id,
                'connection_id' => $conversation->connection_id,
                'flow_id' => $conversation->connection->flow_id,
                'is_new_flow' => $isNewFlow,
                'error' => $th->getMessage(),
            ]);
        }
    }

    /**
     * Extract the storage path from an attachment URL and verify ownership.
     * Returns null if the URL doesn't belong to this session OR the file is
     * gone. Looks for "widget-uploads/{session_token}/..." anywhere in the
     * URL path component — tolerant to /storage/ prefix or any host.
     */
    private function resolveAttachmentPath(string $url, string $sessionToken): ?string
    {
        $urlPath = parse_url($url, PHP_URL_PATH);
        if (!$urlPath) return null;

        $marker = "widget-uploads/{$sessionToken}/";
        $pos = strpos($urlPath, $marker);
        if ($pos === false) return null;

        $storagePath = ltrim(substr($urlPath, $pos), '/');

        if (!Storage::disk('local')->exists($storagePath)) return null;

        return $storagePath;
    }

    private function inferMessageTypeFromMime(string $mime, string $ext): MessageType
    {
        if (str_starts_with($mime, 'image/')) return MessageType::Image;
        if (str_starts_with($mime, 'audio/')) return MessageType::Audio;
        if (str_starts_with($mime, 'video/')) return MessageType::Video;

        return MessageType::Document;
    }

    private function inferMessageTypeFromPath(string $path): MessageType
    {
        $mime = Storage::disk('local')->mimeType($path) ?: '';
        return $this->inferMessageTypeFromMime($mime, pathinfo($path, PATHINFO_EXTENSION));
    }

    /**
     * Return the current session + conversation status. Lightweight: used
     * by the SDK on reconnect / polling fallback to know whether the
     * conversation is still open, who is handling it, and how many agent
     * replies are unread.
     */
    public function status(Request $request, string $sessionToken)
    {
        $session = $this->resolveSession($sessionToken);
        $conversation = $session->conversation()->with('agent:id,name')->first();

        $lastSeenAt = $session->last_seen_at;
        $unreadCount = $conversation->messages()
            ->where('sender_type', SenderType::Outgoing)
            ->when($lastSeenAt, fn($q) => $q->where('created_at', '>', $lastSeenAt))
            ->count();

        $lastMessage = $conversation->messages()
            ->latest('created_at')
            ->latest('id')
            ->first();

        return response()->json([
            'session' => [
                'token' => $session->session_token,
                'last_seen_at' => $lastSeenAt?->toIso8601String(),
            ],
            'conversation' => [
                'id' => $conversation->id,
                'status' => $conversation->status,
                'last_message_at' => $conversation->last_message_at?->toIso8601String(),
                'agent' => $conversation->agent ? [
                    'id' => $conversation->agent->id,
                    'name' => $conversation->agent->name,
                ] : null,
                'last_message' => $lastMessage ? [
                    'id' => $lastMessage->id,
                    'sender_type' => $lastMessage->sender_type,
                    'message_type' => $lastMessage->message_type,
                    'body' => $lastMessage->body,
                    'sent_at' => $lastMessage->sent_at,
                ] : null,
            ],
            'unread_count' => $unreadCount,
        ]);
    }

    /**
     * Mark the conversation as seen by the visitor up to "now". Updates
     * last_seen_at so subsequent /status calls return a correct unread_count.
     */
    public function markSeen(Request $request, string $sessionToken)
    {
        $session = $this->resolveSession($sessionToken);

        $session->update(['last_seen_at' => Carbon::now()]);

        return response()->json([
            'last_seen_at' => $session->last_seen_at->toIso8601String(),
        ]);
    }

    /**
     * Visitor fetches conversation history (for reconnect / page refresh).
     */
    public function history(Request $request, string $sessionToken)
    {
        $session = $this->resolveSession($sessionToken);

        $messages = $session->conversation->messages()
            ->orderBy('sent_at')
            ->orderBy('id')
            ->limit(200)
            ->get();

        return response()->json([
            'messages' => MessageResource::collection($messages)->resolve(),
        ]);
    }

    private function resolveConnectionByAppId(string $appId): Connection
    {
        $connection = Connection::where('channel', \App\Enums\Connection\Channel::LiveChatWidget)
            ->where('credentials->app_id', $appId)
            ->first();

        if (!$connection) {
            throw ValidationException::withMessages([
                'app_id' => 'Unknown Live Chat Widget app_id.',
            ]);
        }

        if ($connection->status !== Status::Active) {
            abort(403, 'Widget is not active.');
        }

        return $connection;
    }

    /**
     * Public-facing Reverb connection config for the embedded SDK.
     *
     * REVERB_HOST is usually an INTERNAL hostname (localhost, 127.0.0.1, or
     * a docker service name) used by Laravel to publish events. External
     * widgets cannot reach that. Resolution order, first non-empty wins:
     *   1. REVERB_PUBLIC_HOST / REVERB_PUBLIC_PORT / REVERB_PUBLIC_SCHEME
     *      → explicit override, recommended for production
     *   2. Host parsed from APP_URL
     *      → works when Reverb sits behind the same reverse proxy as the app
     *   3. REVERB_HOST + defaults
     *      → last-resort fallback (will be 'localhost' in many setups)
     */
    private function resolveRealtimeConfig(): array
    {
        $publicHost = env('REVERB_PUBLIC_HOST');
        $publicPort = env('REVERB_PUBLIC_PORT');
        $publicScheme = env('REVERB_PUBLIC_SCHEME');

        if (!$publicHost) {
            $appUrl = (string) config('app.url');
            $parsed = $appUrl ? parse_url($appUrl) : false;

            if (is_array($parsed) && !empty($parsed['host'])) {
                $publicHost = $parsed['host'];
                $publicScheme = $publicScheme ?: ($parsed['scheme'] ?? null);
            }
        }

        $host = $publicHost
            ?: config('broadcasting.connections.reverb.options.host')
            ?: 'localhost';

        $scheme = $publicScheme
            ?: config('broadcasting.connections.reverb.options.scheme')
            ?: 'https';

        $port = (int) ($publicPort
            ?: config('broadcasting.connections.reverb.options.port')
            ?: ($scheme === 'https' ? 443 : 80));

        return [
            'driver' => 'reverb',
            'key' => config('broadcasting.connections.reverb.key'),
            'host' => $host,
            'port' => $port,
            'scheme' => $scheme,
        ];
    }

    private function resolveSession(string $sessionToken): LiveChatSession
    {
        $session = LiveChatSession::where('session_token', $sessionToken)->first();

        if (!$session || !$session->conversation_id) {
            abort(404, 'Session not found.');
        }

        return $session;
    }
}

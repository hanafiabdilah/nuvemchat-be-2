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
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
     * Visitor sends a message from the widget.
     * Stored as an Incoming message and broadcast to the agent dashboard.
     */
    public function sendMessage(Request $request, string $sessionToken)
    {
        $data = $request->validate([
            'message' => ['required', 'string'],
        ]);

        $session = $this->resolveSession($sessionToken);

        $now = Carbon::now();

        $message = $session->conversation->messages()->create([
            'external_id' => (string) Str::uuid(),
            'sender_type' => SenderType::Incoming,
            'message_type' => MessageType::Text,
            'body' => $data['message'],
            'sent_at' => $now,
            'delivery_at' => $now,
        ]);

        $session->update(['last_seen_at' => $now]);

        broadcast(new MessageReceived($message));
        broadcast(new ConversationUpdated($session->conversation->load('contact')));

        return response()->json([
            'message' => (new MessageResource($message))->resolve(),
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

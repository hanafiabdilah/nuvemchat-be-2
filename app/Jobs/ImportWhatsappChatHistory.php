<?php

namespace App\Jobs;

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status as ConnectionStatus;
use App\Enums\Conversation\Status as ConversationStatus;
use App\Enums\Message\MessageType;
use App\Enums\Message\SenderType;
use App\Events\ConversationUpdated;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Services\Connection\Proxy\ApiwayConfig;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * "Import chat history" for W-API / API Way: once the instance pairs, pull the
 * chat LIST from the provider and turn each recent chat into a Pending
 * conversation (contact + conversation + one preview message) so agents can
 * pick existing customers up from the queue.
 *
 * What the providers actually allow (verified against both APIs):
 *   - W-API exposes GET /v1/chats/fetch-chats, but NO endpoint to fetch a
 *     chat's message backlog — so only the chat list (+ last-message preview
 *     when present) can be imported, never the full history.
 *   - API Way currently answers fetch-chats with 501 not_supported_yet
 *     ("em breve"); the job detects that and marks the import "unsupported".
 *     Since API Way mirrors W-API's /v1 surface, this code starts working
 *     there as soon as the endpoint ships.
 *
 * Limits (deliberate): newest MAX_CHATS chats only, none older than
 * MAX_AGE_DAYS, individual chats only (groups/broadcast/newsletter skipped),
 * one preview message per conversation (marked read — no unread storm, no
 * notification sounds), never duplicates an existing conversation, never
 * starts a flow, and it runs once per connection.
 */
class ImportWhatsappChatHistory implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;
    public int $tries = 1;

    public const MAX_CHATS = 50;
    public const MAX_AGE_DAYS = 30;

    public function __construct(public int $connectionId)
    {
    }

    /**
     * Queue the import when the connection just became Active with the opt-in
     * flag set and no import has run yet. Safe to call from every place that
     * flips the status — the state machine in credentials makes it one-shot.
     */
    public static function dispatchIfPending(Connection $connection): void
    {
        if (!in_array($connection->channel, [Channel::WhatsappWApi, Channel::WhatsappApiway], true)) {
            return;
        }
        if ($connection->status !== ConnectionStatus::Active) {
            return;
        }

        $credentials = $connection->credentials ?? [];
        if (empty($credentials['import_history'])) {
            return;
        }

        $state = $credentials['history_import']['status'] ?? null;
        if (in_array($state, ['queued', 'running', 'done', 'unsupported'], true)) {
            return;
        }

        $credentials['history_import'] = [
            'status' => 'queued',
            'queued_at' => now()->toIso8601String(),
        ];
        $connection->update(['credentials' => $credentials]);

        self::dispatch($connection->id);

        Log::info('ImportWhatsappChatHistory: queued', ['connection_id' => $connection->id]);
    }

    public function handle(): void
    {
        // Claim the run under a row lock so a double dispatch (webhook +
        // status polling racing) results in a single import.
        $connection = DB::transaction(function () {
            $connection = Connection::lockForUpdate()->find($this->connectionId);

            if (!$connection || ($connection->credentials['history_import']['status'] ?? null) !== 'queued') {
                return null;
            }

            $this->setState($connection, ['status' => 'running', 'started_at' => now()->toIso8601String()]);

            return $connection;
        });

        if (!$connection) {
            return;
        }

        try {
            $chats = $this->fetchChats($connection);

            if ($chats === null) {
                // Provider does not support fetch-chats (API Way: 501 "em breve").
                $this->setState($connection, [
                    'status' => 'unsupported',
                    'finished_at' => now()->toIso8601String(),
                ]);
                return;
            }

            [$imported, $skipped] = $this->importChats($connection, $chats);

            $this->setState($connection, [
                'status' => 'done',
                'imported' => $imported,
                'skipped' => $skipped,
                'finished_at' => now()->toIso8601String(),
            ]);

            Log::info('ImportWhatsappChatHistory: finished', [
                'connection_id' => $connection->id,
                'imported' => $imported,
                'skipped' => $skipped,
            ]);
        } catch (\Throwable $th) {
            Log::error('ImportWhatsappChatHistory: failed', [
                'connection_id' => $connection->id,
                'error' => $th->getMessage(),
            ]);

            // "failed" is retryable: the next connect/status check re-queues it.
            $this->setState($connection, [
                'status' => 'failed',
                'error' => mb_substr($th->getMessage(), 0, 300),
                'finished_at' => now()->toIso8601String(),
            ]);
        }
    }

    /**
     * Fetch and normalize the provider's chat list. Returns null when the
     * provider does not support the endpoint (yet).
     */
    protected function fetchChats(Connection $connection): ?array
    {
        $base = $connection->channel === Channel::WhatsappApiway
            ? ApiwayConfig::baseUrl()
            : 'https://api.w-api.app';

        // Legacy rows exist whose credentials predate the instance_id/token
        // shape — there is nothing to call for those.
        if (empty($connection->credentials['instance_id']) || empty($connection->credentials['token'])) {
            throw new \RuntimeException('Connection has no instance_id/token credentials');
        }

        $response = Http::withToken($connection->credentials['token'])
            ->connectTimeout(15)
            ->timeout(60)
            ->get($base . '/v1/chats/fetch-chats', [
                'instanceId' => $connection->credentials['instance_id'],
                'perPage' => self::MAX_CHATS,
                'page' => 1,
            ]);

        $json = $response->json();

        if ($response->status() === 501 || $response->status() === 404
            || (($json['error'] ?? null) === 'not_supported_yet')) {
            Log::info('ImportWhatsappChatHistory: fetch-chats not supported by provider', [
                'connection_id' => $connection->id,
                'status' => $response->status(),
            ]);
            return null;
        }

        if ($response->failed()) {
            throw new \RuntimeException(
                'fetch-chats failed (HTTP ' . $response->status() . '): ' . ($json['message'] ?? $json['error'] ?? '')
            );
        }

        // The list may come bare, or wrapped as {chats}, {data}, {data:{chats}}.
        $list = $json['chats']
            ?? $json['data']['chats']
            ?? $json['data']
            ?? (is_array($json) && array_is_list($json) ? $json : null);

        if (!is_array($list) || !array_is_list($list)) {
            Log::warning('ImportWhatsappChatHistory: unexpected fetch-chats shape', [
                'connection_id' => $connection->id,
                'top_keys' => is_array($json) ? array_keys($json) : gettype($json),
            ]);
            throw new \RuntimeException('Unexpected fetch-chats response shape');
        }

        // Log one sample entry so the normalizer can be tuned against reality.
        if (!empty($list) && is_array($list[0])) {
            Log::info('ImportWhatsappChatHistory: sample chat entry', [
                'connection_id' => $connection->id,
                'sample' => array_slice($list[0], 0, 20, true),
            ]);
        }

        return $list;
    }

    /**
     * @return array{0: int, 1: int} [imported, skipped]
     */
    protected function importChats(Connection $connection, array $chats): array
    {
        $cutoff = now()->subDays(self::MAX_AGE_DAYS)->timestamp;

        $normalized = collect($chats)
            ->map(fn ($chat) => is_array($chat) ? $this->normalizeChat($chat) : null)
            ->filter()
            // Individual, recent chats only.
            ->filter(fn ($chat) => $chat['timestamp'] === null || $chat['timestamp'] >= $cutoff)
            // The same phone can appear more than once; fold those into one
            // entry keeping the newest timestamp and the richest name/preview.
            ->groupBy('phone')
            ->map(fn ($group) => [
                'phone' => $group->first()['phone'],
                'name' => $group->pluck('name')->filter()->first(),
                'preview' => $group->pluck('preview')->filter()->first(),
                'timestamp' => $group->pluck('timestamp')->filter()->max(),
            ])
            ->sortByDesc(fn ($chat) => $chat['timestamp'] ?? 0)
            ->take(self::MAX_CHATS)
            ->values();

        $imported = 0;
        $skipped = 0;

        foreach ($normalized as $chat) {
            try {
                $created = DB::transaction(fn () => $this->importChat($connection, $chat));
            } catch (\Throwable $th) {
                Log::warning('ImportWhatsappChatHistory: chat skipped after error', [
                    'connection_id' => $connection->id,
                    'phone' => $chat['phone'],
                    'error' => $th->getMessage(),
                ]);
                $created = null;
            }

            if ($created) {
                $imported++;
                // Quiet realtime update: lists refresh, but no message
                // notification (sound/toast) is fired for imported history.
                broadcast(new ConversationUpdated($created->load('contact')));
            } else {
                $skipped++;
            }
        }

        return [$imported, $skipped];
    }

    /**
     * Map one provider chat entry to a common shape, or null when it must be
     * skipped (groups, broadcast/status, newsletters, unparseable ids).
     */
    protected function normalizeChat(array $chat): ?array
    {
        $jid = $chat['id'] ?? $chat['jid'] ?? $chat['remoteJid'] ?? $chat['chatId'] ?? $chat['phone'] ?? null;
        if (is_array($jid)) {
            $jid = $jid['_serialized'] ?? (isset($jid['user'], $jid['server']) ? $jid['user'] . '@' . $jid['server'] : null);
        }
        if (!is_string($jid) || $jid === '') {
            return null;
        }

        // Individual chats only: "5511999999999" or "...@s.whatsapp.net".
        if (str_contains($jid, '@') && !str_ends_with($jid, '@s.whatsapp.net')) {
            return null;
        }
        $phone = strstr($jid, '@', true) ?: $jid;
        if (!preg_match('/^\d{6,20}$/', $phone)) {
            return null;
        }

        $name = null;
        foreach (['name', 'pushName', 'pushname', 'contactName', 'verifiedName'] as $key) {
            if (!empty($chat[$key]) && is_string($chat[$key])) {
                $name = $chat[$key];
                break;
            }
        }

        $timestamp = null;
        foreach (['lastMessageTime', 'messageTimestamp', 'conversationTimestamp', 'timestamp', 't'] as $key) {
            if (isset($chat[$key]) && is_numeric($chat[$key])) {
                $timestamp = (int) $chat[$key];
                break;
            }
        }
        if ($timestamp !== null && $timestamp > 100000000000) {
            $timestamp = intdiv($timestamp, 1000); // milliseconds → seconds
        }

        $preview = null;
        $last = $chat['lastMessage'] ?? null;
        if (is_string($last)) {
            $preview = $last;
        } elseif (is_array($last)) {
            foreach (['message', 'body', 'text', 'conversation'] as $key) {
                if (!empty($last[$key]) && is_string($last[$key])) {
                    $preview = $last[$key];
                    break;
                }
            }
        }

        return [
            'phone' => $phone,
            'name' => $name,
            'timestamp' => $timestamp,
            'preview' => $preview,
        ];
    }

    /**
     * Create contact + Pending conversation + one read preview message.
     * Returns the conversation, or null when the chat already exists.
     */
    protected function importChat(Connection $connection, array $chat): ?Conversation
    {
        $phone = $chat['phone'];

        $contact = Contact::where('tenant_id', $connection->tenant_id)
            ->where('external_id', $phone)
            ->first();

        if (!$contact) {
            $contact = Contact::create([
                'tenant_id' => $connection->tenant_id,
                'external_id' => $phone,
                'channel' => $connection->channel,
                'name' => $chat['name'] ?: $phone,
                'username' => $phone,
            ]);
        }

        // Any existing conversation for this contact+connection (open or
        // resolved) means the chat is already known — never duplicate it.
        $exists = Conversation::where('contact_id', $contact->id)
            ->where('connection_id', $connection->id)
            ->exists();

        if ($exists) {
            return null;
        }

        $lastMessageAt = $chat['timestamp'] ? Carbon::createFromTimestamp($chat['timestamp']) : now();

        $conversation = Conversation::create([
            'contact_id' => $contact->id,
            'connection_id' => $connection->id,
            'external_id' => $phone,
            'status' => ConversationStatus::Pending,
            'last_message_at' => $lastMessageAt,
        ]);

        // Conversations without a message are hidden from the inbox, so every
        // import carries one preview message — marked read (no unread badge).
        $conversation->messages()->create([
            'external_id' => 'history-import-' . $connection->id . '-' . $phone,
            'sender_type' => SenderType::Incoming,
            'message_type' => MessageType::Text,
            'body' => $chat['preview'] ?: '[Conversa importada do histórico do WhatsApp]',
            'sent_at' => $lastMessageAt,
            'delivery_at' => $lastMessageAt,
            'read_at' => now(),
            'meta' => ['history_import' => true],
        ]);

        return $conversation;
    }

    /**
     * Merge fields into credentials.history_import on a fresh copy of the row.
     */
    protected function setState(Connection $connection, array $fields): void
    {
        $connection->refresh();
        $credentials = $connection->credentials ?? [];
        $credentials['history_import'] = array_merge($credentials['history_import'] ?? [], $fields);
        $connection->update(['credentials' => $credentials]);
    }
}

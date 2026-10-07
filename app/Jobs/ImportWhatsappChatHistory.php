<?php

namespace App\Jobs;

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status as ConnectionStatus;
use App\Enums\Conversation\Status as ConversationStatus;
use App\Enums\Message\MessageType;
use App\Enums\Message\SenderType;
use App\Events\ConnectionUpdated;
use App\Events\ConversationUpdated;
use App\Exceptions\ChatListNotReadyException;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
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
 * "Import chat history" for API Way: once the instance pairs, pull the chat
 * list from the provider and turn each recent chat into a Pending
 * conversation carrying its latest messages, so agents can pick existing
 * customers up from the queue and read what was said before.
 *
 * What the provider offers:
 *   - GET /v1/chats/fetch-chats — the chat list, newest first:
 *     {chatId, jid, phone, name, lastMessageTime}. `chatId` is how the core
 *     stores the chat and is usually the privacy id (`…@lid`), which carries
 *     no number; `jid`/`phone` is the number, and the only thing a contact
 *     may be keyed by (the live webhook keys on it too, so an imported thread
 *     and the customer's next message land on the same conversation).
 *   - GET /v1/chats/fetch-messages — one chat's messages, newest first.
 *     History media arrives without a file: the row says ":image:" and has
 *     no link, so it is imported as a line of text naming what was there.
 *   - Older cores answer fetch-chats with 501 not_supported_yet, which the
 *     job still detects and marks "unsupported".
 *   - For the first moments after an instance pairs, fetch-chats answers
 *     with no list at all (success, but "data" is not an array) because the
 *     core has not indexed the chats yet. That is a wait, not a failure, so
 *     the job re-queues itself instead of burning its run.
 *
 * Limits (deliberate): newest MAX_CHATS chats only, none older than
 * MAX_AGE_DAYS, individual chats only (groups/broadcast/newsletter skipped),
 * MAX_MESSAGES_PER_CHAT messages each, everything marked read (no unread
 * storm, no notification sounds), never touches a chat that already has a
 * conversation here, never starts a flow. It runs once per pairing; rerun()
 * runs it again on request, which is safe because a chat that was imported
 * is skipped and a message id is never stored twice.
 */
class ImportWhatsappChatHistory implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;
    public int $tries = 1;

    public const MAX_CHATS = 50;
    public const LIST_SIZE = 500;

    /**
     * WhatsApp hands a newly linked device months of history, and most of the
     * customers in it last wrote weeks ago: at 30 days a number with 159 chats
     * imported 13 of them.
     */
    public const MAX_AGE_DAYS = 90;

    public const MAX_MESSAGES_PER_CHAT = 50;

    /**
     * The first run waits before reading the chat list: WhatsApp keeps sending
     * history batches for a few minutes after pairing, and a chat that was not
     * there yet is a chat that is never imported.
     */
    public const INITIAL_DELAY_SECONDS = 150;

    /**
     * One fetch-messages call per chat adds up. Past this the run stops
     * opening new chats and leaves the rest to a rerun, well inside $timeout.
     */
    public const TIME_BUDGET_SECONDS = 240;

    /** What the core writes in place of history media it has no file for. */
    private const MEDIA_LABELS = [
        'image' => '[Imagem]',
        'video' => '[Vídeo]',
        'audio' => '[Áudio]',
        'document' => '[Documento]',
        'sticker' => '[Figurinha]',
        'contact' => '[Contato]',
        'location' => '[Localização]',
    ];

    private const PLACEHOLDER_BODY = '[Conversa importada do histórico do WhatsApp]';

    /**
     * How long to wait for the core to index the chats, and how many times.
     * A fixed minute keeps the wait predictable; the whole point is to cover
     * the warm-up right after pairing, not to poll indefinitely.
     */
    public const READY_RETRY_SECONDS = 60;
    public const MAX_READY_ATTEMPTS = 5;

    public function __construct(public int $connectionId, public int $attempt = 1)
    {
    }

    /**
     * Queue the import when the connection just became Active with the opt-in
     * flag set and no import has run yet. Safe to call from every place that
     * flips the status — the state machine in credentials makes it one-shot.
     */
    public static function dispatchIfPending(Connection $connection): void
    {
        if ($connection->channel !== Channel::WhatsappApiway) {
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

        self::dispatch($connection->id)
            ->delay(now()->addSeconds(self::INITIAL_DELAY_SECONDS));

        self::announce($connection);

        Log::info('ImportWhatsappChatHistory: queued', ['connection_id' => $connection->id]);
    }

    /**
     * Run the import again on a connection that already had its turn. False
     * when there is nothing to run it on, or a run is already on its way.
     *
     * No delay here: someone asked for it, and the batches the first run was
     * waiting for have long since arrived.
     */
    public static function rerun(Connection $connection): bool
    {
        if ($connection->channel !== Channel::WhatsappApiway || $connection->status !== ConnectionStatus::Active) {
            return false;
        }

        $credentials = $connection->credentials ?? [];

        if (in_array($credentials['history_import']['status'] ?? null, ['queued', 'running'], true)) {
            return false;
        }

        $credentials['import_history'] = true;
        $credentials['history_import'] = [
            'status' => 'queued',
            'queued_at' => now()->toIso8601String(),
        ];
        $connection->update(['credentials' => $credentials]);

        self::dispatch($connection->id);

        Log::info('ImportWhatsappChatHistory: rerun queued', ['connection_id' => $connection->id]);

        return true;
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
        } catch (ChatListNotReadyException $th) {
            $this->waitForChatList($connection, $th->getMessage());
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
     * The core has not indexed the chats yet: hand the run back to the queue
     * and try again shortly. The state goes back to "queued" so the guard in
     * dispatchIfPending() keeps a concurrent status flip from dispatching a
     * second run while we wait.
     */
    protected function waitForChatList(Connection $connection, string $reason): void
    {
        if ($this->attempt >= self::MAX_READY_ATTEMPTS) {
            Log::warning('ImportWhatsappChatHistory: chat list never became readable', [
                'connection_id' => $connection->id,
                'attempts' => $this->attempt,
            ]);

            // Still "failed" rather than terminal: a later reconnect re-queues
            // it, by which time the core will have indexed the chats.
            $this->setState($connection, [
                'status' => 'failed',
                'error' => 'Chat list not ready after ' . self::MAX_READY_ATTEMPTS . ' attempts: ' . $reason,
                'finished_at' => now()->toIso8601String(),
            ]);

            return;
        }

        $next = $this->attempt + 1;

        $this->setState($connection, [
            'status' => 'queued',
            'attempt' => $next,
            'error' => null,
        ]);

        self::dispatch($connection->id, $next)
            ->delay(now()->addSeconds(self::READY_RETRY_SECONDS));

        Log::info('ImportWhatsappChatHistory: chat list not ready, retrying', [
            'connection_id' => $connection->id,
            'attempt' => $this->attempt,
            'next_attempt' => $next,
            'retry_in_seconds' => self::READY_RETRY_SECONDS,
        ]);
    }

    /**
     * Fetch and normalize the provider's chat list. Returns null when the
     * provider does not support the endpoint (yet), and throws
     * ChatListNotReadyException when it supports it but has nothing indexed.
     */
    protected function fetchChats(Connection $connection): ?array
    {
        $base = ApiwayConfig::baseUrl();

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
                // More than MAX_CHATS on purpose: groups and chats that are
                // already here are dropped after this, and the cap counts
                // what is imported, not what is listed.
                'perPage' => self::LIST_SIZE,
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
            // A success envelope carrying no list at all is the core telling us
            // it has not indexed this instance's chats yet — normal in the first
            // moments after pairing, and worth waiting for. An empty list is a
            // real answer ("no chats") and falls through to a clean run.
            if ($list === null && ($json['success'] ?? null) === true) {
                throw new ChatListNotReadyException('fetch-chats returned no chat list yet');
            }

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
                'lid' => $group->pluck('lid')->filter()->first(),
                'name' => $group->pluck('name')->filter()->first(),
                'preview' => $group->pluck('preview')->filter()->first(),
                'timestamp' => $group->pluck('timestamp')->filter()->max(),
            ])
            ->sortByDesc(fn ($chat) => $chat['timestamp'] ?? 0)
            ->values();

        $imported = 0;
        $skipped = 0;
        $startedAt = microtime(true);

        foreach ($normalized as $index => $chat) {
            // The cap counts chats brought in, so a rerun moves on to the
            // ones behind those it already imported.
            if ($imported >= self::MAX_CHATS) {
                break;
            }

            if (microtime(true) - $startedAt > self::TIME_BUDGET_SECONDS) {
                $left = $normalized->count() - $index;
                $skipped += $left;

                Log::warning('ImportWhatsappChatHistory: time budget spent, the rest waits for a rerun', [
                    'connection_id' => $connection->id,
                    'left' => $left,
                ]);

                break;
            }

            try {
                $created = $this->importChat($connection, $chat);
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

        // Individual chats only: "5511999999999" or "...@s.whatsapp.net". A
        // chat known only by its `@lid` has no number to key a contact by, or
        // to send to, and is left out rather than imported as a dead thread.
        if (str_contains($jid, '@') && !str_ends_with($jid, '@s.whatsapp.net')) {
            return null;
        }
        $phone = strstr($jid, '@', true) ?: $jid;
        if (!preg_match('/^\d{6,20}$/', $phone)) {
            return null;
        }

        // The privacy id the core files this chat under. Kept as the contact's
        // alias, in the handler's spelling, so a later `@lid`-only delivery
        // from this person still resolves to them.
        $lid = null;
        $chatId = $chat['chatId'] ?? null;
        if (is_string($chatId) && str_ends_with($chatId, '@lid')) {
            $user = explode(':', strstr($chatId, '@', true))[0];
            $lid = $user !== '' ? $user . '@lid' : null;
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
            $raw = $chat[$key] ?? null;

            if (is_numeric($raw)) {
                $timestamp = (int) $raw;
                break;
            }

            // API Way sends an ISO-8601 string ("2026-08-11T08:28:57.41129Z"),
            // not an epoch — without this the age cutoff never applies.
            if (is_string($raw) && $raw !== '') {
                try {
                    $timestamp = Carbon::parse($raw)->timestamp;
                    break;
                } catch (\Throwable) {
                    // Not a date after all; keep looking at the other keys.
                }
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
            'lid' => $lid,
            'name' => $name,
            'timestamp' => $timestamp,
            'preview' => $preview,
        ];
    }

    /**
     * Create contact + Pending conversation + its latest messages, all read.
     * Returns the conversation, or null when the chat is already known here.
     */
    protected function importChat(Connection $connection, array $chat): ?Conversation
    {
        $phone = $chat['phone'];

        $contact = Contact::where('tenant_id', $connection->tenant_id)
            ->where('external_id', $phone)
            ->first();

        // Any existing conversation for this contact+connection (open or
        // resolved) means the chat is already known — never duplicate it, and
        // never add to it: threads are read in insertion order, so old
        // messages written into a live one would surface below today's.
        if ($contact && Conversation::where('contact_id', $contact->id)->where('connection_id', $connection->id)->exists()) {
            return null;
        }

        // Read before the transaction opens: a slow core must not hold one.
        $fetched = $this->fetchMessages($connection, $phone . '@s.whatsapp.net');
        $messages = $this->withoutStoredMessages($connection, $fetched);

        // Every message of this chat is already on the connection, under a
        // conversation this lookup did not find. It is known; leave it be.
        if ($fetched !== [] && $messages === []) {
            return null;
        }

        return DB::transaction(function () use ($connection, $chat, $phone, $contact, $messages) {
            $contact ??= Contact::create([
                'tenant_id' => $connection->tenant_id,
                'external_id' => $phone,
                'channel' => $connection->channel,
                'name' => $chat['name'] ?: $phone,
                'username' => $phone,
            ]);

            if ($chat['lid'] && ! $contact->lid) {
                $contact->update(['lid' => $chat['lid']]);
            }

            $lastMessageAt = $messages !== []
                ? end($messages)['sent_at']
                : ($chat['timestamp'] ? Carbon::createFromTimestamp($chat['timestamp']) : now());

            $conversation = Conversation::create([
                'contact_id' => $contact->id,
                'connection_id' => $connection->id,
                'external_id' => $phone,
                'status' => ConversationStatus::Pending,
                'last_message_at' => $lastMessageAt,
            ]);

            // Conversations without a message are hidden from the inbox, so a
            // chat the core holds nothing for still carries one line.
            if ($messages === []) {
                $messages = [[
                    'external_id' => 'history-import-' . $connection->id . '-' . $phone,
                    'sender_type' => SenderType::Incoming,
                    'message_type' => MessageType::Text,
                    'body' => $chat['preview'] ?: self::PLACEHOLDER_BODY,
                    'sent_at' => $lastMessageAt,
                    'meta' => ['history_import' => true],
                ]];
            }

            // No model events: this is the past being written down, not a
            // customer speaking. An old "SAIR" must not opt anyone out today,
            // and an old ad click is not this week's lead.
            Message::withoutEvents(function () use ($conversation, $messages) {
                foreach ($messages as $message) {
                    $conversation->messages()->create([
                        ...$message,
                        'delivery_at' => $message['sent_at'],
                        // Read marks belong to what the customer sent; whether
                        // they read ours is not something the history says.
                        'read_at' => $message['sender_type'] === SenderType::Incoming ? now() : null,
                    ]);
                }
            });

            return $conversation;
        });
    }

    /**
     * One chat's latest messages, oldest first, in the shape importChat()
     * stores. Empty when the core holds none (or cannot list them at all);
     * throws when it could not be asked, so the chat is left for a rerun
     * instead of being imported empty and never looked at again.
     *
     * @return list<array{external_id: string, sender_type: SenderType, message_type: MessageType, body: string, sent_at: Carbon, meta: array}>
     */
    protected function fetchMessages(Connection $connection, string $jid): array
    {
        $response = Http::withToken($connection->credentials['token'])
            ->connectTimeout(10)
            ->timeout(20)
            ->get(ApiwayConfig::baseUrl() . '/v1/chats/fetch-messages', [
                'instanceId' => $connection->credentials['instance_id'],
                'chatId' => $jid,
                'limit' => self::MAX_MESSAGES_PER_CHAT,
            ]);

        $json = $response->json();

        // A core that predates the endpoint: the chat list is still worth having.
        if (in_array($response->status(), [404, 501], true) || (($json['error'] ?? null) === 'not_supported_yet')) {
            return [];
        }

        if ($response->failed()) {
            throw new \RuntimeException('fetch-messages failed (HTTP ' . $response->status() . ')');
        }

        $rows = $json['data']['messages'] ?? $json['data'] ?? $json['messages'] ?? [];
        if (!is_array($rows) || !array_is_list($rows)) {
            return [];
        }

        $messages = [];
        foreach ($rows as $row) {
            if (is_array($row) && ($message = $this->normalizeMessage($row)) !== null) {
                // Keyed by id: the core returns a chat's number and `@lid`
                // rows together, and the same message can sit under both.
                $messages[$message['external_id']] ??= $message;
            }
        }

        $messages = array_values($messages);
        usort($messages, fn ($a, $b) => $a['sent_at']->getTimestamp() <=> $b['sent_at']->getTimestamp());

        return $messages;
    }

    /**
     * Map one fetch-messages row, or null for a row with nothing to show
     * (reactions, protocol messages, anything without an id).
     */
    protected function normalizeMessage(array $row): ?array
    {
        $id = $row['message_id'] ?? null;
        if (!is_string($id) || $id === '') {
            return null;
        }

        $type = strtolower((string) ($row['message_type'] ?? ''));
        $text = trim((string) ($row['text_content'] ?? ''));

        // ":image:" and friends stand in for media the history came without.
        if (preg_match('/^:([a-z]+):$/', $text, $match) && isset(self::MEDIA_LABELS[$match[1]])) {
            $type = $match[1];
            $text = '';
        }

        if (isset(self::MEDIA_LABELS[$type])) {
            // There is no file to show, so this is stored as the text it is
            // rather than as a media bubble with nothing in it.
            $body = trim(self::MEDIA_LABELS[$type] . ' ' . $text);
        } elseif ($text !== '') {
            $body = $text;
        } else {
            return null;
        }

        try {
            $sentAt = Carbon::parse((string) ($row['timestamp'] ?? ''))->setTimezone(config('app.timezone'));
        } catch (\Throwable) {
            return null;
        }

        $event = $row['data_json'] ?? null;
        if (is_string($event)) {
            $event = json_decode($event, true);
        }

        // No stored event means the message went out through the API — ours.
        $fromMe = !is_array($event) || $event === []
            || ($row['sender_jid'] ?? null) === 'me'
            || !empty($event['Info']['IsFromMe']);

        return [
            'external_id' => $id,
            'sender_type' => $fromMe ? SenderType::Outgoing : SenderType::Incoming,
            'message_type' => MessageType::Text,
            'body' => $body,
            'sent_at' => $sentAt,
            'meta' => ['history_import' => true, 'history_type' => $type ?: 'text'],
        ];
    }

    /**
     * Drop the messages this connection already holds — the live webhook may
     * have delivered some of them before the import got to this chat.
     */
    protected function withoutStoredMessages(Connection $connection, array $messages): array
    {
        if ($messages === []) {
            return [];
        }

        $stored = Message::whereHas('conversation', fn ($q) => $q->where('connection_id', $connection->id))
            ->whereIn('external_id', array_column($messages, 'external_id'))
            ->pluck('external_id')
            ->all();

        return array_values(array_filter(
            $messages,
            fn ($message) => !in_array($message['external_id'], $stored, true),
        ));
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

        self::announce($connection);
    }

    /**
     * Tell the dashboards the import moved on. The inbox shows a spinner on
     * this connection's tab while it is queued or running, and without this
     * the spinner would only appear, or go away, on the next reload.
     */
    protected static function announce(Connection $connection): void
    {
        try {
            broadcast(new ConnectionUpdated($connection));
        } catch (\Throwable $th) {
            // A dashboard that does not light up is no reason to lose the import.
            Log::warning('ImportWhatsappChatHistory: could not announce the state', [
                'connection_id' => $connection->id,
                'error' => $th->getMessage(),
            ]);
        }
    }
}

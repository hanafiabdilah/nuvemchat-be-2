<?php

namespace App\Services\Webhook\Handlers\Chat;

use App\Enums\Connection\Status as ConnectionStatus;
use App\Enums\Conversation\Status as ConversationStatus;
use App\Enums\Message\SenderType;
use App\Events\ConnectionUpdated;
use App\Events\ConversationUpdated;
use App\Jobs\ProcessCoexistenceWebhook;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * WhatsApp Coexistence (same number on the WA Business App AND Cloud API).
 *
 * Handles the three extra WABA webhook fields Meta sends for coexistence
 * numbers, plus account_update:
 *   - smb_message_echoes: messages the business sends FROM THE PHONE APP.
 *     Ingested inline as Outgoing so the dashboard mirrors the app chat.
 *   - history: the backlog (~6 months) the business agreed to share. Arrives
 *     in chunks; each chunk is queued (ProcessCoexistenceWebhook) and
 *     ingested quietly — no flows, no notification sounds, messages read.
 *   - smb_app_state_sync: the business's phone contact book (adds/updates).
 *     Also queued.
 *   - account_update PARTNER_REMOVED: business disconnected us from the
 *     app (Settings > Account > Business Platform) — connection goes Inactive.
 *
 * Extends WhatsappOfficialHandler so echoes/history reuse the exact same
 * parsing (getMessageBody/Type/SentAt) and, for echoes, the same
 * handleMessage pipeline (dedupe, media download, broadcasts).
 */
class WhatsappCoexistenceHandler extends WhatsappOfficialHandler
{
    public const FIELDS = ['smb_message_echoes', 'history', 'smb_app_state_sync', 'account_update'];

    /**
     * Entry point from the webhook controller: one change (field + value).
     */
    public function handleChange(Connection $connection, array $change): void
    {
        $field = $change['field'] ?? null;
        $value = $change['value'] ?? [];

        switch ($field) {
            case 'smb_message_echoes':
                $this->handleMessageEchoes($connection, $value);
                break;

            case 'history':
            case 'smb_app_state_sync':
                // Chunks can carry hundreds of messages/contacts — never block
                // the webhook response on them.
                ProcessCoexistenceWebhook::dispatch($connection->id, $field, $value);
                break;

            case 'account_update':
                $this->handleAccountUpdate($connection, $value);
                break;

            default:
                Log::warning('WhatsappCoexistenceHandler: unsupported field', [
                    'connection_id' => $connection->id,
                    'field' => $field,
                ]);
        }
    }

    /**
     * An echo message has the same shape as a regular Cloud API message plus
     * a `to` (the customer). Presence of `to` is what flips handleMessage's
     * sender_type to Outgoing (see isOutgoingMessage below).
     */
    public function isOutgoingMessage(array $payload): bool
    {
        return isset($payload['changes'][0]['value']['messages'][0]['to']);
    }

    /**
     * Messages the business sent from the WhatsApp Business app. Reuses the
     * parent handleMessage pipeline via a synthesized standard payload, so
     * dedupe, media download and broadcasts behave exactly like live traffic.
     * Flows never run (parent skips them for Outgoing).
     */
    private function handleMessageEchoes(Connection $connection, array $value): void
    {
        foreach ($value['message_echoes'] ?? [] as $echo) {
            $to = $echo['to'] ?? null;

            // 1:1 chats only — groups/broadcast aren't supported on Cloud API.
            if (!is_string($to) || $to === '' || str_contains($to, '@')) {
                continue;
            }

            $payload = ['changes' => [[
                'field' => 'smb_message_echoes',
                'value' => [
                    // Placeholder name = phone; Contact::createFromExternalData
                    // refuses to overwrite a real name with it later.
                    'contacts' => [['wa_id' => $to, 'profile' => ['name' => $to]]],
                    'messages' => [$echo],
                ],
            ]]];

            try {
                $this->handleMessage($connection, $payload);
            } catch (\Throwable $th) {
                Log::error('WhatsappCoexistenceHandler: failed to ingest echo', [
                    'connection_id' => $connection->id,
                    'message_id' => $echo['id'] ?? null,
                    'error' => $th->getMessage(),
                ]);
            }
        }
    }

    /**
     * One `history` webhook chunk (called from ProcessCoexistenceWebhook).
     * Threads become conversations; messages keep their original direction
     * and timestamps, arrive read (no unread storm) and never start flows.
     * Media is NOT downloaded for history (type + caption only) — a 6-month
     * backlog would mean thousands of Graph downloads.
     */
    public function ingestHistoryChunk(Connection $connection, array $value): void
    {
        $businessPhone = $this->normalizePhone($value['metadata']['display_phone_number'] ?? '');

        foreach ($value['history'] ?? [] as $chunk) {
            // Business declined history sharing in the app.
            if (!empty($chunk['errors'])) {
                $this->setSyncState($connection, ['history' => [
                    'status' => 'declined',
                    'error' => $chunk['errors'][0]['title'] ?? 'History sync declined',
                ]]);
                Log::info('WhatsappCoexistenceHandler: history sync declined by business', [
                    'connection_id' => $connection->id,
                ]);
                continue;
            }

            $meta = $chunk['metadata'] ?? [];
            $imported = 0;

            foreach ($chunk['threads'] ?? [] as $thread) {
                try {
                    $imported += DB::transaction(fn () => $this->ingestThread($connection, $thread, $businessPhone));
                } catch (\Throwable $th) {
                    Log::warning('WhatsappCoexistenceHandler: history thread skipped after error', [
                        'connection_id' => $connection->id,
                        'thread_id' => $thread['id'] ?? null,
                        'error' => $th->getMessage(),
                    ]);
                }
            }

            $previous = $connection->credentials['smb_data_sync']['history'] ?? [];
            $this->setSyncState($connection, ['history' => [
                'status' => ((int) ($meta['progress'] ?? 0)) >= 100 ? 'done' : 'receiving',
                'phase' => $meta['phase'] ?? null,
                'progress' => $meta['progress'] ?? null,
                'chunks' => (int) ($previous['chunks'] ?? 0) + 1,
                'messages' => (int) ($previous['messages'] ?? 0) + $imported,
            ]]);
        }

        broadcast(new ConnectionUpdated($connection->fresh()));
    }

    /**
     * @return int number of messages created for this thread
     */
    private function ingestThread(Connection $connection, array $thread, string $businessPhone): int
    {
        $waId = $this->normalizePhone((string) ($thread['id'] ?? ''));

        if ($waId === '' || !preg_match('/^\d{6,20}$/', $waId)) {
            return 0;
        }

        $contact = Contact::createFromExternalData($connection, $waId, $waId, $waId);

        // Reuse any conversation for this contact+connection (open or
        // resolved) — history must land in the existing chat, not fork it.
        $conversation = Conversation::where('contact_id', $contact->id)
            ->where('connection_id', $connection->id)
            ->orderByDesc('id')
            ->first();

        if (!$conversation) {
            $conversation = Conversation::create([
                'contact_id' => $contact->id,
                'connection_id' => $connection->id,
                'external_id' => $waId,
                'status' => ConversationStatus::Pending,
            ]);
        }

        $messages = collect($thread['messages'] ?? [])
            ->filter(fn ($msg) => is_array($msg) && !empty($msg['id']))
            // Oldest first so the Message::created hook lands last_message_at
            // on the newest one.
            ->sortBy(fn ($msg) => (int) ($msg['timestamp'] ?? 0))
            ->values();

        $created = 0;

        foreach ($messages as $msg) {
            if ($conversation->messages()->where('external_id', $msg['id'])->exists()) {
                continue;
            }

            $payload = ['changes' => [['value' => ['messages' => [$msg]]]]];
            $sentAt = $this->getMessageSentAt($payload);
            $isOutgoing = $this->normalizePhone((string) ($msg['from'] ?? '')) === $businessPhone;

            $conversation->messages()->create([
                'external_id' => $msg['id'],
                'sender_type' => $isOutgoing ? SenderType::Outgoing : SenderType::Incoming,
                'message_type' => $this->getMessageType($payload),
                'body' => $this->getMessageBody($payload),
                'sent_at' => $sentAt,
                'delivery_at' => $sentAt,
                // Read: imported history must not create an unread storm.
                'read_at' => now(),
                'meta' => [
                    'coex_history' => true,
                    'history_context' => $msg['history_context'] ?? null,
                    'message' => $msg,
                ],
            ]);
            $created++;
        }

        // The created-hook tracks insertion order; a live message racing the
        // import could get regressed — pin last_message_at to the true max.
        $latest = $conversation->messages()->max('sent_at');
        if ($latest) {
            $conversation->update(['last_message_at' => $latest]);
        }

        if ($created > 0) {
            // Quiet realtime update: lists refresh, no sounds/toasts.
            broadcast(new ConversationUpdated($conversation->fresh()->load('contact')));
        }

        return $created;
    }

    /**
     * Contact book sync (called from ProcessCoexistenceWebhook). Adds/updates
     * only — removals from the phone never delete CRM contacts.
     */
    public function ingestStateSync(Connection $connection, array $value): void
    {
        $synced = 0;

        foreach ($value['state_sync'] ?? [] as $item) {
            if (($item['type'] ?? null) !== 'contact') {
                continue;
            }
            if (($item['action'] ?? 'add') === 'remove') {
                continue;
            }

            $contactData = $item['contact'] ?? [];
            $phone = $this->normalizePhone((string) ($contactData['phone_number'] ?? ''));

            if ($phone === '' || !preg_match('/^\d{6,20}$/', $phone)) {
                continue;
            }

            $name = trim((string) ($contactData['full_name'] ?? $contactData['first_name'] ?? ''));

            try {
                Contact::createFromExternalData($connection, $phone, $name !== '' ? $name : $phone, $phone);
                $synced++;
            } catch (\Throwable $th) {
                Log::warning('WhatsappCoexistenceHandler: contact sync entry skipped', [
                    'connection_id' => $connection->id,
                    'error' => $th->getMessage(),
                ]);
            }
        }

        $previous = $connection->credentials['smb_data_sync'] ?? [];
        $this->setSyncState($connection, [
            'contacts_synced' => (int) ($previous['contacts_synced'] ?? 0) + $synced,
        ]);

        broadcast(new ConnectionUpdated($connection->fresh()));

        Log::info('WhatsappCoexistenceHandler: contacts synced', [
            'connection_id' => $connection->id,
            'synced' => $synced,
        ]);
    }

    /**
     * account_update events. Coexistence offboarding happens in the phone app
     * only (Disconnect Account) and surfaces here as PARTNER_REMOVED.
     */
    private function handleAccountUpdate(Connection $connection, array $value): void
    {
        $event = $value['event'] ?? null;

        if ($event === 'PARTNER_REMOVED') {
            $connection->update(['status' => ConnectionStatus::Inactive]);
            broadcast(new ConnectionUpdated($connection->fresh()));

            Log::warning('WhatsappCoexistenceHandler: business disconnected from Business Platform (PARTNER_REMOVED)', [
                'connection_id' => $connection->id,
            ]);
            return;
        }

        Log::info('WhatsappCoexistenceHandler: account_update received', [
            'connection_id' => $connection->id,
            'event' => $event,
            'value' => $value,
        ]);
    }

    /**
     * Merge fields into credentials.smb_data_sync on a fresh copy of the row.
     * Nested arrays (e.g. `history`) are merged one level deep.
     */
    private function setSyncState(Connection $connection, array $fields): void
    {
        $connection->refresh();
        $credentials = $connection->credentials ?? [];
        $state = $credentials['smb_data_sync'] ?? [];

        foreach ($fields as $key => $fieldValue) {
            $state[$key] = is_array($fieldValue)
                ? array_merge(is_array($state[$key] ?? null) ? $state[$key] : [], $fieldValue)
                : $fieldValue;
        }

        $credentials['smb_data_sync'] = $state;
        $connection->update(['credentials' => $credentials]);
    }

    private function normalizePhone(string $value): string
    {
        // "+55 11 99999-9999" / "5511999999999@s.whatsapp.net" → "5511999999999"
        $value = strstr($value, '@', true) ?: $value;

        return preg_replace('/\D+/', '', $value) ?? '';
    }
}

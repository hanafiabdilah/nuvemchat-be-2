<?php

namespace App\Services\Email;

use App\Enums\Connection\Status as ConnectionStatus;
use App\Enums\Connection\SyncStatus;
use App\Enums\Conversation\Status as ConversationStatus;
use App\Enums\Message\MessageType;
use App\Enums\Message\SenderType;
use App\Events\ConnectionUpdated;
use App\Events\ConversationUpdated;
use App\Events\MessageReceived;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Pulls one batch of inbound mail for a single connection and keeps the
 * connection's sync state current so the SPA can show progress.
 *
 * A pass is deliberately bounded: the first sync of an existing mailbox can
 * span thousands of messages, and fetching them all in one run is what makes
 * the inbox look permanently empty (the run dies before committing anything).
 */
class EmailInboxSynchronizer
{
    /** Messages pulled per pass. Bodies + attachments make this the costly part. */
    public const BATCH_SIZE = 200;

    /** A 'syncing' connection older than this is treated as a dead run. */
    public const STALE_AFTER_MINUTES = 15;

    public function __construct(
        private readonly EmailInboxClientFactory $clientFactory,
    ) {}

    /**
     * @return int Messages imported in this pass.
     */
    public function sync(Connection $connection): int
    {
        if ($connection->status !== ConnectionStatus::Active) {
            return 0;
        }

        $this->markSyncing($connection);

        $client = null;
        $imported = 0;

        try {
            $lastSeenUid = (int) ($connection->last_seen_uid ?? 0);
            $maxSeenUid = $lastSeenUid;
            $client = $this->clientFactory->make($connection);

            foreach ($client->fetchSince($lastSeenUid, self::BATCH_SIZE) as $email) {
                if (! $email->fromEmail) {
                    Log::warning('EmailInboxSynchronizer: skipping message without sender', [
                        'connection_id' => $connection->id,
                        'uid' => $email->uid,
                    ]);

                    // Still advance the cursor, or this message blocks the batch forever.
                    $maxSeenUid = max($maxSeenUid, $email->uid);

                    continue;
                }

                $message = $this->persistEmail($connection, $email);
                $maxSeenUid = max($maxSeenUid, $email->uid);
                $imported++;

                broadcast(new MessageReceived($message));
                broadcast(new ConversationUpdated($message->conversation->load('contact')));
            }

            $remaining = $client->countSince($maxSeenUid);

            $connection->forceFill([
                'last_seen_uid' => $maxSeenUid,
                'last_synced_at' => now(),
                'sync_status' => SyncStatus::Idle,
                'sync_error' => null,
                'sync_remaining' => $remaining,
                'sync_started_at' => null,
            ])->save();

            return $imported;
        } catch (\Throwable $exception) {
            Log::error('EmailInboxSynchronizer: failed to fetch connection inbox', [
                'connection_id' => $connection->id,
                'tenant_id' => $connection->tenant_id,
                'error' => $exception->getMessage(),
            ]);

            $connection->forceFill([
                'sync_status' => SyncStatus::Failed,
                'sync_error' => Str::limit($exception->getMessage(), 500),
                'sync_started_at' => null,
            ])->save();

            throw $exception;
        } finally {
            $client?->disconnect();
            broadcast(new ConnectionUpdated($connection->refresh()));
        }
    }

    /**
     * True when a sync is already running and has not gone stale — used to
     * avoid stacking passes over the same mailbox.
     */
    public function isSyncing(Connection $connection): bool
    {
        if ($connection->sync_status !== SyncStatus::Syncing) {
            return false;
        }

        $startedAt = $connection->sync_started_at;

        return $startedAt !== null
            && $startedAt->gt(now()->subMinutes(self::STALE_AFTER_MINUTES));
    }

    private function markSyncing(Connection $connection): void
    {
        $connection->forceFill([
            'sync_status' => SyncStatus::Syncing,
            'sync_error' => null,
            'sync_started_at' => now(),
        ])->save();

        broadcast(new ConnectionUpdated($connection));
    }

    private function persistEmail(Connection $connection, InboundEmail $email): Message
    {
        return DB::transaction(function () use ($connection, $email) {
            $contact = Contact::createFromExternalData(
                $connection,
                $email->fromEmail,
                $email->fromName ?: $email->fromEmail
            );

            $conversation = $this->findConversationByHeaders($connection, $email)
                ?: $this->findOrCreateConversationBySubject($connection, $contact, $email);

            $externalId = $email->messageId ?: "email:{$connection->id}:uid:{$email->uid}";
            $sentAt = $email->sentAt ? Carbon::instance($email->sentAt) : now();
            $meta = [
                'email' => [
                    'subject' => $email->subject,
                    'subject_normalized' => $this->normalizeSubject($email->subject),
                    'from' => $email->fromEmail,
                    'to' => $email->to,
                    'cc' => $email->cc,
                    'message_id' => $email->messageId,
                    'in_reply_to' => $email->inReplyTo,
                    'references' => $email->references,
                ],
            ];

            $message = Message::updateOrCreate([
                'external_id' => $externalId,
            ], [
                'conversation_id' => $conversation->id,
                'sender_type' => SenderType::Incoming,
                'message_type' => MessageType::Text,
                'body' => $this->plainTextBody($email),
                'sent_at' => $sentAt,
                'delivery_at' => $sentAt,
                'meta' => $meta,
            ]);

            $this->storeAttachments($message, $email->attachments, $meta);

            return $message->refresh();
        });
    }

    private function findConversationByHeaders(Connection $connection, InboundEmail $email): ?Conversation
    {
        $messageIds = array_values(array_unique(array_filter([
            $email->inReplyTo,
            ...$email->references,
        ])));

        if (empty($messageIds)) {
            return null;
        }

        $message = Message::whereIn('external_id', $messageIds)
            ->whereHas('conversation', function ($query) use ($connection) {
                $query->where('connection_id', $connection->id)
                    ->whereIn('status', [
                        ConversationStatus::Active,
                        ConversationStatus::Pending,
                        ConversationStatus::AiHandling,
                    ]);
            })
            ->latest('id')
            ->first();

        return $message?->conversation;
    }

    private function findOrCreateConversationBySubject(
        Connection $connection,
        Contact $contact,
        InboundEmail $email
    ): Conversation {
        $externalId = $this->conversationExternalId($contact, $email->subject);

        $conversation = Conversation::where('external_id', $externalId)
            ->where('contact_id', $contact->id)
            ->where('connection_id', $connection->id)
            ->whereIn('status', [
                ConversationStatus::Active,
                ConversationStatus::Pending,
                ConversationStatus::AiHandling,
            ])
            ->first();

        if ($conversation) {
            return $conversation;
        }

        // E-mail is a shared inbox with no accept/assign step: create the
        // conversation already Active so agents can reply immediately (the
        // send guards require status Active). It stays unassigned (user_id
        // null); access is granted to owner + connection members via
        // Conversation::isAccessibleBy().
        return Conversation::create([
            'contact_id' => $contact->id,
            'connection_id' => $connection->id,
            'external_id' => $externalId,
            'status' => ConversationStatus::Active,
        ]);
    }

    public function conversationExternalId(Contact $contact, ?string $subject): string
    {
        return 'email:'.sha1($contact->id.'|'.$this->normalizeSubject($subject));
    }

    private function normalizeSubject(?string $subject): string
    {
        $subject = trim((string) $subject);

        do {
            $previous = $subject;
            $subject = preg_replace('/^\s*(re|fw|fwd)\s*:\s*/i', '', $subject) ?? $subject;
        } while ($subject !== $previous);

        return Str::of($subject)->squish()->lower()->toString();
    }

    private function plainTextBody(InboundEmail $email): string
    {
        $text = trim((string) $email->textBody);

        if ($text === '' && $email->htmlBody) {
            $html = preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is', ' ', $email->htmlBody) ?? $email->htmlBody;
            $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        return Str::of($text)->replaceMatches('/[ \t]+/', ' ')->trim()->toString();
    }

    /**
     * @param  array<int, InboundEmailAttachment>  $attachments
     * @param  array<string, mixed>  $meta
     */
    private function storeAttachments(Message $message, array $attachments, array $meta): void
    {
        if (empty($attachments) || ($message->attachment && ! $message->wasRecentlyCreated)) {
            return;
        }

        $stored = [];

        foreach ($attachments as $attachment) {
            $path = $this->storeAttachment($message, $attachment);
            $stored[] = [
                'name' => $attachment->filename,
                'content_type' => $attachment->contentType,
                'path' => $path,
            ];
        }

        $meta['email']['attachments'] = $stored;

        $message->update([
            'attachment' => $stored[0]['path'] ?? null,
            'meta' => $meta,
        ]);
    }

    private function storeAttachment(Message $message, InboundEmailAttachment $attachment): string
    {
        $filename = basename(str_replace('\\', '/', $attachment->filename));
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        $safeName = Str::slug(pathinfo($filename, PATHINFO_FILENAME)) ?: 'attachment';
        $path = 'media/'.$message->id.'_'.uniqid().'_'.$safeName;

        if ($extension) {
            $path .= '.'.strtolower($extension);
        }

        Storage::disk('local')->put($path, $attachment->content);

        return $path;
    }
}

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
 * Each pass runs two phases against the mailbox:
 *  - tail: mail above `last_seen_uid` (new arrivals), in arrival order;
 *  - backfill: history inside the connection's sync window
 *    (`sync_window_days`, null = everything), newest first, walking
 *    `backfill_uid` downward until the window is drained (`backfill_done`).
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

    /** Byte cap for `messages.body` — the TEXT column tops out at 64KB. */
    public const MAX_BODY_BYTES = 60000;

    /** Persist failures tolerated per pass before treating them as systemic. */
    public const MAX_PERSIST_FAILURES = 10;

    /**
     * Messages above this RFC822.SIZE are skipped without downloading (the
     * client yields an OversizedEmail marker instead). Webklex buffers the
     * whole message in memory plus parsed copies, so one 47MB newsletter
     * fatally OOMed the 128MB email worker on every pass — during FETCH,
     * where no persist-level guard can run — and the cursor never moved past
     * it. 8MB keeps the peak comfortably inside the worker's memory limit.
     */
    public const MAX_MESSAGE_BYTES = 8 * 1024 * 1024;

    /**
     * Wall-clock budget for one pass, safely under SyncEmailInbox::$timeout
     * (280s). A mailbox slow enough to outlive the job timeout mid-batch
     * keeps its cursor (saved per message) but stays 'syncing' until the
     * stale window expires — a 15-minute dead stop every pass. Ending the
     * pass cleanly instead lets the next minute's tick resume immediately.
     */
    public const MAX_PASS_SECONDS = 240;

    /** Wall-clock cutoff for the running pass (epoch seconds, microtime). */
    private float $deadline = 0.0;

    /** Persist failures seen in the running pass (see MAX_PERSIST_FAILURES). */
    private int $failures = 0;

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
            $this->deadline = microtime(true) + self::MAX_PASS_SECONDS;
            $this->failures = 0;
            $budget = self::BATCH_SIZE;
            $client = $this->clientFactory->make($connection);

            $cutoff = $connection->sync_window_days
                ? now()->subDays((int) $connection->sync_window_days)
                : null;

            // Phase 1 — tail: mail that arrived after the newest message
            // already imported, in arrival order. Runs first so a live inbox
            // stays current even while a long backfill is still draining.
            if ((int) $connection->last_seen_uid > 0) {
                $tail = $client->uidsAfter((int) $connection->last_seen_uid);

                if (count($tail) > $budget) {
                    // A gap bigger than one pass is not "new mail" — it is a
                    // backlog (a mailbox migrated mid-sync from the old
                    // oldest-first walk, or a long offline stretch). Draining
                    // it here would ignore the sync window and run oldest-
                    // first, with sync_remaining stuck at the full backlog.
                    // Fold it into the backfill walk instead: pin the ceiling
                    // to the mailbox top and restart the floor from up there.
                    // Out-of-window mail is then never fetched at all, and
                    // any stretch that is already local is re-persisted
                    // idempotently (no duplicates) as the floor passes it.
                    $connection->forceFill([
                        'last_seen_uid' => max($tail),
                        'backfill_uid' => null,
                        'backfill_done' => false,
                    ])->save();
                } else {
                    [$processed, $importedNow] = $this->importUids($connection, $client, $tail,
                        fn (Connection $c, int $uid) => $c->forceFill([
                            'last_seen_uid' => max((int) $c->last_seen_uid, $uid),
                        ])->save()
                    );

                    $imported += $importedNow;
                    $budget -= $processed;
                }
            }

            // Phase 2 — backfill: history inside the sync window, newest
            // first, so the inbox fills with current conversations before old
            // ones. Walks the UID floor downward until the window is drained.
            if (! $connection->backfill_done && $budget > 0 && microtime(true) < $this->deadline) {
                $pool = $client->uidsWithin($cutoff, $connection->backfill_uid);

                if ((int) $connection->last_seen_uid === 0) {
                    // First pass ever: pin the tail cursor to the top of the
                    // mailbox now, so mail older than the window is never
                    // mistaken for "new" once this backfill finishes.
                    $top = $pool !== [] ? max($pool) : max([0, ...$client->uidsAfter(0)]);
                    $connection->forceFill(['last_seen_uid' => $top])->save();
                }

                // Newest N candidates, descending.
                $batch = array_reverse(array_slice($pool, -$budget));

                [$processed, $importedNow] = $this->importUids($connection, $client, $batch,
                    fn (Connection $c, int $uid) => $c->forceFill([
                        'backfill_uid' => min((int) ($c->backfill_uid ?? PHP_INT_MAX), $uid),
                        'last_seen_uid' => max((int) $c->last_seen_uid, $uid),
                    ])->save()
                );

                $imported += $importedNow;

                // Every candidate was walked in this pass (none were cut off
                // by the budget or the deadline): the window is fully local.
                if ($processed === count($pool)) {
                    $connection->forceFill(['backfill_done' => true])->save();
                }
            }

            $remaining = count($client->uidsAfter((int) $connection->last_seen_uid));

            if (! $connection->backfill_done) {
                $remaining += count($client->uidsWithin($cutoff, $connection->backfill_uid));
            }

            $connection->forceFill([
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
     * Fetch and persist the given UIDs in order. $advance moves the calling
     * phase's cursor past a UID once it is safely imported (or deliberately
     * skipped), per message rather than per pass: a slow mailbox (Gmail
     * bodies + attachments) can hit the job timeout mid-batch, and losing the
     * cursor would restart the same batch forever.
     *
     * @param  array<int, int>  $uids
     * @param  \Closure(Connection, int): void  $advance
     * @return array{0: int, 1: int} [UIDs processed, messages imported]
     */
    private function importUids(Connection $connection, EmailInboxClient $client, array $uids, \Closure $advance): array
    {
        if ($uids === []) {
            return [0, 0];
        }

        $processed = 0;
        $imported = 0;

        foreach ($client->fetch($uids, self::MAX_MESSAGE_BYTES) as $email) {
            if (microtime(true) >= $this->deadline) {
                break;
            }

            if ($email instanceof OversizedEmail) {
                Log::warning('EmailInboxSynchronizer: skipping oversized message', [
                    'connection_id' => $connection->id,
                    'tenant_id' => $connection->tenant_id,
                    'uid' => $email->uid,
                    'bytes' => $email->bytes,
                ]);

                $advance($connection, $email->uid);
                $processed++;

                continue;
            }

            if (! $email->fromEmail) {
                Log::warning('EmailInboxSynchronizer: skipping message without sender', [
                    'connection_id' => $connection->id,
                    'uid' => $email->uid,
                ]);

                // Still advance the cursor, or this message blocks the batch forever.
                $advance($connection, $email->uid);
                $processed++;

                continue;
            }

            try {
                $message = $this->persistEmail($connection, $email);
            } catch (\Throwable $exception) {
                // One message the DB refuses must not wedge the whole
                // mailbox: the cursor only moved after a successful
                // persist, so a single poison email was refetched and
                // refailed every pass forever. Log it, advance past it,
                // keep going. A systemic outage is different: if the DB
                // is down the cursor save below throws too and the pass
                // aborts through the outer catch, and repeated failures
                // bail out instead of skipping through the backlog.
                if (++$this->failures > self::MAX_PERSIST_FAILURES) {
                    throw $exception;
                }

                Log::error('EmailInboxSynchronizer: skipping message that failed to persist', [
                    'connection_id' => $connection->id,
                    'tenant_id' => $connection->tenant_id,
                    'uid' => $email->uid,
                    'message_id' => $email->messageId,
                    'error' => $exception->getMessage(),
                ]);

                $advance($connection, $email->uid);
                $processed++;

                continue;
            }

            $advance($connection, $email->uid);
            $processed++;
            $imported++;

            broadcast(new MessageReceived($message));
            broadcast(new ConversationUpdated($message->conversation->load('contact')));
        }

        return [$processed, $imported];
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
        $email = $this->sanitize($email);

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

            $meta = $this->storeHtmlBody($message, $email, $meta);
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

        $text = Str::of($text)->replaceMatches('/[ \t]+/', ' ')->trim()->toString();

        // The body column is TEXT (64KB); mb_strcut trims on a byte budget
        // without splitting a multibyte character at the cut point.
        return mb_strcut($text, 0, self::MAX_BODY_BYTES);
    }

    /**
     * Webklex decodes RFC2047 *headers* but leaves bodies in whatever charset
     * the sender declared — ISO-8859-1 mail is still common — and MySQL
     * rejects non-UTF-8 bytes outright (error 1366), which used to wedge a
     * whole mailbox on one legacy message. Normalize every string that can
     * reach a table column or the meta JSON before touching the database.
     */
    private function sanitize(InboundEmail $email): InboundEmail
    {
        $utf8 = fn (?string $value): ?string => $value === null ? null : $this->utf8($value);

        return new InboundEmail(
            uid: $email->uid,
            messageId: $utf8($email->messageId),
            fromEmail: $this->utf8($email->fromEmail),
            fromName: $utf8($email->fromName),
            subject: $utf8($email->subject),
            to: array_map($this->utf8(...), $email->to),
            cc: array_map($this->utf8(...), $email->cc),
            inReplyTo: $utf8($email->inReplyTo),
            references: array_map($this->utf8(...), $email->references),
            textBody: $utf8($email->textBody),
            htmlBody: $utf8($email->htmlBody),
            sentAt: $email->sentAt,
            attachments: $email->attachments,
        );
    }

    private function utf8(string $text): string
    {
        if ($text === '' || mb_check_encoding($text, 'UTF-8')) {
            return $text;
        }

        // Windows-1252 (superset of ISO-8859-1) maps every byte, so this
        // always yields valid UTF-8 and is the right guess for legacy
        // single-byte mail.
        $converted = mb_convert_encoding($text, 'UTF-8', 'Windows-1252');

        return is_string($converted) && mb_check_encoding($converted, 'UTF-8')
            ? $converted
            // Last resort: drop the invalid sequences rather than fail the insert.
            : (string) mb_convert_encoding($text, 'UTF-8', 'UTF-8');
    }

    /**
     * Persist the raw HTML body as a file next to the attachments. It is kept
     * off the messages row on purpose: broadcasts are size-capped (see
     * MessageReceived::BROADCAST_BODY_LIMIT) and the SPA caches every message
     * in IndexedDB, so the HTML is fetched on demand instead
     * (GET /conversations/{id}/messages/{id}/email-html). The SPA sanitizes it
     * before rendering.
     *
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    private function storeHtmlBody(Message $message, InboundEmail $email, array $meta): array
    {
        $html = trim((string) $email->htmlBody);

        if ($html === '') {
            return $meta;
        }

        $path = 'media/'.$message->id.'_'.uniqid().'_body.html';
        Storage::disk('local')->put($path, $html);

        $meta['email']['html_path'] = $path;
        $message->update(['meta' => $meta]);

        return $meta;
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
                // The name lands in the meta JSON cast, which refuses to
                // encode invalid UTF-8 just like the body column does.
                'name' => $this->utf8($attachment->filename),
                'content_type' => $attachment->contentType,
                'content_id' => $attachment->contentId,
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

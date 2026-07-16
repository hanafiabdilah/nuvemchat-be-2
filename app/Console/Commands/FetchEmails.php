<?php

namespace App\Console\Commands;

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status as ConnectionStatus;
use App\Enums\Conversation\Status as ConversationStatus;
use App\Enums\Message\MessageType;
use App\Enums\Message\SenderType;
use App\Events\ConversationUpdated;
use App\Events\MessageReceived;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Email\EmailInboxClientFactory;
use App\Services\Email\InboundEmail;
use App\Services\Email\InboundEmailAttachment;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class FetchEmails extends Command
{
    protected $signature = 'email:fetch';

    protected $description = 'Fetch new inbound emails from active email connections';

    public function handle(EmailInboxClientFactory $clientFactory): int
    {
        $connections = Connection::where('channel', Channel::Email)
            ->where('status', ConnectionStatus::Active)
            ->get();

        if ($connections->isEmpty()) {
            $this->info('No active email connections found.');

            return Command::SUCCESS;
        }

        $processed = 0;
        $failed = 0;

        foreach ($connections as $connection) {
            $client = null;

            try {
                $lastSeenUid = (int) ($connection->last_seen_uid ?? 0);
                $maxSeenUid = $lastSeenUid;
                $client = $clientFactory->make($connection);

                foreach ($client->fetchSince($lastSeenUid) as $email) {
                    if (!$email->fromEmail) {
                        Log::warning('FetchEmails: skipping message without sender', [
                            'connection_id' => $connection->id,
                            'uid' => $email->uid,
                        ]);
                        continue;
                    }

                    $message = $this->persistEmail($connection, $email);
                    $maxSeenUid = max($maxSeenUid, $email->uid);
                    $processed++;

                    broadcast(new MessageReceived($message));
                    broadcast(new ConversationUpdated($message->conversation->load('contact')));
                }

                $connection->forceFill([
                    'last_seen_uid' => $maxSeenUid,
                    'last_synced_at' => now(),
                ])->save();
            } catch (\Throwable $exception) {
                $failed++;

                Log::error('FetchEmails: failed to fetch connection inbox', [
                    'connection_id' => $connection->id,
                    'tenant_id' => $connection->tenant_id,
                    'error' => $exception->getMessage(),
                ]);

                $this->error("Connection #{$connection->id}: {$exception->getMessage()}");
            } finally {
                if ($client) {
                    $client->disconnect();
                }
            }
        }

        $this->info("Processed {$processed} email message(s); {$failed} connection(s) failed.");

        return Command::SUCCESS;
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

        return Conversation::create([
            'contact_id' => $contact->id,
            'connection_id' => $connection->id,
            'external_id' => $externalId,
            'status' => ConversationStatus::Pending,
        ]);
    }

    private function conversationExternalId(Contact $contact, ?string $subject): string
    {
        return 'email:' . sha1($contact->id . '|' . $this->normalizeSubject($subject));
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
     * @param array<int, InboundEmailAttachment> $attachments
     * @param array<string, mixed> $meta
     */
    private function storeAttachments(Message $message, array $attachments, array $meta): void
    {
        if (empty($attachments) || ($message->attachment && !$message->wasRecentlyCreated)) {
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
        $path = 'media/' . $message->id . '_' . uniqid() . '_' . $safeName;

        if ($extension) {
            $path .= '.' . strtolower($extension);
        }

        Storage::disk('local')->put($path, $attachment->content);

        return $path;
    }
}

<?php

namespace App\Services\Email;

use Carbon\CarbonInterface;
use Webklex\PHPIMAP\Attribute;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\Folder;
use Webklex\PHPIMAP\IMAP;
use Webklex\PHPIMAP\Message as ImapMessage;

class WebklexEmailInboxClient implements EmailInboxClient
{
    /**
     * Messages per IMAP FETCH round. get() materializes every message in the
     * requested range — bodies included — before returning anything, so a
     * whole 200-message batch from a slow server (Gmail) can outlive the job
     * timeout without yielding a single message, starving the caller's
     * per-message cursor saves. Small chunks make progress durable.
     */
    private const FETCH_CHUNK = 25;

    public function __construct(
        private readonly Client $client,
        private readonly Folder $folder,
    ) {}

    public function fetchSince(int $lastSeenUid, int $limit): iterable
    {
        $uids = $this->pendingUids($lastSeenUid);

        if ($uids === []) {
            return;
        }

        // UIDs are sparse (deleted mail leaves gaps), so slice the actual UID
        // list rather than walking a fixed-width numeric window — otherwise a
        // large gap would burn whole passes fetching nothing.
        $batch = array_slice($uids, 0, $limit);

        // Each chunk is a contiguous slice of the sorted existing UIDs, so the
        // first:last range covers exactly its members.
        // CUSTOM = raw, unquoted criteria. whereUid() quotes the range
        // (UID SEARCH UID "1:35") and Gmail rejects quoted sequence-sets with
        // "BAD Could not parse command"; Dovecot merely tolerates them.
        foreach (array_chunk($batch, self::FETCH_CHUNK) as $chunk) {
            $messages = $this->folder
                ->query()
                ->where('CUSTOM UID '.reset($chunk).':'.end($chunk))
                ->setSequence(IMAP::ST_UID)
                ->leaveUnread()
                ->setFetchBody(true)
                ->fetchOrder('asc')
                ->get();

            foreach ($messages as $message) {
                yield $this->mapMessage($message);
            }
        }
    }

    public function countSince(int $lastSeenUid): int
    {
        return count($this->pendingUids($lastSeenUid));
    }

    /**
     * UIDs above $lastSeenUid, ascending. SEARCH only — this spans the whole
     * backlog, so it must never fetch headers or bodies: on a large mailbox
     * that fetch is what blew the socket timeout ("Empty response") and left
     * the first sync permanently failed.
     *
     * @return array<int, int>
     */
    private function pendingUids(int $lastSeenUid): array
    {
        $startUid = max(1, $lastSeenUid + 1);

        // CUSTOM keeps the range unquoted (Gmail-safe, see fetchSince) and
        // ST_UID makes the server both match and answer in UIDs.
        $uids = $this->folder
            ->query()
            ->where('CUSTOM UID '.$startUid.':*')
            ->setSequence(IMAP::ST_UID)
            ->search()
            ->map(fn ($uid) => (int) $uid)
            // An `n:*` range still returns the highest message when n is past
            // the top of the mailbox, so re-check the bound here.
            ->filter(fn (int $uid) => $uid >= $startUid)
            ->unique()
            ->sort()
            ->values()
            ->all();

        return $uids;
    }

    public function disconnect(): void
    {
        try {
            $this->client->disconnect();
        } catch (\Throwable) {
            //
        }
    }

    private function mapMessage(ImapMessage $message): InboundEmail
    {
        $from = $this->firstAddress($message->getFrom());

        return new InboundEmail(
            uid: (int) $message->getUid(),
            messageId: $this->normalizeMessageId($this->firstString($message->getMessageId())),
            fromEmail: strtolower((string) ($from['email'] ?? '')),
            fromName: $from['name'] ?? null,
            subject: $this->firstString($message->getSubject()),
            to: $this->addressList($message->getTo()),
            cc: $this->addressList($message->getCc()),
            inReplyTo: $this->normalizeMessageId($this->firstString($message->getInReplyTo())),
            // getReferences() devolve null quando o header nao existe - o que e o caso
            // do primeiro e-mail de qualquer thread.
            references: array_values(array_filter(array_map(
                fn ($reference) => $this->normalizeMessageId((string) $reference),
                $message->getReferences()?->toArray() ?? []
            ))),
            textBody: $message->getTextBody(),
            htmlBody: $message->getHTMLBody(),
            sentAt: $this->date($message->getDate()),
            attachments: $this->attachments($message),
        );
    }

    private function firstString(?Attribute $attribute): ?string
    {
        $value = $attribute?->first();

        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * @return array{email?: string, name?: string}
     */
    private function firstAddress(?Attribute $attribute): array
    {
        $address = $attribute?->first();

        if (! $address) {
            return [];
        }

        return [
            'email' => strtolower(trim((string) ($address->mail ?? ''))),
            'name' => trim((string) ($address->personal ?? '')) ?: null,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function addressList(?Attribute $attribute): array
    {
        return array_values(array_filter(array_map(function ($address) {
            $email = strtolower(trim((string) ($address->mail ?? '')));

            return $email ?: null;
        }, $attribute?->toArray() ?? [])));
    }

    private function normalizeMessageId(?string $messageId): ?string
    {
        if ($messageId === null) {
            return null;
        }

        $messageId = trim($messageId);
        $messageId = trim($messageId, '<>');

        return $messageId === '' ? null : $messageId;
    }

    private function date(?Attribute $attribute): ?CarbonInterface
    {
        try {
            return $attribute?->toDate();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array<int, InboundEmailAttachment>
     */
    private function attachments(ImapMessage $message): array
    {
        $attachments = [];

        foreach ($message->getAttachments() as $attachment) {
            $filename = trim((string) ($attachment->getName() ?: $attachment->getFilename() ?: $attachment->getHash()));
            $attachments[] = new InboundEmailAttachment(
                filename: $filename ?: 'attachment',
                content: (string) $attachment->getContent(),
                contentType: $attachment->getMimeType(),
            );
        }

        return $attachments;
    }
}

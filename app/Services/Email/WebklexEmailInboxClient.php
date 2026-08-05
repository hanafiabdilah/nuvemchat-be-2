<?php

namespace App\Services\Email;

use Carbon\CarbonInterface;
use DateTimeInterface;
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

    public function uidsAfter(int $uid): array
    {
        $startUid = max(1, $uid + 1);

        // An `n:*` range still returns the highest message when n is past the
        // top of the mailbox, so re-check the bound here.
        return $this->searchUids(
            'UID '.$startUid.':*',
            fn (int $found) => $found >= $startUid
        );
    }

    public function uidsWithin(?DateTimeInterface $since, ?int $beforeUid): array
    {
        if ($beforeUid !== null && $beforeUid <= 1) {
            return [];
        }

        $criteria = 'UID 1:'.($beforeUid !== null ? $beforeUid - 1 : '*');

        if ($since !== null) {
            // SINCE matches on INTERNALDATE with day granularity; the RFC 3501
            // date is unquoted and always uses English month names, which
            // format() guarantees regardless of the app locale.
            $criteria .= ' SINCE '.$since->format('j-M-Y');
        }

        return $this->searchUids(
            $criteria,
            fn (int $found) => $beforeUid === null || $found < $beforeUid
        );
    }

    public function fetch(array $uids, ?int $maxMessageBytes = null): iterable
    {
        $uids = array_values(array_unique(array_map('intval', $uids)));

        if ($uids === []) {
            return;
        }

        foreach (array_chunk($uids, self::FETCH_CHUNK) as $chunk) {
            // RFC822.SIZE first (SEARCH/FETCH-size only, no bodies): a huge
            // message must never be downloaded at all — Webklex buffers the
            // whole literal in memory and a 47MB mail fatally OOMs the worker
            // mid-read, before any exception handling can run.
            $sizes = $maxMessageBytes !== null ? $this->sizes($chunk) : [];

            $fetchable = array_values(array_filter(
                $chunk,
                fn (int $uid) => $maxMessageBytes === null || ($sizes[$uid] ?? 0) <= $maxMessageBytes
            ));

            $messages = $this->messagesByUid($fetchable);

            // Yield in the caller's order, interleaving oversized markers at
            // their exact position: the caller advances its cursor per yielded
            // item, and an out-of-order marker would jump the cursor past
            // UIDs that have not been walked yet.
            foreach ($chunk as $uid) {
                if ($maxMessageBytes !== null && ($sizes[$uid] ?? 0) > $maxMessageBytes) {
                    yield new OversizedEmail($uid, (int) $sizes[$uid]);
                } elseif (isset($messages[$uid])) {
                    yield $messages[$uid];
                }
            }
        }
    }

    /**
     * Download and map one chunk of UIDs, keyed by UID. The criteria is an
     * explicit comma set, not a min:max range: a range would drag excluded
     * UIDs (an oversized message sitting between two fetchable ones) right
     * back into the download. CUSTOM = raw, unquoted criteria — whereUid()
     * quotes the set and Gmail rejects quoted sequence-sets with "BAD Could
     * not parse command"; Dovecot merely tolerates them.
     *
     * @param  array<int, int>  $uids
     * @return array<int, InboundEmail>
     */
    private function messagesByUid(array $uids): array
    {
        if ($uids === []) {
            return [];
        }

        $requested = array_flip($uids);

        $messages = $this->folder
            ->query()
            ->where('CUSTOM UID '.implode(',', $uids))
            ->setSequence(IMAP::ST_UID)
            ->leaveUnread()
            ->setFetchBody(true)
            ->get();

        $mapped = [];

        foreach ($messages as $message) {
            $email = $this->mapMessage($message);

            if (isset($requested[$email->uid])) {
                $mapped[$email->uid] = $email;
            }
        }

        return $mapped;
    }

    /**
     * RFC822.SIZE for the given UIDs — never fetches headers or bodies. A UID
     * the server does not answer for is simply absent (callers treat missing
     * as small, preserving the plain fetch path).
     *
     * @param  array<int, int>  $uids
     * @return array<int, int>
     */
    private function sizes(array $uids): array
    {
        // A raw FETCH silently returns nothing when no mailbox is selected;
        // query()/search() select implicitly, this call does not.
        $this->client->openFolder($this->folder->path);

        $sizes = [];

        foreach ($this->client->getConnection()->sizes($uids)->data() as $uid => $size) {
            $sizes[(int) $uid] = (int) $size;
        }

        return $sizes;
    }

    /**
     * SEARCH only — a UID scan spans the whole backlog, so it must never fetch
     * headers or bodies: on a large mailbox that fetch is what blew the socket
     * timeout ("Empty response") and left the first sync permanently failed.
     *
     * @param  \Closure(int): bool  $bound  Server answers can overshoot the
     *                                      requested range; re-check it here.
     * @return array<int, int>
     */
    private function searchUids(string $criteria, \Closure $bound): array
    {
        // CUSTOM keeps the criteria unquoted (Gmail-safe, see fetch) and
        // ST_UID makes the server both match and answer in UIDs.
        return $this->folder
            ->query()
            ->where('CUSTOM '.$criteria)
            ->setSequence(IMAP::ST_UID)
            ->search()
            ->map(fn ($uid) => (int) $uid)
            ->filter($bound)
            ->unique()
            ->sort()
            ->values()
            ->all();
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
                // Webklex falls back to a content hash when the part has no
                // Content-ID; a hash never appears as a cid: ref, so it is a
                // harmless value here.
                contentId: $attachment->id ?: null,
            );
        }

        return $attachments;
    }
}

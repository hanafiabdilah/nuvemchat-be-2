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

    public function fetch(array $uids): iterable
    {
        $uids = array_values(array_unique(array_map('intval', $uids)));

        if ($uids === []) {
            return;
        }

        // A min:max range also matches UIDs the caller did not ask for when
        // the list has gaps (mail outside the sync window whose INTERNALDATE
        // is out of UID order), so filter the response back to the request.
        $requested = array_flip($uids);

        // Each chunk is a contiguous slice of the caller's ordered UID list,
        // so the min:max range covers (at least) exactly its members.
        // CUSTOM = raw, unquoted criteria. whereUid() quotes the range
        // (UID SEARCH UID "1:35") and Gmail rejects quoted sequence-sets with
        // "BAD Could not parse command"; Dovecot merely tolerates them.
        foreach (array_chunk($uids, self::FETCH_CHUNK) as $chunk) {
            $first = (int) reset($chunk);
            $last = (int) end($chunk);

            $messages = $this->folder
                ->query()
                ->where('CUSTOM UID '.min($first, $last).':'.max($first, $last))
                ->setSequence(IMAP::ST_UID)
                ->leaveUnread()
                ->setFetchBody(true)
                ->fetchOrder($first <= $last ? 'asc' : 'desc')
                ->get();

            foreach ($messages as $message) {
                $mapped = $this->mapMessage($message);

                if (isset($requested[$mapped->uid])) {
                    yield $mapped;
                }
            }
        }
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

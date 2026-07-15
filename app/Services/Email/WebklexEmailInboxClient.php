<?php

namespace App\Services\Email;

use Carbon\CarbonInterface;
use Webklex\PHPIMAP\Attribute;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\Folder;
use Webklex\PHPIMAP\Message as ImapMessage;

class WebklexEmailInboxClient implements EmailInboxClient
{
    public function __construct(
        private readonly Client $client,
        private readonly Folder $folder,
    ) {
    }

    public function fetchSince(int $lastSeenUid): iterable
    {
        $startUid = max(1, $lastSeenUid + 1);

        $messages = $this->folder
            ->query()
            ->whereUid($startUid . ':*')
            ->leaveUnread()
            ->setFetchBody(true)
            ->fetchOrder('asc')
            ->get();

        foreach ($messages as $message) {
            yield $this->mapMessage($message);
        }
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

        if (!$address) {
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

<?php

namespace App\Services\Email;

use Carbon\CarbonInterface;

class InboundEmail
{
    /**
     * @param array<int, string> $to
     * @param array<int, string> $cc
     * @param array<int, string> $references
     * @param array<int, InboundEmailAttachment> $attachments
     */
    public function __construct(
        public readonly int $uid,
        public readonly ?string $messageId,
        public readonly string $fromEmail,
        public readonly ?string $fromName,
        public readonly ?string $subject,
        public readonly array $to,
        public readonly array $cc,
        public readonly ?string $inReplyTo,
        public readonly array $references,
        public readonly ?string $textBody,
        public readonly ?string $htmlBody,
        public readonly ?CarbonInterface $sentAt,
        public readonly array $attachments = [],
    ) {
    }
}

<?php

namespace App\Services\Email;

class InboundEmailAttachment
{
    public function __construct(
        public readonly string $filename,
        public readonly string $content,
        public readonly ?string $contentType = null,
    ) {
    }
}

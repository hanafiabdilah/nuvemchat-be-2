<?php

namespace App\Services\Email;

interface EmailInboxClient
{
    /**
     * @return iterable<InboundEmail>
     */
    public function fetchSince(int $lastSeenUid): iterable;

    public function disconnect(): void;
}

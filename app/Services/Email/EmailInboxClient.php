<?php

namespace App\Services\Email;

interface EmailInboxClient
{
    /**
     * Fetch at most $limit messages with a UID above $lastSeenUid, oldest
     * first. Bounding the batch keeps the first sync of a large mailbox from
     * running until it times out or exhausts memory.
     *
     * @return iterable<InboundEmail>
     */
    public function fetchSince(int $lastSeenUid, int $limit): iterable;

    /**
     * How many messages sit above $lastSeenUid. Drives the progress readout,
     * so it must not fetch bodies or attachments.
     */
    public function countSince(int $lastSeenUid): int;

    public function disconnect(): void;
}

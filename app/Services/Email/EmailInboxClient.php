<?php

namespace App\Services\Email;

use DateTimeInterface;

interface EmailInboxClient
{
    /**
     * UIDs strictly above $uid, ascending — the tail of newly arrived mail.
     * SEARCH-only: it spans the whole backlog, so it must never fetch headers
     * or bodies.
     *
     * @return array<int, int>
     */
    public function uidsAfter(int $uid): array;

    /**
     * UIDs of messages received on/after $since (the whole mailbox when null),
     * strictly below $beforeUid when given, ascending. Drives the newest-first
     * backfill and the progress readout. SEARCH-only, like uidsAfter().
     *
     * @return array<int, int>
     */
    public function uidsWithin(?DateTimeInterface $since, ?int $beforeUid): array;

    /**
     * Fetch exactly these UIDs — bodies and attachments included — yielding
     * them in the given order (pass a descending list for newest-first).
     * Bounding the list keeps one pass from running until it times out.
     *
     * With $maxMessageBytes set, messages whose RFC822.SIZE exceeds it are
     * never downloaded: an OversizedEmail marker is yielded in their place —
     * in order, so the caller's per-message cursor can step over them without
     * jumping past UIDs that have not been walked yet.
     *
     * @param  array<int, int>  $uids
     * @return iterable<InboundEmail|OversizedEmail>
     */
    public function fetch(array $uids, ?int $maxMessageBytes = null): iterable;

    /**
     * Set the \Seen flag on exactly these UIDs.
     *
     * The counterpart of the leaveUnread()/FT_PEEK the whole sync is built on:
     * downloading mail must never touch its state, so the mailbox stays exactly
     * as the account owner left it — which also means that without this, mail
     * an agent has read and answered here sits bold in Gmail forever. Only the
     * act of reading a thread in the panel moves the flag.
     *
     * @param  array<int, int>  $uids
     * @return int How many were flagged. Fewer than asked is normal: a UID may
     *             have been moved or deleted from the mailbox since we saw it.
     */
    public function markSeen(array $uids): int;

    public function disconnect(): void;
}

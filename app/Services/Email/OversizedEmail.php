<?php

namespace App\Services\Email;

/**
 * A message the client refused to download. Webklex buffers the entire RFC822
 * literal in memory while reading it (plus parsed copies), so one huge message
 * fatally OOMs the worker mid-FETCH — a fatal error, not an exception, which
 * no persist-level guard can catch. The marker is yielded in the message's
 * place so the synchronizer can advance its cursor past the UID.
 */
final class OversizedEmail
{
    public function __construct(
        public readonly int $uid,
        public readonly int $bytes,
    ) {}
}

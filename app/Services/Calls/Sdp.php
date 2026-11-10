<?php

namespace App\Services\Calls;

/**
 * An SDP as WebRTC parsers insist on receiving it: every line — the last one
 * included — ends in CRLF (RFC 8866 §5).
 *
 * Not pedantry. Chrome refuses an offer whose final line has no line break
 * ("Failed to parse SessionDescription"), and the first production call proved
 * how easily that break goes missing: Laravel's TrimStrings stripped it from
 * the webhook before the offer was stored, so every agent who pressed Answer
 * was told to check a microphone that was fine. Normalised on the way in and on
 * the way out, so neither a middleware nor a browser that emits bare LF can
 * break a call again.
 */
final class Sdp
{
    public static function normalize(?string $sdp): ?string
    {
        if ($sdp === null) {
            return null;
        }

        $lines = preg_split('/\r\n|\r|\n/', trim($sdp)) ?: [];
        $lines = array_values(array_filter($lines, fn (string $line) => $line !== ''));

        return $lines === [] ? null : implode("\r\n", $lines)."\r\n";
    }
}

<?php

namespace App\Support\Errors;

use Throwable;

/**
 * Did this request ever reach the other side?
 *
 * Asked by the callers that want to retry a send. Retrying is only safe when
 * the answer is a provable no: WhatsApp has no way to tell us "you already sent
 * that", so a second attempt at a request the channel *did* receive is a second
 * bubble in front of the customer — and in a marketing funnel that reads as
 * spam, which is the one failure that costs a phone number rather than a
 * message.
 *
 * So this only reports true for failures that happened *before* the request was
 * written to the socket: DNS, the TCP connect, the TLS handshake. Everything
 * else — a read timeout, an empty reply, a 5xx — is ambiguous by construction:
 * the channel may have accepted and delivered the message and then lost the
 * answer on the way back, and there is no way to tell that apart from a channel
 * that never got it. Those are left alone.
 *
 * ⚠️ Matched on cURL's own wording, because that is what survives: every send
 * handler catches its transport failure and rethrows its own Exception, and
 * `guard()` in MessageService then translates it into copy for the customer.
 * The original is kept as `previous` at each of those rethrows, so the chain is
 * walked here — and the text is checked at every link, because some handlers
 * inline `$th->getMessage()` into their own sentence instead.
 */
final class TransportFailure
{
    /**
     * Signatures of a failure that happened before the request was sent.
     *
     * ⚠️ "Connection timed out after" is cURL's connect-phase timeout and is
     * NOT the same string as its read timeout, which reads "Operation timed out
     * after N milliseconds with M bytes received". Matching loosely on "timed
     * out" would pull the read timeout in with it and hand back exactly the
     * ambiguous case this class exists to exclude.
     */
    private const CONNECT_PHASE = [
        'connection timed out after',
        'resolving timed out after',
        'failed to connect to',
        'connection refused',
        'could not resolve host',
        'could not resolve proxy',
        'ssl connect error',
        'no route to host',
        'network is unreachable',
    ];

    /**
     * True only when nothing can have been delivered, so a retry cannot
     * duplicate. Anything unrecognised answers false: the cost of being wrong
     * in that direction is one lost bubble, and the caller reports that rather
     * than hiding it.
     */
    public static function undelivered(?Throwable $th): bool
    {
        for ($e = $th; $e !== null; $e = $e->getPrevious()) {
            $message = mb_strtolower($e->getMessage());

            foreach (self::CONNECT_PHASE as $signature) {
                if (str_contains($message, $signature)) {
                    return true;
                }
            }
        }

        return false;
    }
}

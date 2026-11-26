<?php

namespace App\Enums\Auth;

use Illuminate\Http\Request;

/**
 * The kind of device a dashboard sign-in came from.
 *
 * An account may be signed in on one of each at the same time — a computer at
 * the desk and a phone in the pocket — but never on two of the same kind
 * (App\Services\Auth\SingleSession). Tablets count as phones: what the rule
 * keeps apart is "the desk" and "on the move", and a tablet is the second.
 *
 * Read from the request the sign-in arrived with, so it is what the browser
 * says it is. That is not a security boundary and does not need to be: the
 * rule is about how many places an account is used from, and a person set on
 * sharing a login can already share the password.
 */
enum SessionDevice: string
{
    case Computer = 'computer';
    case Phone = 'phone';

    public static function fromRequest(?Request $request): self
    {
        if ($request === null) {
            return self::Computer;
        }

        // Chromium's own answer, sent on every request. It also follows
        // "Request desktop site", which the user agent below cannot tell.
        $hint = trim((string) $request->header('Sec-CH-UA-Mobile'));

        if ($hint === '?1') {
            return self::Phone;
        }

        return self::fromUserAgent($request->userAgent());
    }

    /**
     * ⚠️ An iPad in its default mode announces itself as a Mac and is read as
     * a computer — nothing on the server can tell them apart.
     */
    public static function fromUserAgent(?string $userAgent): self
    {
        $ua = (string) $userAgent;

        // The Pingly app first: its WebView is always a phone or tablet.
        if (stripos($ua, 'PinglyApp/') !== false) {
            return self::Phone;
        }

        // "Mobi" is the token every mobile browser sends; Android and the iOS
        // names cover the tablets that leave it out.
        return preg_match('/Mobi|Android|iPhone|iPad|iPod/i', $ua) === 1
            ? self::Phone
            : self::Computer;
    }
}

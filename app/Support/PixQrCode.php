<?php

namespace App\Support;

use BaconQrCode\Renderer\GDLibRenderer;
use BaconQrCode\Writer;

/**
 * A Pix copy-and-paste code as a scannable PNG.
 *
 * Drawn here rather than taken from the gateway: OpenPix hosts an image,
 * Mercado Pago only returns base64, and a customer scanning from another phone
 * should get the same picture either way. The code is the whole of the charge —
 * the QR encodes exactly that string — so drawing it ourselves cannot drift from
 * what the gateway issued.
 */
final class PixQrCode
{
    /** Large enough to scan off a phone held up to another phone's screen. */
    private const SIZE = 480;

    /**
     * Whether a PNG can be drawn here at all. The renderer needs GD, and the
     * platform does not assume it (see AvatarStorage): without it the QR
     * bubble is skipped and the copy-and-paste code — which is the whole
     * charge — still goes out.
     */
    public static function available(): bool
    {
        return extension_loaded('gd') && function_exists('imagecreatetruecolor');
    }

    public static function png(string $code): string
    {
        return (new Writer(new GDLibRenderer(self::SIZE, 2)))->writeString($code);
    }
}

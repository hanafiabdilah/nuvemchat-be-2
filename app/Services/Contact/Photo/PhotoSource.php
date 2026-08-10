<?php

namespace App\Services\Contact\Photo;

/**
 * A downloadable profile picture located by a channel resolver.
 *
 * $extension is a hint only — the syncer falls back to sniffing the response
 * Content-Type when a channel doesn't expose one (Instagram/Messenger CDN
 * URLs, for instance, carry no usable suffix).
 */
class PhotoSource
{
    public function __construct(
        public readonly string $url,
        public readonly ?string $extension = null,
    ) {}
}

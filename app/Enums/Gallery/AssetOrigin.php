<?php

namespace App\Enums\Gallery;

/**
 * Where a gallery row came from, which decides the three things that differ
 * between rows: whether it is paid for, how long it lives, and where its bytes
 * sit.
 *
 * Only `gallery` is a file the workspace put in its library on purpose, and it
 * is the only one the storage quota counts. Every other origin is a file that
 * already existed somewhere else before it appeared here — a flow node's
 * upload, a campaign's media, a product photo, an attachment an agent sent —
 * and the row only **points** at it. Listing those is what makes the gallery
 * "every file I have used"; charging for them would put every legacy workspace
 * (whose plans grant 0 GB) over its limit on the day this shipped, for files
 * it never chose to keep here.
 *
 * ⚠️ `message` rows are the only temporary ones. Their bytes are message media,
 * which `media:purge` deletes on its retention schedule, and the row goes with
 * them. "Salvar na galeria" copies the file into a `gallery` row to keep it —
 * that is the only way anything here starts counting.
 *
 * Files customers send are never registered at all: they arrive unbidden, are
 * often private, and are exactly the volume retention exists to bound.
 */
enum AssetOrigin: string
{
    case Gallery = 'gallery';
    case Flow = 'flow';
    case Campaign = 'campaign';
    case Catalog = 'catalog';
    case Message = 'message';
    /** A published upload whose use could not be determined (backfilled rows). */
    case Upload = 'upload';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $o) => $o->value, self::cases());
    }

    /** The origins `POST /api/uploads` may be told it is uploading for. */
    public static function uploadPurposes(): array
    {
        return [self::Flow->value, self::Campaign->value, self::Catalog->value];
    }

    /** Only files put in the library on purpose are paid for. */
    public function countsTowardQuota(): bool
    {
        return $this === self::Gallery;
    }

    /**
     * Whether the file outlives a retention window. Anything that writes a
     * gallery URL somewhere it will be sent again for months (a flow node, a
     * campaign, a product) must only offer permanent rows.
     */
    public function isPermanent(): bool
    {
        return $this !== self::Message;
    }

    /** Whether the row points at bytes that belong to something else. */
    public function isLinked(): bool
    {
        return $this !== self::Gallery;
    }
}

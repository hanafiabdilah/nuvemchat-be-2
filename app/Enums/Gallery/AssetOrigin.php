<?php

namespace App\Enums\Gallery;

/**
 * Where a gallery row came from, which decides the two things that still
 * differ between rows: how long the file lives, and whose disk it sits on.
 *
 * ⚠️ Every origin counts against the storage quota (Nov 2026). It used to be
 * `gallery` alone, which made the meter measure "what you saved here on
 * purpose" while the bytes the workspace actually stores — a flow's banner, a
 * campaign's video, a PDF uploaded through the API for every customer — grew
 * for free. Now the meter is the workspace's storage, and the gallery is the
 * list of everything in it.
 *
 * Two things the quota still does not measure, both on purpose: files a
 * customer sends (they arrive unbidden and can never be refused, so counting
 * them would let a stranger fill the space an agent needs to reply), and
 * widget visitors' uploads (same reason). Neither is ever registered here.
 *
 * ⚠️ `message` and `ai` rows are temporary. A message file is message media,
 * which `media:purge` deletes on its retention schedule; an AI media file is
 * deleted by the same purge after `media.retention.ai_days` (30 by default).
 * The row goes with the file, so both free their space by themselves.
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
    /**
     * A file an AI made in a flow (the AI media node). Counted, never refused,
     * and deleted after `media.retention.ai_days`.
     */
    case Ai = 'ai';

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

    /** Every file listed in the gallery is stored by the workspace, so all count. */
    public function countsTowardQuota(): bool
    {
        return true;
    }

    /**
     * Whether the file outlives a retention window. Anything that writes a
     * gallery URL somewhere it will be sent again for months (a flow node, a
     * campaign, a product) must only offer permanent rows.
     */
    public function isPermanent(): bool
    {
        return $this !== self::Message && $this !== self::Ai;
    }

    /** @return list<string> the origins whose files a retention deletes */
    public static function temporaryValues(): array
    {
        return [self::Message->value, self::Ai->value];
    }

    /** Whether the row points at bytes that belong to something else. */
    public function isLinked(): bool
    {
        return $this !== self::Gallery;
    }

    /** Whether the bytes sit on the published disk (`uploads/…`). */
    public function isPublished(): bool
    {
        return $this->isLinked() && $this !== self::Message;
    }
}

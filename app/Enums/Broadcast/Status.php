<?php

namespace App\Enums\Broadcast;

/**
 * Where a campaign is in its life.
 *
 *   draft ─┬─▶ scheduled ─▶ running ⇄ paused ─▶ completed
 *          └─────────────▶ running ─▶ canceled
 *                                  └─▶ failed
 *
 * `failed` is reserved for the campaign as a whole giving up (the connection
 * died, the template vanished). A recipient that could not be reached is a
 * RecipientStatus concern and never stops the run.
 */
enum Status: string
{
    case Draft = 'draft';
    case Scheduled = 'scheduled';
    case Running = 'running';
    case Paused = 'paused';
    case Completed = 'completed';
    case Canceled = 'canceled';
    case Failed = 'failed';

    /** Nothing more will be sent; the campaign is history. */
    public function isFinished(): bool
    {
        return in_array($this, [self::Completed, self::Canceled, self::Failed], true);
    }

    /** The pump may claim another batch. */
    public function isActive(): bool
    {
        return $this === self::Running;
    }

    /** Still editable: nothing has gone out yet. */
    public function isEditable(): bool
    {
        return in_array($this, [self::Draft, self::Scheduled], true);
    }
}

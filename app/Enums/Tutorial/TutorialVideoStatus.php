<?php

namespace App\Enums\Tutorial;

/**
 * Where an uploaded tutorial is on its way to being playable.
 *
 * `processing` covers the minutes ffmpeg spends making the file stream well
 * (faststart, H.264, a sane size) and drawing its poster. Only `ready` videos
 * reach the dashboard; `failed` keeps the row so the admin can read why and
 * upload again instead of wondering where the video went.
 */
enum TutorialVideoStatus: string
{
    case Processing = 'processing';
    case Ready = 'ready';
    case Failed = 'failed';
}

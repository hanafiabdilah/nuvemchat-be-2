<?php

namespace App\Services\Message\Contracts;

use App\Models\Conversation;
use App\Models\Message;

/**
 * A send handler whose channel draws a sticker as a sticker — the cut-out
 * picture with no bubble around it, not a photo.
 *
 * A capability interface like SendsPixKey: only WhatsApp (both roads) and
 * Telegram have one we can send. Callers go through
 * MessageService::sendSticker(), which sends the same picture as a plain
 * image wherever this is missing — a flow built for WhatsApp should not fail
 * because it also runs on Instagram.
 *
 * `$data` carries `media_url` (or an uploaded `sticker` file) and, optionally,
 * `replied_message_id`. Never a caption: no channel puts one on a sticker.
 */
interface SendsStickers
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function handleSendSticker(Conversation $conversation, array $data): ?Message;
}

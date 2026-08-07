<?php

namespace App\Enums\Connection;

enum Channel: string
{
    case Instagram = 'instagram';
    case WhatsappOfficial = 'whatsapp_official';
    // Branded "API Way". The backing value stays `whatsapp_proxyhub` because it is
    // persisted in `connections.channel` — renaming it would orphan existing rows.
    case WhatsappApiway = 'whatsapp_proxyhub';
    case Telegram = 'telegram';
    case LiveChatWidget = 'live_chat_widget';
    case Email = 'email';
    case TikTok = 'tiktok';
    case Messenger = 'messenger';
}

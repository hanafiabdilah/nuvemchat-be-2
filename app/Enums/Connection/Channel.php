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
    case Discord = 'discord';

    /**
     * Whether an agent may open a conversation here, i.e. send the very first
     * message. False on channels where the platform only ever allows replying
     * inside a session the customer opened:
     *
     *  - WhatsApp Official: free-form content is limited to 24h after the
     *    customer's last message. Re-engaging goes through an approved
     *    template instead (see MessageTemplateController::send).
     *  - TikTok: same shape, 48h, and there is no template equivalent.
     *
     * Instagram and Messenger carry the same platform limitation but are left
     * alone here — their contacts already cannot be created manually, which
     * keeps them out of the flow in practice.
     */
    public function canStartConversation(): bool
    {
        return ! in_array($this, [self::WhatsappOfficial, self::TikTok], true);
    }
}

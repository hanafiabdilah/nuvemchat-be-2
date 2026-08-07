<?php

namespace App\Services\Contact;

use App\Enums\Connection\Channel;
use App\Services\Contact\Channels\DiscordChannel;
use App\Services\Contact\Channels\InstagramChannel;
use App\Services\Contact\Channels\LiveChatWidgetChannel;
use App\Services\Contact\Channels\MessengerChannel;
use App\Services\Contact\Channels\TelegramChannel;
use App\Services\Contact\Channels\TikTokChannel;
use App\Services\Contact\Channels\WhatsappOfficialChannel;
use App\Services\Contact\Channels\WhatsappApiwayChannel;

class ContactChannelFactory
{
    public static function make(Channel $channel): ContactChannelInterface
    {
        return match ($channel) {
            Channel::WhatsappApiway => new WhatsappApiwayChannel(),
            Channel::WhatsappOfficial => new WhatsappOfficialChannel(),
            Channel::Instagram => new InstagramChannel(),
            Channel::Messenger => new MessengerChannel(),
            Channel::Discord => new DiscordChannel(),
            Channel::Telegram => new TelegramChannel(),
            Channel::TikTok => new TikTokChannel(),
            Channel::LiveChatWidget => new LiveChatWidgetChannel(),
            Channel::Email => throw new \InvalidArgumentException('Email channel not supported for this operation yet'),
        };
    }
}

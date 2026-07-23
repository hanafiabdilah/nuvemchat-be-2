<?php

namespace App\Services\Connection;

use App\Enums\Connection\Channel;
use App\Services\Connection\Channels\InstagramChannel;
use App\Services\Connection\Channels\EmailChannel;
use App\Services\Connection\Channels\LiveChatWidgetChannel;
use App\Services\Connection\Channels\TelegramChannel;
use App\Services\Connection\Channels\WhatsappOfficialChannel;
use App\Services\Connection\Channels\WhatsappApiwayChannel;
use App\Services\Connection\Channels\WhatsappWApiChannel;
use App\Services\Connection\ChannelInterface;

class ChannelFactory
{
    public static function make(Channel $channel): ChannelInterface
    {
        return match($channel) {
            Channel::Instagram => new InstagramChannel(),
            Channel::Telegram => new TelegramChannel(),
            Channel::WhatsappOfficial => new WhatsappOfficialChannel(),
            Channel::WhatsappWApi => new WhatsappWApiChannel(),
            Channel::WhatsappApiway => new WhatsappApiwayChannel(),
            Channel::LiveChatWidget => new LiveChatWidgetChannel(),
            Channel::Email => new EmailChannel(),
            default => throw new \Exception("Channel not supported"),
        };
    }
}

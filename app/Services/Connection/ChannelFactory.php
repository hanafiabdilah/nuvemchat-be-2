<?php

namespace App\Services\Connection;

use App\Enums\Connection\Channel;
use App\Services\Connection\Channels\TelegramChannel;
use App\Services\Connection\Channels\WhatsappOfficialChannel;
use App\Services\Connection\Channels\WhatsappWApiChannel;
use App\Services\Connection\ChannelInterface;

class ChannelFactory
{
    public static function make(Channel $channel): ChannelInterface
    {
        return match($channel) {
            Channel::Telegram => new TelegramChannel(),
            Channel::WhatsappOfficial => new WhatsappOfficialChannel(),
            Channel::WhatsappWApi => new WhatsappWApiChannel(),
            default => throw new \Exception("Channel not supported"),
        };
    }
}

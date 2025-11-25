<?php

namespace App\Services\Connections;

use App\Enums\Connection\Channel;
use App\Services\Connections\Channels\TelegramChannel;
use App\Services\Connections\Channels\WhatsappOfficialChannel;
use App\Services\Connections\Channels\WhatsappWApiChannel;
use App\Services\Connections\ChannelInterface;

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

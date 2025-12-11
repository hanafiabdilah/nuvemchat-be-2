<?php

namespace App\Services\Message;

use App\Enums\Connection\Channel;
use App\Services\Message\Handlers\WhatsappOfficialHandler;
use App\Services\Message\Handlers\TelegramHandler;
use InvalidArgumentException;

class MessageFactory
{
    public static function make(Channel $channel): MessageHandlerInterface
    {
        return match($channel){
            Channel::WhatsappOfficial => new WhatsappOfficialHandler(),
            Channel::Telegram => new TelegramHandler(),
            default => throw new InvalidArgumentException("Unsupported channel: " . $channel->value),
        };
    }
}

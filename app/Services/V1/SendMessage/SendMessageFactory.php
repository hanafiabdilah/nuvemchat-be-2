<?php

namespace App\Services\V1\SendMessage;

use App\Enums\Connection\Channel;
use App\Services\V1\SendMessage\Handlers\TelegramHandler;
use App\Services\V1\SendMessage\Handlers\WhatsappOfficialHandler;
use App\Services\V1\SendMessage\SendMessageHandlerInterface;
use InvalidArgumentException;

class SendMessageFactory
{
    public static function make(Channel $channel): SendMessageHandlerInterface
    {
        return match($channel){
            Channel::WhatsappOfficial => new WhatsappOfficialHandler(),
            Channel::Telegram => new TelegramHandler(),
            default => throw new InvalidArgumentException("Unsupported channel: " . $channel->value),
        };
    }
}

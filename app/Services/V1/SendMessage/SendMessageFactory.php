<?php

namespace App\Services\V1\SendMessage;

use App\Enums\Connection\Channel;
use App\Services\V1\SendMessage\Handlers\InstagramHandler;
use App\Services\V1\SendMessage\Handlers\MessengerHandler;
use App\Services\V1\SendMessage\Handlers\TelegramHandler;
use App\Services\V1\SendMessage\Handlers\TikTokHandler;
use App\Services\V1\SendMessage\Handlers\WhatsappOfficialHandler;
use App\Services\V1\SendMessage\Handlers\WhatsappApiwayHandler;
use App\Services\V1\SendMessage\SendMessageHandlerInterface;
use InvalidArgumentException;

class SendMessageFactory
{
    public static function make(Channel $channel): SendMessageHandlerInterface
    {
        return match($channel){
            Channel::WhatsappOfficial => new WhatsappOfficialHandler(),
            Channel::WhatsappApiway => new WhatsappApiwayHandler(),
            Channel::Instagram => new InstagramHandler(),
            Channel::Messenger => new MessengerHandler(),
            Channel::Telegram => new TelegramHandler(),
            Channel::TikTok => new TikTokHandler(),
            Channel::Email => throw new InvalidArgumentException("Email channel not supported for this operation yet"),
            default => throw new InvalidArgumentException("Unsupported channel: " . $channel->value),
        };
    }
}

<?php

namespace App\Services\Webhook\Factories;

use App\Enums\Connection\Channel;
use App\Services\Webhook\Contracts\ChatHandlerInterface;
use App\Services\Webhook\Handlers\Chat\TelegramHandler;
use App\Services\Webhook\Handlers\Chat\WhatsappOfficialHandler;

class ChatFactory
{
    public static function make(Channel $channel): ChatHandlerInterface
    {
        return match ($channel) {
            Channel::Telegram => new TelegramHandler(),
            Channel::WhatsappOfficial => new WhatsappOfficialHandler(),
            default => throw new \InvalidArgumentException('Unsupported channel type for chat handler.'),
        };
    }
}

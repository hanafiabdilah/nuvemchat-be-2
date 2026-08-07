<?php

namespace App\Services\Message;

use App\Enums\Connection\Channel;
use App\Services\Message\Handlers\DiscordHandler;
use App\Services\Message\Handlers\EmailHandler;
use App\Services\Message\Handlers\InstagramHandler;
use App\Services\Message\Handlers\LiveChatWidgetHandler;
use App\Services\Message\Handlers\MessengerHandler;
use App\Services\Message\Handlers\WhatsappOfficialHandler;
use App\Services\Message\Handlers\TelegramHandler;
use App\Services\Message\Handlers\TikTokHandler;
use App\Services\Message\Handlers\WhatsappApiwayHandler;
use InvalidArgumentException;

class MessageFactory
{
    public static function make(Channel $channel): MessageHandlerInterface
    {
        return match($channel){
            Channel::Instagram => new InstagramHandler(),
            Channel::Messenger => new MessengerHandler(),
            Channel::Discord => new DiscordHandler(),
            Channel::WhatsappOfficial => new WhatsappOfficialHandler(),
            Channel::Telegram => new TelegramHandler(),
            Channel::TikTok => new TikTokHandler(),
            Channel::WhatsappApiway => new WhatsappApiwayHandler(),
            Channel::LiveChatWidget => new LiveChatWidgetHandler(),
            Channel::Email => new EmailHandler(),
            default => throw new InvalidArgumentException("Unsupported channel: " . $channel->value),
        };
    }
}

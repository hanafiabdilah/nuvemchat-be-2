<?php

namespace App\Services\Contact;

use App\Enums\Connection\Channel;
use App\Services\Contact\Channels\InstagramChannel;
use App\Services\Contact\Channels\TelegramChannel;
use App\Services\Contact\Channels\WhatsappOfficialChannel;
use App\Services\Contact\Channels\WhatsappWApiChannel;

class ContactChannelFactory
{
    public static function make(Channel $channel): ContactChannelInterface
    {
        return match ($channel) {
            Channel::WhatsappWApi => new WhatsappWApiChannel(),
            Channel::WhatsappOfficial => new WhatsappOfficialChannel(),
            Channel::Instagram => new InstagramChannel(),
            Channel::Telegram => new TelegramChannel(),
        };
    }
}

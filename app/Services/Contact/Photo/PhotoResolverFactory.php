<?php

namespace App\Services\Contact\Photo;

use App\Enums\Connection\Channel;
use App\Services\Contact\Photo\Resolvers\DiscordPhotoResolver;
use App\Services\Contact\Photo\Resolvers\InstagramPhotoResolver;
use App\Services\Contact\Photo\Resolvers\MessengerPhotoResolver;
use App\Services\Contact\Photo\Resolvers\TelegramPhotoResolver;
use App\Services\Contact\Photo\Resolvers\WhatsappApiwayPhotoResolver;

/**
 * Channels missing here simply have no way to read a picture: WhatsApp Cloud
 * API exposes no per-contact photo endpoint, TikTok Business Messaging does
 * not either, and the live chat widget has no profile to read.
 */
class PhotoResolverFactory
{
    public static function for(Channel $channel): ?PhotoResolver
    {
        return match ($channel) {
            Channel::Telegram => new TelegramPhotoResolver(),
            Channel::Discord => new DiscordPhotoResolver(),
            Channel::Instagram => new InstagramPhotoResolver(),
            Channel::Messenger => new MessengerPhotoResolver(),
            Channel::WhatsappApiway => new WhatsappApiwayPhotoResolver(),
            default => null,
        };
    }

    public static function supports(Channel $channel): bool
    {
        return self::for($channel) !== null;
    }
}

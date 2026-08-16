<?php

namespace App\Services\Contact\Profile;

use App\Enums\Connection\Channel;
use App\Services\Contact\Profile\Resolvers\InstagramProfileResolver;

/**
 * Only channels that hand out an opaque id *and* offer a way to read the
 * identity behind it belong here. WhatsApp and Telegram carry the name in the
 * webhook itself, so their contacts are already named at ingest; Messenger has
 * the same shape as Instagram but still resolves inline in its own handler.
 */
class ProfileResolverFactory
{
    public static function for(Channel $channel): ?ProfileResolver
    {
        return match ($channel) {
            Channel::Instagram => new InstagramProfileResolver(),
            default => null,
        };
    }

    public static function supports(Channel $channel): bool
    {
        return self::for($channel) !== null;
    }
}

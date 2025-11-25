<?php

namespace App\Enums\Connection;

enum Channel: string
{
    case Whatsapp = 'whatsapp';
    case Telegram = 'telegram';
    case Messenger = 'messenger';
    case Instagram = 'instagram';
    case Twitter = 'twitter';
}

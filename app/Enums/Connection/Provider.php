<?php

namespace App\Enums\Connection;

enum Provider: string
{
    case BotAPI = 'bot_api';
    case WApi = 'w_api';
    case Official = 'official';
}

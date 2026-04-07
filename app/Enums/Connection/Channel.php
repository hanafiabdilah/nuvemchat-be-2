<?php

namespace App\Enums\Connection;

enum Channel: string
{
    case Instagram = 'instagram';
    case WhatsappOfficial = 'whatsapp_official';
    case WhatsappWApi = 'whatsapp_w_api';
    case Telegram = 'telegram';
}

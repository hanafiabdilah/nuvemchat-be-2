<?php

namespace App\Enums\Connection;

enum Channel: string
{
    case WhatsappOfficial = 'whatsapp_official';
    case WhatsappWApi = 'whatsapp_w_api';
    case Telegram = 'telegram';
}

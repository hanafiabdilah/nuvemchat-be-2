<?php

namespace App\Enums\Message;

enum SenderType: string
{
    case Incoming = 'incoming';
    case Outgoing = 'outgoing';
}

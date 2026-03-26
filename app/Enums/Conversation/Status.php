<?php

namespace App\Enums\Conversation;

enum Status: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Resolved = 'resolved';
}

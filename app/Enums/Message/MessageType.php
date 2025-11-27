<?php

namespace App\Enums\Message;

enum MessageType: string
{
    case Text = 'text';
    case Image = 'image';
    case Video = 'video';
    case Audio = 'audio';
    case Document = 'document';
    case Location = 'location';
    case Contact = 'contact';
    case Unsupported = 'unsupported';
}

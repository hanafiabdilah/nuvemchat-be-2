<?php

namespace App\Enums\Message;

enum MessageType: string
{
    case Text = 'text';
    case Image = 'image';
    case Video = 'video';
    case Audio = 'audio';
    case Document = 'document';
    case Sticker = 'sticker';
    case Location = 'location';
    case Template = 'template';
    case Interactive = 'interactive';
    case Unsupported = 'unsupported';

    /**
     * A note the platform itself wrote into the thread — never sent to the
     * channel and never authored by a person. Used to record something that
     * happened to the conversation (a reply window expiring and closing it,
     * for example), where a chat bubble would misattribute the words to an
     * agent or the customer.
     */
    case Info = 'info';
}

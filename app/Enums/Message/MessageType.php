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

    /**
     * A contact card (vCard). Not media: nothing is downloaded, the card's
     * text arrives inside the webhook and is served from `meta.contacts`.
     */
    case Contact = 'contact';
    case Template = 'template';
    case Interactive = 'interactive';

    /**
     * A post or reel someone shared from Instagram into the DM. Not media:
     * the bytes belong to whoever posted them, so nothing is mirrored and the
     * bubble is a link back to the post instead — served from
     * `meta.instagram_share`. See InstagramHandler::shareData().
     */
    case InstagramShare = 'instagram_share';

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

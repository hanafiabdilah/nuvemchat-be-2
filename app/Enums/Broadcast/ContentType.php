<?php

namespace App\Enums\Broadcast;

/**
 * What a campaign sends. The payload column's shape follows from this:
 *
 *   template → {template_name, language, components}   (Meta components, verbatim)
 *   text     → {body}
 *   media    → {media_type, url, caption}
 *   email    → {subject, body}
 *
 * Anything but `template` is free-form as far as the platform is concerned, so
 * it is only allowed where the session window is open — see MessagingWindow.
 */
enum ContentType: string
{
    case Template = 'template';
    case Text = 'text';
    case Media = 'media';
    case Email = 'email';

    /** Whether the platform treats this as session content rather than a template. */
    public function isFreeForm(): bool
    {
        return $this !== self::Template;
    }
}

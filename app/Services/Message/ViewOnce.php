<?php

namespace App\Services\Message;

use App\Models\Message;

/**
 * "View once" media: a picture or a video the recipient can open a single time.
 *
 * Only WhatsApp over API Way can send one (Channel::supportsViewOnce) — the
 * Cloud API has no such option and no other channel has the concept. Asking for
 * it on a channel that cannot do it sends the media normally rather than
 * failing: the request is about how the file is shown, not whether it goes.
 *
 * ⚠️ The dashboard is not the recipient. A view-once file sent from here, or
 * received here, stays visible to the team in the thread — the single view is
 * a property of the customer's phone. The message carries a flag
 * (`meta.view_once`) so the bubble can say so.
 */
final class ViewOnce
{
    /** Wrappers WhatsApp puts around a view-once message. */
    private const WRAPPERS = ['viewOnceMessage', 'viewOnceMessageV2', 'viewOnceMessageV2Extension'];

    /** Flags whatsmeow sets on the event once it has unwrapped one. */
    private const EVENT_FLAGS = ['IsViewOnce', 'IsViewOnceV2', 'IsViewOnceV2Extension'];

    /** @param  array<string, mixed>  $data  a send request */
    public static function requested(array $data): bool
    {
        return filter_var($data['view_once'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    /** Record that this message went out as view once. */
    public static function mark(Message $message): void
    {
        $message->update(['meta' => array_merge((array) $message->meta, ['view_once' => true])]);
    }

    /**
     * Whether a stored message is view once — one we sent that way, or one
     * WhatsApp delivered that way.
     *
     * @param  array<string, mixed>|null  $meta
     */
    public static function is(?array $meta): bool
    {
        if (! is_array($meta)) {
            return false;
        }

        if (($meta['view_once'] ?? false) === true) {
            return true;
        }

        foreach (self::EVENT_FLAGS as $flag) {
            if (($meta[$flag] ?? false) === true) {
                return true;
            }
        }

        return false;
    }

    /**
     * Lift the real message out of a view-once wrapper, flagging the event.
     *
     * whatsmeow normally does this itself; a core that forwards the raw node
     * would otherwise deliver a picture this application reads as "unsupported".
     *
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    public static function unwrap(array $event): array
    {
        $message = $event['Message'] ?? null;

        if (! is_array($message)) {
            return $event;
        }

        foreach (self::WRAPPERS as $wrapper) {
            $inner = $message[$wrapper]['message'] ?? null;

            if (is_array($inner) && $inner !== []) {
                $event['Message'] = $inner + array_diff_key($message, array_flip(self::WRAPPERS));
                $event['IsViewOnce'] = true;

                return $event;
            }
        }

        return $event;
    }
}

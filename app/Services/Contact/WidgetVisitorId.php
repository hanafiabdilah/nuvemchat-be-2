<?php

namespace App\Services\Contact;

use App\Models\Connection;
use Illuminate\Support\Str;

/**
 * The one channel whose contact id is chosen by the person on the other side.
 *
 * Every other channel's `external_id` comes from the platform it belongs to —
 * a phone number Meta reported, a Telegram chat id, an Instagram scoped id.
 * The widget's comes from `visitor_id` in the request body, which is whatever
 * the browser sends, and `Contact::createFromExternalData()` looks a contact up
 * by `(external_id, tenant_id)` alone.
 *
 * So an anonymous caller who knew a workspace's widget `app_id` — a value
 * published in the HTML of the customer's own site — could open a session with
 * `visitor_id` set to one of that workspace's WhatsApp numbers, land on the
 * real customer's contact record, and rename it. The forged conversation then
 * appeared in the agent's inbox under that customer's identity, carrying their
 * photo and their tags.
 *
 * Prefixing with the connection id puts widget visitors in an id space of their
 * own. A phone number can no longer be spelled; neither can another
 * connection's visitor, since the prefix is not the caller's to choose.
 */
final class WidgetVisitorId
{
    /**
     * Room left for the prefix inside `contacts.external_id` (VARCHAR 255),
     * with plenty to spare — a visitor id is a UUID in practice.
     */
    private const MAX_RAW_LENGTH = 200;

    public static function scoped(Connection $connection, string $visitorId): string
    {
        return self::prefix($connection).Str::limit(trim($visitorId), self::MAX_RAW_LENGTH, '');
    }

    /** What every widget contact id for this connection starts with. */
    public static function prefix(Connection $connection): string
    {
        return 'w'.$connection->id.':';
    }

    /** Whether this id has already been through scoped(). */
    public static function isScoped(Connection $connection, string $externalId): bool
    {
        return str_starts_with($externalId, self::prefix($connection));
    }
}

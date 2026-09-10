<?php

namespace App\Services\Flow;

use App\Models\Contact;
use App\Services\Contact\ContactIdentity;
use App\Services\Integrations\Pixels\PixelEvent;
use App\Services\Integrations\Pixels\PixelEvents;
use Illuminate\Support\Str;

/**
 * The Pixel node: report a conversion and move straight on.
 *
 * Tracking never holds a customer up. The node queues one event per selected
 * integration and follows its single edge in the same breath; whether the ad
 * account accepted it is recorded on the integration, not in the conversation.
 */
final class PixelNodes
{
    /** Parameter names both providers accept — and the two we set ourselves. */
    private const PARAMETER_KEY_PATTERN = '/^[A-Za-z][A-Za-z0-9_]{0,39}$/';

    private const RESERVED_PARAMETERS = ['value', 'currency'];

    /**
     * @param  array<string, mixed>  $data
     * @return list<int>
     */
    public static function integrationIds(array $data): array
    {
        $ids = array_map('intval', (array) ($data['integration_ids'] ?? []));

        return array_values(array_unique(array_filter($ids, fn (int $id) => $id > 0)));
    }

    /** @param  array<string, mixed>  $data */
    public static function event(array $data): ?string
    {
        $event = $data['event'] ?? null;

        return in_array($event, PixelEvents::EVENTS, true) ? $event : null;
    }

    /**
     * Whether there is anything to send. A node dropped on the canvas and
     * auto-saved before an account was picked is skipped, not failed.
     *
     * @param  array<string, mixed>  $data
     */
    public static function isConfigured(array $data): bool
    {
        $event = self::event($data);

        if ($event === null || self::integrationIds($data) === []) {
            return false;
        }

        return $event !== 'custom' || PixelEvents::isValidCustomName($data['custom_event_name'] ?? null);
    }

    /**
     * The event, with every {{variable}} resolved now.
     *
     * @param  array<string, mixed>  $data
     * @param  callable(string): string  $interpolate
     */
    public static function buildEvent(array $data, callable $interpolate, ?Contact $contact, string $eventId): PixelEvent
    {
        $rawValue = trim($interpolate((string) ($data['value'] ?? '')));
        $cents = $rawValue !== '' ? PaymentNodes::parseAmount($rawValue) : null;

        $parameters = [];
        foreach ((array) ($data['parameters'] ?? []) as $row) {
            $key = trim((string) ($row['key'] ?? ''));

            if (preg_match(self::PARAMETER_KEY_PATTERN, $key) !== 1 || in_array(strtolower($key), self::RESERVED_PARAMETERS, true)) {
                continue;
            }

            $parameters[$key] = Str::limit($interpolate((string) ($row['value'] ?? '')), 100, '');
        }

        $currency = strtoupper(trim((string) ($data['currency'] ?? 'BRL')));

        return new PixelEvent(
            event: (string) self::event($data),
            customName: ($data['custom_event_name'] ?? null) ?: null,
            value: $cents !== null ? $cents / 100 : null,
            currency: strlen($currency) === 3 ? $currency : 'BRL',
            parameters: $parameters,
            eventId: $eventId,
            eventTime: time(),
            identity: ContactIdentity::for($contact),
        );
    }
}

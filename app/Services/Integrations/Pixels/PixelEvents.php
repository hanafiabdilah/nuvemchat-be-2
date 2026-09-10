<?php

namespace App\Services\Integrations\Pixels;

/**
 * The events a pixel node can report, and what each provider calls them.
 *
 * One vocabulary in the builder, translated per destination here: the author
 * picks "Lead" once and a node wired to both a Meta pixel and a GA4 property
 * sends `Lead` to one and `generate_lead` to the other. Mirrored on the
 * frontend in `lib/pixelEvents.ts`.
 *
 * Where a provider has no standard name for an event (GA4 has no "contact"),
 * the canonical key is sent as a custom event — which both accept.
 */
final class PixelEvents
{
    public const EVENTS = [
        'lead',
        'purchase',
        'contact',
        'complete_registration',
        'initiate_checkout',
        'schedule',
        'add_to_cart',
        'view_content',
        'subscribe',
        'custom',
    ];

    /** Letters, digits and underscore, starting with a letter — the rule both providers share. */
    public const CUSTOM_NAME_PATTERN = '/^[A-Za-z][A-Za-z0-9_]{0,39}$/';

    private const META = [
        'lead' => 'Lead',
        'purchase' => 'Purchase',
        'contact' => 'Contact',
        'complete_registration' => 'CompleteRegistration',
        'initiate_checkout' => 'InitiateCheckout',
        'schedule' => 'Schedule',
        'add_to_cart' => 'AddToCart',
        'view_content' => 'ViewContent',
        'subscribe' => 'Subscribe',
    ];

    private const GOOGLE = [
        'lead' => 'generate_lead',
        'purchase' => 'purchase',
        'contact' => 'contact',
        'complete_registration' => 'sign_up',
        'initiate_checkout' => 'begin_checkout',
        'schedule' => 'schedule',
        'add_to_cart' => 'add_to_cart',
        'view_content' => 'view_item',
        'subscribe' => 'subscribe',
    ];

    public static function metaName(PixelEvent $event): string
    {
        return self::META[$event->event] ?? self::customName($event);
    }

    public static function googleName(PixelEvent $event): string
    {
        return self::GOOGLE[$event->event] ?? self::customName($event);
    }

    public static function isValidCustomName(?string $name): bool
    {
        return is_string($name) && preg_match(self::CUSTOM_NAME_PATTERN, $name) === 1;
    }

    private static function customName(PixelEvent $event): string
    {
        return self::isValidCustomName($event->customName) ? (string) $event->customName : 'custom_event';
    }
}

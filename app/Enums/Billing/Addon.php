<?php

namespace App\Enums\Billing;

/**
 * Capacity a subscription can buy on top of what its plan includes, one unit
 * at a time: one more agent seat, one more WhatsApp number, one more gigabyte
 * of gallery storage.
 *
 * Each add-on raises exactly one quota — the subscription's effective limit is
 * the plan's number plus the units bought (Subscription::entitlements()). A
 * quota the plan leaves unlimited has nothing to add to, so its add-on is not
 * offered on that plan.
 *
 * ⚠️ The price is the **plan's**, not the platform's: a bigger plan sells its
 * extras cheaper (the landing page's ladder), so it lives on each market price
 * row beside the plan's own amount (`market_prices.addon_cents`, per cycle),
 * typed by hand in the Back Office like the plan's price.
 *
 * The stored values are the keys of `subscriptions.addons` and of
 * `market_prices.addon_cents`; renaming one orphans every unit already sold
 * under it.
 *
 * ⚠️ Gallery storage is the one whose quota reads **absent as zero**, not as
 * unlimited (Quota::GalleryStorageGb): a plan that does not mention it gives
 * no space, so there is always something to add to — see included().
 * Storage used to be rented from the prepaid balance at one platform-wide
 * price per GB; since Nov 2026 it is sold here, per plan, like the others.
 */
enum Addon: string
{
    case Agent = 'agents';
    case WhatsappConnection = 'whatsapp_connections';
    /** One unit = one gigabyte of gallery storage. */
    case GalleryStorage = 'gallery_storage_gb';

    /** The most units of one add-on a subscription may hold. */
    public const MAX_UNITS = 50;

    public function quota(): Quota
    {
        return match ($this) {
            self::Agent => Quota::MaxAgents,
            self::WhatsappConnection => Quota::MaxWhatsappConnections,
            self::GalleryStorage => Quota::GalleryStorageGb,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Agent => 'Extra agent',
            self::WhatsappConnection => 'Extra WhatsApp connection',
            self::GalleryStorage => 'Extra gallery storage (1 GB)',
        };
    }

    /**
     * What these quotas include of what this add-on raises, or null when they
     * do not limit it (nothing to add to). Gallery storage absent is zero.
     */
    public function included(array $quotas): ?int
    {
        $value = $quotas[$this->quota()->value] ?? null;

        if ($value === null) {
            return $this === self::GalleryStorage ? 0 : null;
        }

        return (int) $value;
    }

    /** Whether a plan with these quotas limits what this add-on raises. */
    public function limitedBy(array $quotas): bool
    {
        return $this->included($quotas) !== null;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $addon) => $addon->value, self::cases());
    }

    /**
     * Whole, positive units by known key — anything else dropped.
     *
     * @return array<string, int>
     */
    public static function clean(?array $raw): array
    {
        $units = [];

        foreach (self::cases() as $addon) {
            $quantity = (int) ($raw[$addon->value] ?? 0);

            if ($quantity > 0) {
                $units[$addon->value] = min($quantity, self::MAX_UNITS);
            }
        }

        return $units;
    }
}

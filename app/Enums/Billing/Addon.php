<?php

namespace App\Enums\Billing;

/**
 * Capacity a subscription can buy on top of what its plan includes, one unit
 * at a time: one more agent seat, one more WhatsApp number.
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
 */
enum Addon: string
{
    case Agent = 'agents';
    case WhatsappConnection = 'whatsapp_connections';

    /** The most units of one add-on a subscription may hold. */
    public const MAX_UNITS = 50;

    public function quota(): Quota
    {
        return match ($this) {
            self::Agent => Quota::MaxAgents,
            self::WhatsappConnection => Quota::MaxWhatsappConnections,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Agent => 'Extra agent',
            self::WhatsappConnection => 'Extra WhatsApp connection',
        };
    }

    /** Whether a plan with these quotas limits what this add-on raises. */
    public function limitedBy(array $quotas): bool
    {
        return isset($quotas[$this->quota()->value]);
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

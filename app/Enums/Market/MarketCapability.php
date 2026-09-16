<?php

namespace App\Enums\Market;

use App\Enums\Integration\IntegrationProvider;

/**
 * What the platform may sell, and which outside accounts a workspace may
 * connect, in one country.
 *
 * Plans already carry a country dimension — a plan with no price in a market is
 * not sold there (HasMarketPrices) — so plan features need nothing here. What
 * has no country dimension at all is everything bought *outside* a plan (a
 * virtual number, an API Way instance, a gigabyte of storage: a permission and
 * a balance, nothing else) and every integration a workspace connects. Both are
 * supplied by somebody, and some of those suppliers exist in one country only:
 * API Way sells Brazilian numbers for Brazilian apps, Spedy issues notas fiscais
 * to Brazilian prefeituras, and a Pix key means nothing in Jakarta.
 *
 * ⚠️ **A capability is a gate, never a grant.** It can only take away what a
 * plan or a permission already allows. Read the other way, a country row would
 * be able to hand out a product to a workspace that never bought it.
 *
 * Adding an integration provider needs no case here: providers get one key each,
 * derived from IntegrationProvider, so the vocabulary cannot fall behind the
 * catalog the way a second hand-written list would.
 */
enum MarketCapability: string
{
    /** Renting an SMS/OTP number. */
    case VirtualNumbers = 'virtual_numbers';

    /** Buying WhatsApp instances through the ProxyBR partner API. */
    case ApiwayInstances = 'apiway_instances';

    /** Renting extra media-library storage by the gigabyte. */
    case GalleryStorage = 'gallery_storage';

    public const INTEGRATION_PREFIX = 'integration:';

    public const GROUP_PRODUCTS = 'products';

    public const GROUP_INTEGRATIONS = 'integrations';

    /**
     * Providers that only work in one country.
     *
     * A fact about the supplier, not about the buyer: Pix and boleto are
     * Brazilian rails and a nota fiscal is issued to a Brazilian prefeitura, so
     * these are useless anywhere else no matter who is asking. A provider not
     * listed is global (Stripe, Meta Pixel, GA4) — and a Brazil-only provider
     * added later belongs in this list, or it will be offered everywhere until
     * an admin notices and unticks it.
     */
    private const INTEGRATION_SUPPLIERS = [
        'openpix' => 'BR',
        'mercadopago' => 'BR',
        'asaas' => 'BR',
        'spedy' => 'BR',
    ];

    public static function integrationKey(IntegrationProvider $provider): string
    {
        return self::INTEGRATION_PREFIX.$provider->value;
    }

    public static function providerFromKey(string $key): ?IntegrationProvider
    {
        if (! str_starts_with($key, self::INTEGRATION_PREFIX)) {
            return null;
        }

        return IntegrationProvider::tryFrom(substr($key, strlen(self::INTEGRATION_PREFIX)));
    }

    /**
     * Every key in the vocabulary — the only list a market's stored map may use.
     *
     * @return list<string>
     */
    public static function keys(): array
    {
        return [
            ...array_map(fn (self $case) => $case->value, self::cases()),
            ...array_map(fn (IntegrationProvider $p) => self::integrationKey($p), IntegrationProvider::cases()),
        ];
    }

    public static function isKey(string $key): bool
    {
        return in_array($key, self::keys(), true);
    }

    /**
     * The country this key's supplier sells from, or null when it is global.
     *
     * This is what a market's unset keys fall back to, so that opening a country
     * does not start by offering it things that cannot exist there. It stays a
     * default: every key is a checkbox an admin can flip either way.
     */
    public static function supplierCountry(string $key): ?string
    {
        $provider = self::providerFromKey($key);

        if ($provider !== null) {
            return self::INTEGRATION_SUPPLIERS[$provider->value] ?? null;
        }

        return match (self::tryFrom($key)) {
            // API Way's stock is Brazilian DDD numbers for Brazilian apps, and
            // ProxyBR provisions instances on Brazilian infrastructure.
            self::VirtualNumbers, self::ApiwayInstances => 'BR',
            // Our own disk. It has no country.
            default => null,
        };
    }

    public static function groupOf(string $key): string
    {
        return self::providerFromKey($key) !== null
            ? self::GROUP_INTEGRATIONS
            : self::GROUP_PRODUCTS;
    }

    public function label(): string
    {
        return match ($this) {
            self::VirtualNumbers => 'Virtual numbers',
            self::ApiwayInstances => 'API Way instances',
            self::GalleryStorage => 'Extra media storage',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::VirtualNumbers => 'Renting an SMS number to receive codes. The stock is API Way\'s, and it is Brazilian.',
            self::ApiwayInstances => 'Buying WhatsApp instances from ProxyBR. Owning one is also what grants the WhatsApp API feature.',
            self::GalleryStorage => 'Renting gigabytes for the media library, monthly, from the balance.',
        };
    }
}

<?php

namespace App\Enums\Integration;

use App\Support\Errors\UpstreamProvider;

/**
 * Every external app a workspace can connect, and what connecting one takes.
 *
 * The field list lives here — not in the dashboard — because it is what the
 * API validates against. The Integrations page renders its forms from the same
 * definitions (served by GET /integrations as `catalog`), so a provider added
 * here arrives with a working form, and a field renamed here cannot leave a
 * form that posts the old name. The dashboard only owns the logo.
 *
 * `secret` decides where a value is stored: secrets go into the encrypted
 * `credentials` column and are never sent back (only a masked preview), the
 * rest into `settings`, which the builder may read.
 */
enum IntegrationProvider: string
{
    case OpenPix = 'openpix';
    case MercadoPago = 'mercadopago';
    case MetaPixel = 'meta_pixel';
    case GoogleAnalytics = 'google_analytics';

    public function category(): IntegrationCategory
    {
        return match ($this) {
            self::OpenPix, self::MercadoPago => IntegrationCategory::Payment,
            self::MetaPixel, self::GoogleAnalytics => IntegrationCategory::Pixel,
        };
    }

    /** The brand name. Not translated — it is what the customer knows the app as. */
    public function label(): string
    {
        return match ($this) {
            self::OpenPix => 'OpenPix',
            self::MercadoPago => 'Mercado Pago',
            self::MetaPixel => 'Meta Pixel',
            self::GoogleAnalytics => 'Google Analytics 4',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::OpenPix => 'Pix charges with QR code and copy-and-paste code, confirmed the moment they are paid.',
            self::MercadoPago => 'Pix charges or a checkout link that also takes cards and boleto.',
            self::MetaPixel => 'Sends conversions to your pixel through the Conversions API, for Facebook and Instagram ads.',
            self::GoogleAnalytics => 'Sends events to a GA4 property through the Measurement Protocol, for reports and Google Ads.',
        };
    }

    public function docsUrl(): string
    {
        return match ($this) {
            self::OpenPix => 'https://developers.openpix.com.br/docs/apis/api-getting-started',
            self::MercadoPago => 'https://www.mercadopago.com.br/developers/pt/docs/your-integrations/credentials',
            self::MetaPixel => 'https://developers.facebook.com/docs/marketing-api/conversions-api/get-started',
            self::GoogleAnalytics => 'https://developers.google.com/analytics/devguides/collection/protocols/ga4',
        };
    }

    /**
     * Which charge shapes a payment provider issues.
     *
     * `pix` is a Pix charge (QR image + copy-and-paste code); `checkout` is a
     * hosted page that also takes cards and boleto. OpenPix only does Pix.
     *
     * @return list<string>
     */
    public function paymentMethods(): array
    {
        return match ($this) {
            self::OpenPix => ['pix'],
            self::MercadoPago => ['pix', 'checkout'],
            default => [],
        };
    }

    /**
     * What the connect form asks for.
     *
     * @return list<array{key: string, label: string, type: string, required: bool, secret: bool, placeholder?: string, help?: string, rules: list<mixed>}>
     */
    public function fields(): array
    {
        return match ($this) {
            self::OpenPix => [
                [
                    'key' => 'app_id',
                    'label' => 'AppID',
                    'type' => 'secret',
                    'required' => true,
                    'secret' => true,
                    'placeholder' => 'Q2xpZW50X0lkXz...',
                    'help' => 'In OpenPix, open API/Plugins, create a new API key and copy the AppID it shows.',
                    'rules' => ['string', 'min:16', 'max:2000'],
                ],
                [
                    'key' => 'sandbox',
                    'label' => 'Test environment (sandbox)',
                    'type' => 'boolean',
                    'required' => false,
                    'secret' => false,
                    'help' => 'Charges are created in the OpenPix sandbox and never move real money.',
                    'rules' => ['boolean'],
                ],
            ],
            self::MercadoPago => [
                [
                    'key' => 'access_token',
                    'label' => 'Access token',
                    'type' => 'secret',
                    'required' => true,
                    'secret' => true,
                    'placeholder' => 'APP_USR-...',
                    'help' => 'Mercado Pago Developers → Your integrations → your application → Production credentials. A TEST- token creates test payments.',
                    'rules' => ['string', 'min:20', 'max:500', 'regex:/^(APP_USR|TEST)-/'],
                ],
                [
                    'key' => 'payer_email',
                    'label' => 'Default payer e-mail',
                    'type' => 'email',
                    'required' => false,
                    'secret' => false,
                    'placeholder' => 'pagamentos@suaempresa.com.br',
                    'help' => 'Mercado Pago asks for an e-mail on every Pix. Used when the contact has none.',
                    'rules' => ['email', 'max:255'],
                ],
            ],
            self::MetaPixel => [
                [
                    'key' => 'pixel_id',
                    'label' => 'Pixel ID',
                    'type' => 'text',
                    'required' => true,
                    'secret' => false,
                    'placeholder' => '123456789012345',
                    'help' => 'Events Manager → Data sources → your pixel. The number under its name.',
                    'rules' => ['string', 'regex:/^\d{5,25}$/'],
                ],
                [
                    'key' => 'access_token',
                    'label' => 'Conversions API token',
                    'type' => 'secret',
                    'required' => true,
                    'secret' => true,
                    'placeholder' => 'EAAB...',
                    'help' => 'Events Manager → your pixel → Settings → Conversions API → Generate access token.',
                    'rules' => ['string', 'min:20', 'max:1000'],
                ],
                [
                    'key' => 'test_event_code',
                    'label' => 'Test event code',
                    'type' => 'text',
                    'required' => false,
                    'secret' => false,
                    'placeholder' => 'TEST12345',
                    'help' => 'Optional. While set, events appear under Test events instead of counting as real ones. Clear it to go live.',
                    'rules' => ['string', 'max:40'],
                ],
            ],
            self::GoogleAnalytics => [
                [
                    'key' => 'measurement_id',
                    'label' => 'Measurement ID',
                    'type' => 'text',
                    'required' => true,
                    'secret' => false,
                    'placeholder' => 'G-XXXXXXXXXX',
                    'help' => 'Google Analytics → Admin → Data streams → your web stream.',
                    'rules' => ['string', 'regex:/^G-[A-Z0-9]{4,20}$/i'],
                ],
                [
                    'key' => 'api_secret',
                    'label' => 'Measurement Protocol API secret',
                    'type' => 'secret',
                    'required' => true,
                    'secret' => true,
                    'help' => 'The same data stream → Measurement Protocol API secrets → Create.',
                    'rules' => ['string', 'min:10', 'max:200'],
                ],
            ],
        };
    }

    /** @return list<string> */
    public function secretKeys(): array
    {
        return array_values(array_map(
            fn (array $field) => $field['key'],
            array_filter($this->fields(), fn (array $field) => $field['secret']),
        ));
    }

    /** @return list<string> */
    public function settingKeys(): array
    {
        return array_values(array_map(
            fn (array $field) => $field['key'],
            array_filter($this->fields(), fn (array $field) => ! $field['secret']),
        ));
    }

    /** Whose words a failure from this app is in — see UpstreamError. */
    public function upstream(): UpstreamProvider
    {
        return match ($this) {
            self::OpenPix => UpstreamProvider::OpenPix,
            self::MercadoPago => UpstreamProvider::MercadoPago,
            self::MetaPixel => UpstreamProvider::MetaPixel,
            self::GoogleAnalytics => UpstreamProvider::GoogleAnalytics,
        };
    }

    /**
     * @return list<self>
     */
    public static function forCategory(IntegrationCategory $category): array
    {
        return array_values(array_filter(self::cases(), fn (self $provider) => $provider->category() === $category));
    }

    /**
     * The same, as the string values a query or a validation rule needs.
     *
     * @return list<string>
     */
    public static function valuesFor(IntegrationCategory $category): array
    {
        return array_map(fn (self $provider) => $provider->value, self::forCategory($category));
    }

    /**
     * The catalog as the dashboard reads it: everything above except the
     * validation rules, which are the server's business.
     *
     * @return array<string, mixed>
     */
    public function toCatalog(): array
    {
        return [
            'provider' => $this->value,
            'category' => $this->category()->value,
            'name' => $this->label(),
            'description' => $this->description(),
            'docs_url' => $this->docsUrl(),
            'payment_methods' => $this->paymentMethods(),
            'fields' => array_map(function (array $field) {
                unset($field['rules']);

                return $field;
            }, $this->fields()),
        ];
    }
}

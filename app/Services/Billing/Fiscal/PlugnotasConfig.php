<?php

namespace App\Services\Billing\Fiscal;

use App\Enums\Billing\InvoicePurpose;
use App\Models\Setting;
use Illuminate\Support\Str;

/**
 * Pingly's Plugnotas account: the platform as prestador, issuing a nota fiscal
 * (NFS-e) for every invoice a Brazilian workspace pays.
 *
 * Stored in `settings` like every platform credential and edited in Back
 * Office → Integrations → Nota fiscal. The fiscal codes live here rather than
 * per plan because they describe what *Pingly* sells — one SaaS service, one
 * item of the LC 116 list — not what a given plan contains.
 *
 * ⚠️ Not the workspace-level Spedy integration (`integrations` table, flow node
 * Invoice). That one is a customer's company issuing notas to *its* customers;
 * this one is Pingly's company issuing to Pingly's.
 */
class PlugnotasConfig
{
    public const ENABLED = 'plugnotas.enabled';

    public const API_KEY = 'plugnotas.api_key';

    /** '1' = sandbox host. Keys belong to one environment and fail in the other. */
    public const SANDBOX = 'plugnotas.sandbox';

    /** Pingly's CNPJ — the company registered in Plugnotas (with its certificate). */
    public const PRESTADOR_CNPJ = 'plugnotas.prestador_cnpj';

    /** Item of the LC 116 list ("1.03"), as the prefeitura spells it. */
    public const SERVICE_CODE = 'plugnotas.service_code';

    /** Código de tributação municipal — optional, some cities demand it. */
    public const TAX_CODE = 'plugnotas.tax_code';

    public const CNAE = 'plugnotas.cnae';

    /** ISS rate in percent (2 = 2%). */
    public const ISS_RATE = 'plugnotas.iss_rate';

    /** Plugnotas `iss.tipoTributacao` (0–8). Empty = let Plugnotas default it. */
    public const ISS_TAX_TYPE = 'plugnotas.iss_tax_type';

    /** Plugnotas `iss.exigibilidade` (1–7). Empty = not sent. */
    public const ISS_REQUIREMENT = 'plugnotas.iss_requirement';

    /** Free text appended to every nota (informacoesComplementares). */
    public const NOTES = 'plugnotas.notes';

    /** '1' = Plugnotas e-mails the PDF and XML to the tomador. */
    public const SEND_EMAIL = 'plugnotas.send_email';

    /** JSON list of InvoicePurpose values a nota is issued for. Empty = all. */
    public const PURPOSES = 'plugnotas.purposes';

    /** Sent by Plugnotas in a header on every webhook; ours, random. */
    public const WEBHOOK_TOKEN = 'plugnotas.webhook_token';

    public const PRODUCTION_URL = 'https://api.plugnotas.com.br';

    public const SANDBOX_URL = 'https://api.sandbox.plugnotas.com.br';

    /** The header the token travels in — a header, so it never lands in an access log. */
    public const WEBHOOK_HEADER = 'X-Pingly-Token';

    public static function enabled(): bool
    {
        return self::flag(self::ENABLED);
    }

    public static function apiKey(): ?string
    {
        return Setting::get(self::API_KEY) ?: null;
    }

    public static function sandbox(): bool
    {
        return self::flag(self::SANDBOX);
    }

    public static function baseUrl(): string
    {
        return self::sandbox() ? self::SANDBOX_URL : self::PRODUCTION_URL;
    }

    public static function prestadorCnpj(): ?string
    {
        $digits = preg_replace('/\D/', '', (string) Setting::get(self::PRESTADOR_CNPJ));

        return $digits !== '' ? $digits : null;
    }

    public static function serviceCode(): ?string
    {
        return self::text(self::SERVICE_CODE);
    }

    public static function taxCode(): ?string
    {
        return self::text(self::TAX_CODE);
    }

    public static function cnae(): ?string
    {
        $digits = preg_replace('/\D/', '', (string) Setting::get(self::CNAE));

        return $digits !== '' ? $digits : null;
    }

    public static function issRate(): ?float
    {
        $value = Setting::get(self::ISS_RATE);

        return is_numeric($value) ? (float) $value : null;
    }

    public static function issTaxType(): ?int
    {
        $value = Setting::get(self::ISS_TAX_TYPE);

        return is_numeric($value) ? (int) $value : null;
    }

    public static function issRequirement(): ?int
    {
        $value = Setting::get(self::ISS_REQUIREMENT);

        return is_numeric($value) ? (int) $value : null;
    }

    public static function notes(): ?string
    {
        return self::text(self::NOTES);
    }

    public static function sendEmail(): bool
    {
        // Absent = on: the nota is the customer's document, and Plugnotas
        // mailing it is the cheapest way it reaches their accountant.
        $value = Setting::get(self::SEND_EMAIL);

        return $value === null || in_array((string) $value, ['1', 'true'], true);
    }

    /** @return list<string> */
    public static function purposes(): array
    {
        $stored = json_decode((string) Setting::get(self::PURPOSES), true);
        $all = array_map(fn (InvoicePurpose $p) => $p->value, InvoicePurpose::cases());

        if (! is_array($stored) || $stored === []) {
            return $all;
        }

        return array_values(array_intersect($all, $stored));
    }

    public static function issuesFor(?InvoicePurpose $purpose): bool
    {
        return in_array(($purpose ?? InvoicePurpose::Subscription)->value, self::purposes(), true);
    }

    public static function webhookToken(): ?string
    {
        return Setting::get(self::WEBHOOK_TOKEN) ?: null;
    }

    /** Minted on first use; rotating it means registering the webhook again. */
    public static function ensureWebhookToken(): string
    {
        $token = self::webhookToken();

        if ($token === null) {
            $token = Str::random(48);
            Setting::set(self::WEBHOOK_TOKEN, $token);
        }

        return $token;
    }

    public static function webhookUrl(): string
    {
        return route('webhook.plugnotas');
    }

    /**
     * What is missing before a nota can be issued, in the words the settings
     * screen uses. Empty = ready.
     *
     * @return list<string>
     */
    public static function missing(): array
    {
        return array_values(array_filter([
            self::apiKey() === null ? 'api_key' : null,
            self::prestadorCnpj() === null ? 'prestador_cnpj' : null,
            self::serviceCode() === null ? 'service_code' : null,
            self::issRate() === null ? 'iss_rate' : null,
        ]));
    }

    public static function ready(): bool
    {
        return self::enabled() && self::missing() === [];
    }

    private static function flag(string $key): bool
    {
        return in_array((string) Setting::get($key), ['1', 'true'], true);
    }

    private static function text(string $key): ?string
    {
        $value = trim((string) Setting::get($key));

        return $value !== '' ? $value : null;
    }
}

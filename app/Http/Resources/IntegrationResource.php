<?php

namespace App\Http\Resources;

use App\Enums\Integration\IntegrationCategory;
use App\Enums\Integration\IntegrationProvider;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A connected account as the dashboard sees it.
 *
 * Secrets are never in it — only the last four characters, so somebody can
 * tell which key is stored without the key being in the network tab. The
 * webhook URL (which carries its own secret token) is only for people who may
 * view integrations; the flow builder lists these too, and whoever can edit a
 * flow has no reason to learn it.
 */
class IntegrationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var IntegrationProvider $provider */
        $provider = $this->provider;
        $meta = $this->meta ?? [];

        return [
            'id' => $this->id,
            'provider' => $provider->value,
            'category' => $provider->category()->value,
            'name' => $this->name,
            'enabled' => (bool) $this->enabled,
            'settings' => (object) ($this->settings ?? []),
            'credentials' => (object) $this->maskedCredentials($provider),
            'account' => $meta['account'] ?? null,
            'payment_methods' => $provider->paymentMethods(),
            'webhook' => $this->webhook($request, $provider, $meta),
            'verified_at' => $this->verified_at,
            'last_used_at' => $this->last_used_at,
            'last_error' => $this->last_error,
            'last_error_at' => $this->last_error_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'used_by_flows' => $this->whenLoaded('usedByFlows', fn () => array_values($this->usedByFlows->all())),
        ];
    }

    /** @return array<string, string|null> */
    private function maskedCredentials(IntegrationProvider $provider): array
    {
        $stored = $this->credentials ?? [];
        $masked = [];

        foreach ($provider->secretKeys() as $key) {
            $value = (string) ($stored[$key] ?? '');
            $masked[$key] = $value === '' ? null : '••••'.mb_substr($value, -4);
        }

        return $masked;
    }

    /**
     * How payments reach us for this account.
     *
     * `account` mode is OpenPix: one URL registered on the account, which
     * either worked or did not. `per_payment` is Mercado Pago: each charge
     * carries the URL itself, so there is nothing to register — only the
     * platform's own address has to be https for it to be sent at all.
     *
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>|null
     */
    private function webhook(Request $request, IntegrationProvider $provider, array $meta): ?array
    {
        if ($provider->category() !== IntegrationCategory::Payment || ! $request->user()?->can('integrations.view')) {
            return null;
        }

        $url = $this->resource->webhookUrl();
        $perPayment = $provider === IntegrationProvider::MercadoPago;

        return [
            'url' => $url,
            'mode' => $perPayment ? 'per_payment' : 'account',
            'registered' => $perPayment
                ? str_starts_with((string) $url, 'https://')
                : ! empty($meta['webhook']['webhook_ids'] ?? null),
            'error' => $meta['webhook_error'] ?? null,
        ];
    }
}

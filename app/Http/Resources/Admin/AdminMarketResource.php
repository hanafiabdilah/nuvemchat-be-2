<?php

namespace App\Http\Resources\Admin;

use App\Models\MarketDomain;
use App\Services\Market\MarketResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A market as the Back Office manages it.
 *
 * Unlike the public MarketResource it carries the status and what is locked:
 * the screen has to say why the currency can't be changed and why a market
 * can't be deleted before someone tries, not after.
 *
 * Expects `domains` loaded and `tenants_count` counted.
 */
class AdminMarketResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $tenants = (int) ($this->tenants_count ?? 0);
        $isDefault = $this->code === MarketResolver::defaultCode();

        return [
            'code' => $this->code,
            'name' => $this->name,
            'currency' => $this->currency,
            'default_locale' => $this->default_locale,
            'default_timezone' => $this->default_timezone,
            'phone_country' => $this->phone_country,
            'status' => $this->status->value,
            'is_default' => $isDefault,
            'tenants_count' => $tenants,
            'currency_locked' => $tenants > 0,
            'deletable' => ! $isDefault && $tenants === 0,
            'domains' => $this->domains
                ->sortBy([['is_primary', 'desc'], ['id', 'asc']])
                ->values()
                ->map(fn (MarketDomain $domain) => [
                    'id' => $domain->id,
                    'domain' => $domain->domain,
                    'is_primary' => $domain->is_primary,
                    'created_at' => $domain->created_at,
                ]),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}

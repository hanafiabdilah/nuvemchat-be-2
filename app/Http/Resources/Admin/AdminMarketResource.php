<?php

namespace App\Http\Resources\Admin;

use App\Models\MarketDomain;
use App\Services\Market\MarketCapabilities;
use App\Services\Market\MarketDocuments;
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
            'price_rounding_cents' => $this->roundingCents(),
            'default_locale' => $this->default_locale,
            'default_timezone' => $this->default_timezone,
            'phone_country' => $this->phone_country,
            'status' => $this->status->value,
            // The effective answers, and what they would be with nothing stored.
            // The form needs both: a box ticked because an admin decided it and
            // a box ticked because the supplier is in this country are the same
            // picture, and only one of them is a decision somebody made.
            'capabilities' => MarketCapabilities::forMarket($this->code),
            'capability_defaults' => MarketCapabilities::defaultsFor($this->code),
            // What a payer here is asked for. Empty is a real answer — this
            // country asks for nothing — so the shipped list travels beside it,
            // or the screen cannot tell "nobody decided" from "decided: none".
            'documents' => MarketDocuments::forMarket($this->code),
            'document_defaults' => MarketDocuments::defaultsFor($this->code),
            'documents_customised' => $this->documents !== null,
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

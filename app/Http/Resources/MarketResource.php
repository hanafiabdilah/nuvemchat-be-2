<?php

namespace App\Http\Resources;

use App\Services\Market\MarketCapabilities;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A market as the dashboard sees it.
 *
 * Status is left out on purpose: this is served to anyone who opens a signup
 * page, and whether a country is still a draft is a launch plan, not something
 * a visitor needs.
 */
class MarketResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'code' => $this->code,
            'name' => $this->name,
            'currency' => $this->currency,
            'default_locale' => $this->default_locale,
            'default_timezone' => $this->default_timezone,
            'phone_country' => $this->phone_country,
            // What this country sells and may connect. The dashboard hides what
            // it cannot buy rather than letting someone reach a form whose
            // submit is refused — and hiding it in the bundle instead would mean
            // one build per country.
            'capabilities' => MarketCapabilities::forMarket($this->code),
        ];
    }
}

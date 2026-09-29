<?php

namespace App\Http\Resources\Catalog;

use App\Models\ProductVariant;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A product as the Products page shows it: the product, every variant, and
 * the two summaries the list needs without doing arithmetic in the browser —
 * the price range and whether anything is running out.
 */
class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $currency = $request->user()?->tenant?->currency();
        $variants = $this->whenLoaded('variants', fn () => $this->variants, collect());
        $prices = $variants->pluck('price_cents');
        $tracked = $variants->filter(fn (ProductVariant $v) => $v->stock !== null);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'image_url' => $this->image_url,
            'gallery_asset_id' => $this->gallery_asset_id,
            'has_variants' => $this->has_variants,
            'active' => $this->active,
            'currency' => $currency,
            'price_min_cents' => $prices->min(),
            'price_max_cents' => $prices->max(),
            'price_label' => $prices->isEmpty() ? null : ($prices->min() === $prices->max()
                ? Money::format((int) $prices->min(), $currency)
                : Money::format((int) $prices->min(), $currency).' – '.Money::format((int) $prices->max(), $currency)),
            // Null when no variant is counted: "not tracked" is not zero.
            'stock_total' => $tracked->isEmpty() ? null : (int) $tracked->sum('stock'),
            'has_negative_stock' => $tracked->contains(fn (ProductVariant $v) => $v->stock < 0),
            'variants' => $variants->map(fn (ProductVariant $v) => [
                'id' => $v->id,
                'name' => $v->name,
                'sku' => $v->sku,
                'price_cents' => $v->price_cents,
                'stock' => $v->stock,
                'active' => $v->active,
            ])->values(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}

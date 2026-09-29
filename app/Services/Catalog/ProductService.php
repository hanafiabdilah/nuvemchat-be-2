<?php

namespace App\Services\Catalog;

use App\Enums\Catalog\StockMovementReason;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Writing a product and its variants in one go.
 *
 * The dashboard always sends a `variants` list, even for a product that "has
 * no variants": then the list holds one entry with no name, and this keeps
 * exactly one. That single rule is what lets every other part of the system —
 * the AI's tools, the order lines, the import — deal only in variants.
 */
final class ProductService
{
    public function __construct(private StockService $stock) {}

    /**
     * @param  array<string, mixed>  $data  validated by ProductController
     */
    public function create(int $tenantId, array $data, ?User $user = null): Product
    {
        return DB::transaction(function () use ($tenantId, $data, $user) {
            $product = Product::create([
                'tenant_id' => $tenantId,
                'name' => trim((string) $data['name']),
                'description' => $this->nullableText($data['description'] ?? null),
                'image_url' => $this->nullableText($data['image_url'] ?? null),
                'gallery_asset_id' => $data['gallery_asset_id'] ?? null,
                'has_variants' => (bool) ($data['has_variants'] ?? false),
                'active' => (bool) ($data['active'] ?? true),
                'position' => (int) (Product::forTenant($tenantId)->max('position') ?? 0) + 1,
            ]);

            $this->syncVariants($product, $data['variants'] ?? [], $user);

            return $product->load('variants');
        });
    }

    /**
     * @param  array<string, mixed>  $data  validated by ProductController
     */
    public function update(Product $product, array $data, ?User $user = null): Product
    {
        return DB::transaction(function () use ($product, $data, $user) {
            $product->update(array_filter([
                'name' => array_key_exists('name', $data) ? trim((string) $data['name']) : null,
                'description' => array_key_exists('description', $data) ? ($this->nullableText($data['description']) ?? '') : null,
                'image_url' => array_key_exists('image_url', $data) ? ($this->nullableText($data['image_url']) ?? '') : null,
                'has_variants' => array_key_exists('has_variants', $data) ? (bool) $data['has_variants'] : null,
                'active' => array_key_exists('active', $data) ? (bool) $data['active'] : null,
            ], fn ($value) => $value !== null));

            // Emptied fields: stored as null, not as an empty string that
            // renders as a blank line in the AI's product card.
            foreach (['description', 'image_url'] as $field) {
                if ($product->{$field} === '') {
                    $product->forceFill([$field => null])->save();
                }
            }

            if (array_key_exists('gallery_asset_id', $data)) {
                $product->forceFill(['gallery_asset_id' => $data['gallery_asset_id']])->save();
            }

            if (array_key_exists('variants', $data)) {
                $this->syncVariants($product, (array) $data['variants'], $user);
            }

            return $product->refresh()->load('variants');
        });
    }

    public function delete(Product $product): void
    {
        DB::transaction(function () use ($product) {
            // The variants go too, so a search never offers something whose
            // product is gone; order lines keep their own copy of the name.
            $product->variants()->get()->each->delete();
            $product->delete();
        });
    }

    /**
     * Update, create and drop variants to match the list sent.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function syncVariants(Product $product, array $rows, ?User $user): void
    {
        $rows = array_values($rows);

        if (! $product->has_variants) {
            // One variant, no name. The id is kept when the dashboard sent one,
            // so switching variants off keeps the first variant's history.
            $rows = [array_merge($rows[0] ?? [], ['name' => null])];
        }

        if ($rows === []) {
            throw ValidationException::withMessages([
                'variants' => __('A product needs at least one price.'),
            ]);
        }

        $existing = $product->variants()->get()->keyBy('id');
        $kept = [];

        foreach ($rows as $position => $row) {
            $id = (int) ($row['id'] ?? 0);
            $variant = $id > 0 ? $existing->get($id) : null;

            $name = $product->has_variants ? trim((string) ($row['name'] ?? '')) : null;

            if ($product->has_variants && $name === '') {
                throw ValidationException::withMessages([
                    "variants.{$position}.name" => __('Give every variation a name (e.g. "Black / L").'),
                ]);
            }

            $sku = $this->resolveSku($product, $row['sku'] ?? null, $name, $variant, $position);

            $attributes = [
                'name' => $name,
                'sku' => $sku,
                'price_cents' => max(0, (int) ($row['price_cents'] ?? 0)),
                'active' => (bool) ($row['active'] ?? true),
                'position' => $position,
            ];

            $stock = array_key_exists('stock', $row) && $row['stock'] !== null && $row['stock'] !== ''
                ? (int) $row['stock']
                : null;

            if ($variant) {
                $variant->update($attributes);
            } else {
                $variant = $product->variants()->create(array_merge($attributes, [
                    'tenant_id' => $product->tenant_id,
                ]));
            }

            // Stock only ever moves through the service, so the history adds
            // up to what is on screen.
            $this->stock->set($variant, $stock, StockMovementReason::Manual, $user);

            $kept[] = $variant->id;
        }

        $existing->except($kept)->each->delete();
    }

    /**
     * The SKU the shop typed, or one made from the names.
     *
     * Unique per workspace, soft-deleted rows included — the unique index
     * covers them, and a deleted product's SKU being reused would make an
     * import match a row nobody can see.
     */
    private function resolveSku(Product $product, mixed $typed, ?string $variantName, ?ProductVariant $variant, int $position): string
    {
        $typed = strtoupper(trim((string) $typed));

        if ($typed !== '') {
            $taken = ProductVariant::withTrashed()
                ->where('tenant_id', $product->tenant_id)
                ->where('sku', $typed)
                ->when($variant, fn ($query) => $query->whereKeyNot($variant->id))
                ->exists();

            if ($taken) {
                throw ValidationException::withMessages([
                    "variants.{$position}.sku" => __('The SKU ":sku" is already used by another product.', ['sku' => $typed]),
                ]);
            }

            return $typed;
        }

        if ($variant && $variant->sku !== '') {
            return $variant->sku;
        }

        return self::generateSku($product->tenant_id, $product->name, $variantName);
    }

    public static function generateSku(int $tenantId, string $productName, ?string $variantName = null): string
    {
        $base = Str::upper(Str::slug(trim($productName.' '.($variantName ?? '')), '-'));
        $base = Str::limit($base !== '' ? $base : 'ITEM', 48, '');
        $candidate = $base;
        $suffix = 1;

        while (ProductVariant::withTrashed()->where('tenant_id', $tenantId)->where('sku', $candidate)->exists()) {
            $suffix++;
            $candidate = Str::limit($base, 44, '').'-'.$suffix;
        }

        return $candidate;
    }

    private function nullableText(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}

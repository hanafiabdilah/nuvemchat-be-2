<?php

namespace App\Services\Catalog;

use App\Enums\Catalog\StockMovementReason;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Bringing a spreadsheet of products into the catalog, in two steps.
 *
 * `preview` reads the file, guesses which column is which from the header
 * names a shop actually uses ("Preço", "Estoque", "Produto"…) and shows the
 * first rows the way they will be imported. `commit` runs the import with the
 * mapping the person confirmed. The parsed rows wait in the cache between the
 * two, so the file is uploaded once.
 *
 * One row is one variant. Rows that share a product name become one product;
 * a row with something in the variation column makes that product one with
 * variations. A row whose SKU already exists updates that variant — which is
 * what makes "export, fix prices in Excel, import again" work.
 */
final class ProductImport
{
    public const FIELDS = ['name', 'variant', 'sku', 'price', 'stock', 'description', 'image_url', 'active'];

    private const CACHE_MINUTES = 30;

    /**
     * Header words, accent-free and lowercase, that mean each field. Checked
     * in this order, so the more specific "nome da variacao" is seen as the
     * variation before the generic "nome" claims it as the product.
     *
     * @var array<string, list<string>>
     */
    private const HEADER_WORDS = [
        'variant' => ['variacao', 'variante', 'variant', 'variation', 'opcao', 'option', 'tamanho', 'size', 'cor', 'color', 'ukuran', 'varian'],
        'sku' => ['sku', 'codigo', 'code', 'ref', 'referencia', 'kode'],
        'price' => ['preco', 'price', 'valor', 'value', 'harga'],
        'stock' => ['estoque', 'stock', 'quantidade', 'qtd', 'qty', 'inventory', 'stok', 'jumlah'],
        'description' => ['descricao', 'description', 'detalhes', 'details', 'deskripsi'],
        'image_url' => ['imagem', 'image', 'foto', 'photo', 'gambar'],
        'active' => ['ativo', 'active', 'status', 'aktif'],
        'name' => ['produto', 'nome', 'name', 'product', 'titulo', 'title', 'item', 'nama', 'produk'],
    ];

    public function __construct(private StockService $stock) {}

    /**
     * @return array{token: string, headers: list<string>, mapping: array<string, int|null>, sample: list<array<string, string|null>>, rows: list<list<string>>, total_rows: int}
     */
    public function preview(int $tenantId, UploadedFile $file): array
    {
        $rows = SpreadsheetReader::read($file->getRealPath(), $file->getClientOriginalExtension());

        if (count($rows) < 2) {
            throw ValidationException::withMessages([
                'file' => __('The file needs a header row and at least one product.'),
            ]);
        }

        $headers = array_map(fn ($cell) => (string) $cell, array_shift($rows));
        $mapping = self::guessMapping($headers);
        $token = (string) Str::uuid();

        Cache::put($this->cacheKey($tenantId, $token), ['headers' => $headers, 'rows' => $rows], now()->addMinutes(self::CACHE_MINUTES));

        return [
            'token' => $token,
            'headers' => $headers,
            'mapping' => $mapping,
            'sample' => array_map(fn (array $row) => $this->mapRow($row, $mapping), array_slice($rows, 0, 10)),
            // The same rows unread, so the screen can re-read them the moment
            // somebody points a field at a different column.
            'rows' => array_slice($rows, 0, 10),
            'total_rows' => count($rows),
        ];
    }

    /**
     * @param  array<string, int|null>  $mapping  field => column index
     * @return array{created: int, updated: int, skipped: int, rows: list<array{row: int, status: string, message: string|null}>}
     */
    public function commit(int $tenantId, string $token, array $mapping, ?User $user = null): array
    {
        $cached = Cache::get($this->cacheKey($tenantId, $token));

        if (! is_array($cached)) {
            throw ValidationException::withMessages([
                'token' => __('This import expired. Send the file again.'),
            ]);
        }

        $mapping = array_intersect_key($mapping, array_flip(self::FIELDS));

        if (! isset($mapping['name']) || $mapping['name'] === null) {
            throw ValidationException::withMessages([
                'mapping.name' => __('Choose the column with the product name.'),
            ]);
        }

        if (! isset($mapping['price']) || $mapping['price'] === null) {
            throw ValidationException::withMessages([
                'mapping.price' => __('Choose the column with the price.'),
            ]);
        }

        $report = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'rows' => []];
        $products = [];

        foreach ($cached['rows'] as $index => $raw) {
            // +2: the header is row 1, and people count from 1.
            $line = $index + 2;
            $row = $this->mapRow($raw, $mapping);

            try {
                // By reference: the products this import already touched are
                // found here on the next row instead of queried again.
                $status = DB::transaction(function () use ($tenantId, $row, &$products, $user) {
                    return $this->importRow($tenantId, $row, $products, $user);
                });
                $report[$status]++;
                $report['rows'][] = ['row' => $line, 'status' => $status, 'message' => null];
            } catch (ValidationException $e) {
                $report['skipped']++;
                $report['rows'][] = ['row' => $line, 'status' => 'skipped', 'message' => collect($e->errors())->flatten()->first()];
            }
        }

        Cache::forget($this->cacheKey($tenantId, $token));

        return $report;
    }

    /**
     * @param  array<string, string|null>  $row
     * @param  array<string, Product>  $products  products touched in this import, by lowercased name
     */
    private function importRow(int $tenantId, array $row, array &$products, ?User $user): string
    {
        $name = trim((string) ($row['name'] ?? ''));

        if ($name === '') {
            throw ValidationException::withMessages(['name' => __('The product name is empty.')]);
        }

        $price = PriceParser::cents((string) ($row['price'] ?? ''));

        if ($price === null) {
            throw ValidationException::withMessages(['price' => __('The price ":price" is not a number.', ['price' => (string) ($row['price'] ?? '')])]);
        }

        $stockRaw = trim((string) ($row['stock'] ?? ''));
        $stock = null;

        if ($stockRaw !== '') {
            if (! preg_match('/^-?\d+([.,]0+)?$/', $stockRaw)) {
                throw ValidationException::withMessages(['stock' => __('The stock ":stock" is not a whole number.', ['stock' => $stockRaw])]);
            }
            $stock = (int) $stockRaw;
        }

        $variantName = trim((string) ($row['variant'] ?? ''));
        $sku = strtoupper(trim((string) ($row['sku'] ?? '')));
        $active = self::readBoolean($row['active'] ?? null);

        $variant = $sku !== ''
            ? ProductVariant::where('tenant_id', $tenantId)->where('sku', $sku)->first()
            : null;

        if ($sku !== '' && ! $variant && ProductVariant::onlyTrashed()->where('tenant_id', $tenantId)->where('sku', $sku)->exists()) {
            throw ValidationException::withMessages(['sku' => __('The SKU ":sku" belonged to a deleted product. Use another SKU.', ['sku' => $sku])]);
        }

        if ($variant) {
            $variant->update(array_filter([
                'price_cents' => $price,
                'name' => $variantName !== '' ? $variantName : null,
                'active' => $active,
            ], fn ($value) => $value !== null));

            $product = $variant->product;
            $this->touchProduct($product, $row);

            if ($stockRaw !== '') {
                $this->stock->set($variant, $stock, StockMovementReason::Import, $user, 'import');
            }

            return 'updated';
        }

        $key = mb_strtolower($name);
        $product = $products[$key]
            ?? Product::forTenant($tenantId)->whereRaw('LOWER(name) = ?', [$key])->with('variants')->first();

        $status = 'updated';

        if (! $product) {
            $product = Product::create([
                'tenant_id' => $tenantId,
                'name' => Str::limit($name, 255, ''),
                'description' => self::nullable($row['description'] ?? null),
                'image_url' => self::nullable($row['image_url'] ?? null),
                'has_variants' => $variantName !== '',
                'active' => $active ?? true,
                'position' => (int) (Product::forTenant($tenantId)->max('position') ?? 0) + 1,
            ]);
            $status = 'created';
        } else {
            $this->touchProduct($product, $row);
        }

        $products[$key] = $product;

        if ($variantName === '' && $product->has_variants && $status !== 'created') {
            throw ValidationException::withMessages(['variant' => __('":name" has variations: fill in the variation column for this row.', ['name' => $product->name])]);
        }

        // A product imported without variations gets its single variant; a
        // row naming a variation on a product that had none turns it into one
        // with variations, keeping the existing price as the first.
        if ($variantName !== '' && ! $product->has_variants) {
            $product->update(['has_variants' => true]);
        }

        $existing = $product->variants()
            ->when($variantName !== '',
                fn ($query) => $query->whereRaw('LOWER(name) = ?', [mb_strtolower($variantName)]),
                fn ($query) => $query->whereNull('name'))
            ->first();

        if ($existing) {
            $existing->update(array_filter(['price_cents' => $price, 'active' => $active], fn ($v) => $v !== null));

            if ($stockRaw !== '') {
                $this->stock->set($existing, $stock, StockMovementReason::Import, $user, 'import');
            }

            return $status;
        }

        $variant = $product->variants()->create([
            'tenant_id' => $tenantId,
            'name' => $variantName !== '' ? Str::limit($variantName, 255, '') : null,
            'sku' => $sku !== '' ? Str::limit($sku, 64, '') : ProductService::generateSku($tenantId, $product->name, $variantName ?: null),
            'price_cents' => $price,
            'active' => $active ?? true,
            'position' => (int) $product->variants()->count(),
        ]);

        if ($stock !== null) {
            $this->stock->set($variant, $stock, StockMovementReason::Import, $user, 'import');
        }

        return $status === 'created' ? 'created' : 'updated';
    }

    /** @param  array<string, string|null>  $row */
    private function touchProduct(Product $product, array $row): void
    {
        $changes = array_filter([
            'description' => self::nullable($row['description'] ?? null),
            'image_url' => self::nullable($row['image_url'] ?? null),
        ], fn ($value) => $value !== null);

        if ($changes !== []) {
            $product->update($changes);
        }
    }

    /**
     * @param  list<string>  $headers
     * @return array<string, int|null>
     */
    public static function guessMapping(array $headers): array
    {
        $normalized = array_map(
            fn ($header) => trim((string) preg_replace('/[^a-z0-9 ]+/', ' ', Str::lower(Str::ascii((string) $header)))),
            $headers,
        );

        $mapping = array_fill_keys(self::FIELDS, null);
        $taken = [];

        foreach (self::HEADER_WORDS as $field => $words) {
            foreach ($normalized as $index => $header) {
                if (isset($taken[$index]) || $header === '') {
                    continue;
                }

                $tokens = explode(' ', $header);

                if (array_intersect($words, $tokens) !== [] || in_array($header, $words, true)) {
                    $mapping[$field] = $index;
                    $taken[$index] = true;
                    break;
                }
            }
        }

        return $mapping;
    }

    /**
     * @param  list<string>  $row
     * @param  array<string, int|null>  $mapping
     * @return array<string, string|null>
     */
    private function mapRow(array $row, array $mapping): array
    {
        $out = [];

        foreach (self::FIELDS as $field) {
            $index = $mapping[$field] ?? null;
            $out[$field] = $index === null ? null : ($row[(int) $index] ?? null);
        }

        return $out;
    }

    private static function readBoolean(?string $value): ?bool
    {
        $value = Str::lower(Str::ascii(trim((string) $value)));

        if ($value === '') {
            return null;
        }

        return ! in_array($value, ['0', 'nao', 'no', 'false', 'inativo', 'inactive', 'off', 'tidak', 'nonaktif'], true);
    }

    private static function nullable(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function cacheKey(int $tenantId, string $token): string
    {
        return "catalog-import:{$tenantId}:{$token}";
    }
}

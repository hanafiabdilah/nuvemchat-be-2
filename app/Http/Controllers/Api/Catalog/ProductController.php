<?php

namespace App\Http\Controllers\Api\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Resources\Catalog\ProductResource;
use App\Models\GalleryAsset;
use App\Models\Product;
use App\Services\Catalog\PriceParser;
use App\Services\Catalog\ProductImport;
use App\Services\Catalog\ProductService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The workspace's product catalog — what the "Agente IA com ações" node sells
 * from. Prices are minor units in the workspace's currency.
 */
class ProductController extends Controller
{
    public function __construct(
        private readonly ProductService $products,
        private readonly ProductImport $import,
    ) {}

    public function index(Request $request)
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:200'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $tenantId = (int) $request->user()->tenant_id;

        $products = Product::forTenant($tenantId)
            ->when($validated['search'] ?? null, function ($query, $search) {
                $like = '%'.mb_strtolower($search).'%';
                $query->where(fn ($q) => $q
                    ->whereRaw('LOWER(name) LIKE ?', [$like])
                    ->orWhereHas('variants', fn ($v) => $v
                        ->whereRaw('LOWER(sku) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(name) LIKE ?', [$like])));
            })
            ->when(($validated['status'] ?? null) === 'active', fn ($q) => $q->where('active', true))
            ->when(($validated['status'] ?? null) === 'inactive', fn ($q) => $q->where('active', false))
            ->with('variants')
            ->orderBy('name')
            ->paginate($validated['per_page'] ?? 50);

        return ProductResource::collection($products);
    }

    public function show(Request $request, int $id)
    {
        return new ProductResource($this->find($request, $id)->load('variants'));
    }

    public function store(Request $request)
    {
        $data = $request->validate($this->rules($request, creating: true));

        $product = $this->products->create((int) $request->user()->tenant_id, $data, $request->user());

        return (new ProductResource($product))->response()->setStatusCode(201);
    }

    public function update(Request $request, int $id)
    {
        $product = $this->find($request, $id);
        $data = $request->validate($this->rules($request, creating: false));

        return new ProductResource($this->products->update($product, $data, $request->user()));
    }

    public function destroy(Request $request, int $id)
    {
        $this->products->delete($this->find($request, $id));

        return response()->noContent();
    }

    /** Read a spreadsheet and show how it will be imported. Nothing is written. */
    public function importPreview(Request $request)
    {
        $request->validate([
            'file' => ['required', 'file', 'max:10240', 'mimes:csv,txt,xlsx'],
        ]);

        return response()->json($this->import->preview((int) $request->user()->tenant_id, $request->file('file')));
    }

    public function importCommit(Request $request)
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:64'],
            'mapping' => ['required', 'array'],
            'mapping.*' => ['nullable', 'integer', 'min:0', 'max:200'],
        ]);

        return response()->json($this->import->commit(
            (int) $request->user()->tenant_id,
            $data['token'],
            $data['mapping'],
            $request->user(),
        ));
    }

    private function find(Request $request, int $id): Product
    {
        return Product::forTenant((int) $request->user()->tenant_id)->findOrFail($id);
    }

    /** @return array<string, mixed> */
    private function rules(Request $request, bool $creating): array
    {
        $tenantId = (int) $request->user()->tenant_id;
        $required = $creating ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'image_url' => ['sometimes', 'nullable', 'string', 'max:2000', 'url:https,http'],
            'gallery_asset_id' => ['sometimes', 'nullable', 'integer', Rule::exists((new GalleryAsset)->getTable(), 'id')->where('tenant_id', $tenantId)],
            'has_variants' => ['sometimes', 'boolean'],
            'active' => ['sometimes', 'boolean'],
            'variants' => [$required, 'array', 'min:1', 'max:100'],
            'variants.*.id' => ['nullable', 'integer'],
            'variants.*.name' => ['nullable', 'string', 'max:255'],
            'variants.*.sku' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9._\-]+$/'],
            'variants.*.price_cents' => ['required', 'integer', 'min:0', 'max:'.PriceParser::MAX_CENTS],
            'variants.*.stock' => ['nullable', 'integer', 'min:-1000000', 'max:1000000'],
            'variants.*.active' => ['sometimes', 'boolean'],
        ];
    }
}

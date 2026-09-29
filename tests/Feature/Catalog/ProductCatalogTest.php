<?php

use App\Enums\Catalog\StockMovementReason;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Services\Catalog\PriceParser;
use App\Services\Catalog\ProductImport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Support\IntegrationFixtures as Fx;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['services.billing.enforce' => false]);
});

function catalogManager(?\App\Models\Tenant $tenant = null): \App\Models\User
{
    return Fx::user($tenant, ['products.view', 'products.manage', 'orders.view']);
}

it('creates a simple product as one unnamed variant with a generated SKU', function () {
    $user = catalogManager();

    $response = $this->actingAs($user)->postJson('/api/products', [
        'name' => 'Boné Aba Reta',
        'has_variants' => false,
        'variants' => [['price_cents' => 3990, 'stock' => 12]],
    ])->assertCreated();

    $variant = ProductVariant::firstOrFail();

    expect($variant->name)->toBeNull()
        ->and($variant->sku)->toBe('BONE-ABA-RETA')
        ->and($variant->stock)->toBe(12)
        ->and($response->json('data.price_label'))->toBe('R$ 39,90')
        ->and($response->json('data.stock_total'))->toBe(12);

    // The starting stock is a movement, so the history adds up to the number.
    expect(StockMovement::firstOrFail()->only(['delta', 'stock_after']))->toBe(['delta' => 12, 'stock_after' => 12])
        ->and(StockMovement::firstOrFail()->reason)->toBe(StockMovementReason::Manual);
});

it('keeps variants in sync: updates, adds and drops', function () {
    $user = catalogManager();

    $created = $this->actingAs($user)->postJson('/api/products', [
        'name' => 'Camiseta Preta',
        'has_variants' => true,
        'variants' => [
            ['name' => 'P', 'price_cents' => 5990, 'stock' => 3],
            ['name' => 'M', 'price_cents' => 5990, 'stock' => 5],
        ],
    ])->assertCreated()->json('data');

    [$p, $m] = $created['variants'];

    $this->actingAs($user)->putJson("/api/products/{$created['id']}", [
        'variants' => [
            ['id' => $p['id'], 'name' => 'P', 'price_cents' => 6490, 'stock' => 3],
            ['name' => 'G', 'price_cents' => 6990, 'stock' => null],
        ],
    ])->assertOk();

    $variants = Product::findOrFail($created['id'])->variants;

    expect($variants->pluck('name')->all())->toBe(['P', 'G'])
        ->and($variants->first()->price_cents)->toBe(6490)
        ->and($variants->last()->stock)->toBeNull()
        ->and(ProductVariant::withTrashed()->find($m['id'])->trashed())->toBeTrue();
});

it('refuses a SKU another product already uses', function () {
    $user = catalogManager();

    $this->actingAs($user)->postJson('/api/products', [
        'name' => 'A', 'variants' => [['sku' => 'ABC-1', 'price_cents' => 100]],
    ])->assertCreated();

    $this->actingAs($user)->postJson('/api/products', [
        'name' => 'B', 'variants' => [['sku' => 'abc-1', 'price_cents' => 100]],
    ])->assertStatus(422)->assertJsonValidationErrors('variants.0.sku');
});

it('requires a name for every variation', function () {
    $this->actingAs(catalogManager())->postJson('/api/products', [
        'name' => 'Camiseta', 'has_variants' => true,
        'variants' => [['name' => '', 'price_cents' => 100]],
    ])->assertStatus(422)->assertJsonValidationErrors('variants.0.name');
});

it('never shows or changes another workspace\'s product', function () {
    $owner = catalogManager();
    $product = $this->actingAs($owner)->postJson('/api/products', [
        'name' => 'Segredo', 'variants' => [['price_cents' => 100]],
    ])->json('data');

    $stranger = catalogManager();

    $this->actingAs($stranger)->getJson("/api/products/{$product['id']}")->assertNotFound();
    $this->actingAs($stranger)->deleteJson("/api/products/{$product['id']}")->assertNotFound();
    expect($this->actingAs($stranger)->getJson('/api/products')->json('data'))->toBe([]);
});

it('lets someone read the catalog without being able to change it', function () {
    $tenant = Fx::tenant();
    $reader = Fx::user($tenant, ['products.view']);

    $this->actingAs($reader)->getJson('/api/products')->assertOk();
    $this->actingAs($reader)->postJson('/api/products', ['name' => 'x', 'variants' => [['price_cents' => 1]]])->assertForbidden();
});

it('reads prices the way shops write them', function (string $raw, ?int $cents) {
    expect(PriceParser::cents($raw))->toBe($cents);
})->with([
    ['59,90', 5990],
    ['R$ 1.234,56', 123456],
    ['Rp 149.000', 14900000],
    ['1,234.56', 123456],
    ['59.9', 5990],
    ['60', 6000],
    ['0', 0],
    ['grátis', null],
]);

it('guesses the columns from the header names a shop uses', function () {
    expect(ProductImport::guessMapping(['Produto', 'Variação', 'Código', 'Preço de venda', 'Estoque', 'Descrição']))
        ->toBe([
            'name' => 0, 'variant' => 1, 'sku' => 2, 'price' => 3, 'stock' => 4,
            'description' => 5, 'image_url' => null, 'active' => null,
        ]);
});

it('imports a pt-BR CSV: groups variations, then updates by SKU on the second pass', function () {
    $user = catalogManager();

    $csv = "Produto;Variação;SKU;Preço;Estoque\n"
        ."Camiseta Preta;P;CAM-P;59,90;3\n"
        ."Camiseta Preta;G;CAM-G;59,90;0\n"
        ."Boné;;BONE;39,90;\n"
        ."Sem preço;;;abc;1\n";

    $preview = $this->actingAs($user)->post('/api/products/import/preview', [
        'file' => UploadedFile::fake()->createWithContent('produtos.csv', $csv),
    ])->assertOk()->json();

    expect($preview['total_rows'])->toBe(4)
        ->and($preview['sample'][0])->toMatchArray(['name' => 'Camiseta Preta', 'variant' => 'P', 'price' => '59,90']);

    $report = $this->actingAs($user)->postJson('/api/products/import', [
        'token' => $preview['token'],
        'mapping' => $preview['mapping'],
    ])->assertOk()->json();

    expect($report['created'])->toBe(2)
        ->and($report['updated'])->toBe(1)
        ->and($report['skipped'])->toBe(1)
        ->and($report['rows'][3]['row'])->toBe(5)
        ->and(Product::count())->toBe(2)
        ->and(Product::where('name', 'Camiseta Preta')->firstOrFail()->has_variants)->toBeTrue()
        ->and(ProductVariant::where('sku', 'BONE')->firstOrFail()->stock)->toBeNull();

    // Export, fix in Excel, import again: the SKU finds the row.
    $second = $this->actingAs($user)->post('/api/products/import/preview', [
        'file' => UploadedFile::fake()->createWithContent('produtos.csv', "SKU;Produto;Preço;Estoque\nCAM-P;Camiseta Preta;64,90;10\n"),
    ])->json();

    $this->actingAs($user)->postJson('/api/products/import', [
        'token' => $second['token'], 'mapping' => $second['mapping'],
    ])->assertOk()->assertJsonPath('updated', 1);

    $variant = ProductVariant::where('sku', 'CAM-P')->firstOrFail();

    expect($variant->price_cents)->toBe(6490)
        ->and($variant->stock)->toBe(10)
        ->and(StockMovement::where('product_variant_id', $variant->id)->where('reason', 'import')->sum('delta'))->toBe(10);
});

it('imports the first sheet of an xlsx file', function () {
    if (! class_exists(ZipArchive::class)) {
        $this->markTestSkipped('zip extension missing');
    }

    $path = tempnam(sys_get_temp_dir(), 'xlsx');
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::OVERWRITE);
    $zip->addFromString('xl/workbook.xml', '<workbook xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Produtos" sheetId="1" r:id="rId1"/></sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<Relationships><Relationship Id="rId1" Target="worksheets/sheet1.xml"/></Relationships>');
    $zip->addFromString('xl/sharedStrings.xml', '<sst><si><t>Nome</t></si><si><t>Preço</t></si><si><t>Caneca</t></si></sst>');
    $zip->addFromString('xl/worksheets/sheet1.xml', '<worksheet><sheetData>'
        .'<row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c></row>'
        .'<row r="2"><c r="A2" t="s"><v>2</v></c><c r="B2"><v>29.9</v></c></row>'
        .'</sheetData></worksheet>');
    $zip->close();

    $user = catalogManager();

    $preview = $this->actingAs($user)->post('/api/products/import/preview', [
        'file' => new UploadedFile($path, 'produtos.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true),
    ])->assertOk()->json();

    $this->actingAs($user)->postJson('/api/products/import', [
        'token' => $preview['token'], 'mapping' => $preview['mapping'],
    ])->assertOk()->assertJsonPath('created', 1);

    expect(ProductVariant::firstOrFail()->price_cents)->toBe(2990);
});

it('lists what the AI sold, never an empty cart', function () {
    $user = catalogManager();

    \App\Models\Order::create(['tenant_id' => $user->tenant_id, 'status' => 'open', 'currency' => 'BRL', 'total_cents' => 0]);
    \App\Models\Order::create(['tenant_id' => $user->tenant_id, 'status' => 'paid', 'currency' => 'BRL', 'total_cents' => 3990]);

    $orders = $this->actingAs($user)->getJson('/api/orders')->assertOk()->json('data');

    expect($orders)->toHaveCount(1)->and($orders[0]['total'])->toBe('R$ 39,90');
});

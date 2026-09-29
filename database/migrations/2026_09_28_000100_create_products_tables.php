<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The workspace's own product catalog — what the "Agente IA com ações" node
 * sells from.
 *
 * Three tables, and the split is the whole design:
 *
 *  - `products` is what a person thinks of as the thing on sale.
 *  - `product_variants` is what is actually priced and counted. EVERY product
 *    has at least one, including the ones that "have no variants": a simple
 *    product is a single hidden variant with a null name. The AI's tools and
 *    the order lines only ever point at variants, so adding sizes to a product
 *    later never changes the contract the hub was given.
 *  - `stock_movements` is the only way stock changes. `product_variants.stock`
 *    is a cache written in the same transaction as its movement row, so "why
 *    does it say 3?" is answerable from the table, not from memory.
 *
 * `stock` null means "not counted" (a service, something made to order) and is
 * the default: a number is typed only by someone who wants it enforced.
 *
 * Money is minor units, unsigned big integer, in the tenant's currency — the
 * lesson from widening every money column before rupiah (2026_09_16_000300).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 255);
            $table->text('description')->nullable();
            // A URL we will show and may send. Uploaded straight to the
            // published disk (no gallery quota — every plan before the gallery
            // has 0 GB) or picked from the gallery, whose link never expires.
            $table->text('image_url')->nullable();
            $table->foreignId('gallery_asset_id')->nullable()->constrained('gallery_assets')->nullOnDelete();
            $table->boolean('has_variants')->default(false);
            $table->boolean('active')->default(true);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'active']);
            $table->index(['tenant_id', 'name']);
        });

        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            // Null on a simple product's single variant: there is nothing to
            // tell it apart from, and inventing "Padrão" would print it in
            // every answer the AI gives.
            $table->string('name', 255)->nullable();
            $table->string('sku', 64);
            $table->unsignedBigInteger('price_cents');
            // Signed: a sale the AI took while two customers paid for the last
            // unit is recorded, not refused — the money is already real. The
            // negative number is how the shop finds out.
            $table->integer('stock')->nullable();
            $table->boolean('active')->default(true);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
            $table->softDeletes();

            // What an import matches on, and what a person types to find a row.
            $table->unique(['tenant_id', 'sku']);
            $table->index(['product_id', 'position']);
        });

        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained('product_variants')->cascadeOnDelete();
            $table->integer('delta');
            $table->integer('stock_after')->nullable();
            $table->string('reason', 30);
            $table->unsignedBigInteger('order_id')->nullable()->index();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('note', 255)->nullable();
            $table->timestamps();

            $table->index(['product_variant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('product_variants');
        Schema::dropIfExists('products');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the AI sold: an order is the cart it built in a conversation, and then
 * the charge that paid for it.
 *
 * The cart lives here rather than in the flow state for two reasons: the shop
 * wants to see what was bought (and what was left in a cart), and stock can
 * only be taken when the money arrives — which happens in a webhook, long after
 * the flow state the cart was built in has moved on.
 *
 * `order_items` copies the name, SKU and price at the moment of sale. A product
 * edited or deleted afterwards must never rewrite an order somebody paid for.
 *
 * No shipping or address columns, on purpose: shipping is out of this release,
 * and a field nothing fills reads as a feature that does not exist.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('flow_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('flow_node_id')->nullable()->constrained('flow_nodes')->nullOnDelete();
            $table->string('status', 20);
            $table->string('currency', 3);
            $table->unsignedBigInteger('total_cents')->default(0);
            $table->string('source', 20)->default('ai_tools');
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status', 'created_at']);
            // "The cart open in this conversation" — read on every cart tool call.
            $table->index(['conversation_id', 'status']);
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->string('name', 512);
            $table->string('sku', 64)->nullable();
            $table->unsignedBigInteger('unit_price_cents');
            $table->unsignedInteger('quantity');
            $table->unsignedBigInteger('line_total_cents');
            $table->timestamps();
        });

        Schema::table('flow_payments', function (Blueprint $table) {
            // Set for a charge the AI issued for a cart; null for a Payment
            // node's own charge. What settle() reads to take the stock.
            $table->foreignId('order_id')->nullable()->after('flow_node_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('flow_payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('order_id');
        });

        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
    }
};

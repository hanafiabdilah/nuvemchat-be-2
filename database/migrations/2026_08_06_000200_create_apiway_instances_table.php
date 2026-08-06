<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Individual API Way instances owned by a tenant (provisioned by ProxyBR under
 * an apiway_subscription). An instance is "in use" when linked to a Connection;
 * deleting the Connection only releases the link — the asset stays purchased.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('apiway_instances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('apiway_subscription_id')->constrained()->cascadeOnDelete();

            // Core instance UUID (preferred id on every partner console endpoint).
            $table->string('provider_instance_id', 64)->unique();
            $table->string('name')->nullable();
            $table->string('ip_address', 64)->nullable();
            $table->string('status', 40)->nullable();   // provider status string (aguardando_qr, conectado, ...)

            $table->foreignId('connection_id')->nullable()->unique()
                ->constrained('connections')->nullOnDelete();

            $table->timestamps();
            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('apiway_instances');
    }
};

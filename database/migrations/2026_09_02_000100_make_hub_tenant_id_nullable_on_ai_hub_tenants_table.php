<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `ai_hub_tenants` rows stopped being tenants of the hub.
 *
 * The platform registers no workspace there — Pingly is itself the hub's
 * tenant, and every call goes out under its one token. What is left is a local
 * scope row, so `hub_tenant_id` has nothing to hold for anything created from
 * now on. The unique index has to go with it: were the column ever filled
 * again it would carry the *same* hub tenant id on every row, and uniqueness
 * would reject the second workspace.
 *
 * Existing rows keep the id of the hub tenant they were once given. It is
 * history, not a pointer — nothing reads it any more.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_hub_tenants', function (Blueprint $table) {
            $table->dropUnique('ai_hub_tenants_hub_tenant_id_unique');
        });

        Schema::table('ai_hub_tenants', function (Blueprint $table) {
            $table->string('hub_tenant_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('ai_hub_tenants', function (Blueprint $table) {
            $table->string('hub_tenant_id')->nullable(false)->change();
        });

        Schema::table('ai_hub_tenants', function (Blueprint $table) {
            $table->unique('hub_tenant_id');
        });
    }
};

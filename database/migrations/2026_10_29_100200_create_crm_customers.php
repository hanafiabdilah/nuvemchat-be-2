<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CRM, phase 1: one customer above the channel contacts.
 *
 * A `contacts` row is one identity on one channel — the same person on
 * WhatsApp and on Instagram is two rows, each with its own conversations. A
 * `customers` row is the person: the channel contacts link to it
 * (`contacts.customer_id`), and the inbox, webhooks and campaigns keep working
 * on contacts exactly as before.
 *
 *  - `companies`: the organisation a customer works for.
 *  - `customer_relationships`: one customer to another, with a label
 *    ("spouse", "colleague"), stored once per pair direction.
 *  - `crm_activities`: the timeline — what happened to a customer or company
 *    and who did it (created, a field changed, a contact linked, two
 *    customers merged). Polymorphic so leads and deals write to the same one
 *    later. Conversations are not copied in: the timeline reads them live.
 *  - `crm_custom_fields`: the workspace's own fields per entity; the values
 *    live in each row's `custom_fields` JSON.
 *
 * ⚠️ `contacts.customer_id` is an indexed column WITHOUT a foreign-key
 * constraint, on purpose: adding a constraint to `contacts` makes MySQL copy
 * the whole table while every webhook waits to write to it. Deleting a
 * customer clears the column in code (CustomerService::delete), the one path
 * that removes a customer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('domain')->nullable();
            $table->string('industry')->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('website')->nullable();
            $table->text('address')->nullable();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('custom_fields')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'name']);
        });

        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('email')->nullable();
            // Digits only, country code first — the shape AddressType::Phone
            // normalises to, so a WhatsApp contact matches it exactly.
            $table->string('phone', 32)->nullable();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->string('job_title')->nullable();
            // The person responsible (PIC).
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('new');
            // Where the customer came from: a channel value, `manual`, `import`.
            $table->string('source', 40)->nullable();
            $table->json('custom_fields')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'phone']);
            $table->index(['tenant_id', 'email']);
            $table->index(['tenant_id', 'name']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('customer_relationships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('related_customer_id')->constrained('customers')->cascadeOnDelete();
            $table->string('label', 60)->nullable();
            $table->timestamps();

            $table->unique(['customer_id', 'related_customer_id']);
        });

        Schema::create('crm_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('subject_type', 40);
            $table->unsignedBigInteger('subject_id');
            $table->string('type', 40);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->json('data')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['subject_type', 'subject_id', 'occurred_at']);
            $table->index(['tenant_id', 'occurred_at']);
        });

        Schema::create('crm_custom_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('entity', 20);
            $table->string('key', 64);
            $table->string('label', 100);
            $table->string('type', 20);
            $table->json('options')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'entity', 'key']);
        });

        Schema::table('contacts', function (Blueprint $table) {
            $table->unsignedBigInteger('customer_id')->nullable()->after('tenant_id');
            $table->index('customer_id');
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropIndex(['customer_id']);
            $table->dropColumn('customer_id');
        });

        Schema::dropIfExists('crm_custom_fields');
        Schema::dropIfExists('crm_activities');
        Schema::dropIfExists('customer_relationships');
        Schema::dropIfExists('customers');
        Schema::dropIfExists('companies');
    }
};

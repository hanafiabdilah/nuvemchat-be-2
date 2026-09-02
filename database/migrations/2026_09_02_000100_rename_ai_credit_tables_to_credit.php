<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The wallet stops being an AI wallet.
 *
 * It was built to pay for runs on a rented platform key, and the name said so.
 * It now also pays for API Way instances and trained agent hires, and a table
 * called `ai_credit_wallets` holding the money that buys a WhatsApp number is a
 * name that will mislead every reader after this one — including the one who
 * goes looking for why an instance was not provisioned and never thinks to open
 * an AI table.
 *
 * The two `create` migrations are deliberately left as they were. They describe
 * what the schema was on the day they ran, and a fresh install reaches the same
 * place by creating the old names and renaming them here — the same path every
 * existing install takes. Editing history so that only new databases skip a
 * step is how the two diverge.
 *
 * `reference` is the part that is not cosmetic. Idempotency in this ledger has
 * so far been a typed foreign key — `ai_hub_run_id`, `invoice_id`, each unique —
 * and that shape cannot express "one debit per cycle": a unique key on the
 * subscription would allow a single renewal ever, and no key at all would let a
 * retried command charge the same renewal twice. A free-form unique string can
 * name the thing that must happen once ("apiway:renew:12:2026-10-01") whether or
 * not it has a row of its own anywhere else.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Guarded rather than bare: a database that already carries the new
        // names (a restore taken after this migration, a branch replayed) must
        // not turn a rename into a fatal deploy.
        if (Schema::hasTable('ai_credit_wallets') && ! Schema::hasTable('credit_wallets')) {
            Schema::rename('ai_credit_wallets', 'credit_wallets');
        }

        if (Schema::hasTable('ai_credit_transactions') && ! Schema::hasTable('credit_transactions')) {
            Schema::rename('ai_credit_transactions', 'credit_transactions');
        }

        if (! Schema::hasColumn('credit_transactions', 'reference')) {
            Schema::table('credit_transactions', function (Blueprint $table) {
                // 191 rather than the default 255: this is indexed, and 255 in
                // utf8mb4 overruns MySQL's key length on older row formats.
                //
                // Nullable because the rows already in the table have nothing to
                // put here, and because a debit that is already made unique by
                // one of the two foreign keys does not need a second name.
                $table->string('reference', 191)->nullable()->unique()->after('invoice_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('credit_transactions', 'reference')) {
            Schema::table('credit_transactions', function (Blueprint $table) {
                $table->dropUnique(['reference']);
                $table->dropColumn('reference');
            });
        }

        if (Schema::hasTable('credit_transactions') && ! Schema::hasTable('ai_credit_transactions')) {
            Schema::rename('credit_transactions', 'ai_credit_transactions');
        }

        if (Schema::hasTable('credit_wallets') && ! Schema::hasTable('ai_credit_wallets')) {
            Schema::rename('credit_wallets', 'ai_credit_wallets');
        }
    }
};

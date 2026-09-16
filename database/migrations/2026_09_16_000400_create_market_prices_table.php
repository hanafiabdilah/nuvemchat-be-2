<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What something costs in one country — and, by existing at all, that it is
 * sold there.
 *
 * Two things carry a price the platform sets by hand rather than converts: a
 * plan and a trained agent. Both are priced per country with no exchange rate
 * (the decision behind this whole phase: a plan at R$ 49,90 is not Rp 148.637,
 * it is whatever round number sells in that country), and for both the same
 * question has to be answerable — is this on sale here, and for how much. One
 * row answers both, and a missing row is the honest form of "not sold here":
 * a catalog that quotes a converted number in a market nobody priced is a
 * price nobody agreed to.
 *
 * Polymorphic rather than two tables because the shape is identical and so is
 * every screen that edits it. The currency is copied from the market at write
 * time: it is the market's, fixed with it, and a row that carries its own says
 * what was meant even if a market row is later gone.
 *
 * Backfilled from what is on sale today, so Brazil keeps selling exactly what
 * it sold the minute before this ran.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('market_prices', function (Blueprint $table) {
            $table->id();
            $table->string('priceable_type');
            $table->unsignedBigInteger('priceable_id');
            $table->string('market_code', 2);
            $table->foreign('market_code')->references('code')->on('markets')->cascadeOnDelete();
            $table->unsignedBigInteger('amount_cents')->default(0);
            $table->char('currency', 3);
            $table->timestamps();

            // One price per thing per country. Two would make "the price here"
            // a question with two answers, decided by insertion order.
            $table->unique(['priceable_type', 'priceable_id', 'market_code'], 'market_prices_unique');
            $table->index(['market_code', 'priceable_type']);
        });

        $now = now();

        foreach ([
            ['table' => 'plans', 'type' => \App\Models\Plan::class],
            ['table' => 'trained_agent_blueprints', 'type' => \App\Models\TrainedAgentBlueprint::class],
        ] as $source) {
            DB::table($source['table'])
                ->select('id', 'price_cents', 'currency')
                ->orderBy('id')
                ->chunk(200, function ($rows) use ($source, $now) {
                    $prices = [];

                    foreach ($rows as $row) {
                        $currency = $row->currency ?: 'BRL';

                        // The market this price was always in: the one every
                        // workspace belongs to today.
                        $marketCode = DB::table('markets')->where('currency', $currency)->value('code')
                            ?? DB::table('markets')->value('code');

                        if ($marketCode === null) {
                            continue;
                        }

                        $prices[] = [
                            'priceable_type' => $source['type'],
                            'priceable_id' => $row->id,
                            'market_code' => $marketCode,
                            'amount_cents' => (int) ($row->price_cents ?? 0),
                            'currency' => $currency,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }

                    if ($prices !== []) {
                        DB::table('market_prices')->insert($prices);
                    }
                });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('market_prices');
    }
};

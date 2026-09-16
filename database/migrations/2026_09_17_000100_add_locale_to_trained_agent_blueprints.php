<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What language a trained agent is written in — and therefore who should be
 * offered it.
 *
 * The catalog was already per country (a blueprint with no price in a market is
 * not sold there), but never per language. An Indonesian workspace was shown
 * agents whose system prompt, knowledge and training examples are Portuguese:
 * the sale would complete, the fork would work, and the bot would answer
 * customers in a language the business does not speak.
 *
 * ⚠️ Deliberately its own column rather than the `profile->language` that
 * already exists. Two reasons, and the first is enough: comparing a JSON path
 * behaves differently on MySQL and SQLite, which this codebase has been bitten
 * by before. The second is vocabulary — `profile.language` is free text an
 * admin types for the *model* to read ("pt-BR", "formal Portuguese"), while
 * this has to match `config('markets.locales')` exactly so a market can be
 * joined to a catalog. They answer different questions and are allowed to
 * disagree.
 *
 * Null means "written for everyone" — a blueprint whose material is not tied to
 * a language. Existing rows are NOT null: every one of them is Portuguese, and
 * reading them as language-neutral would keep showing them to Indonesia, which
 * is the bug this column exists to close.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trained_agent_blueprints', function (Blueprint $table) {
            $table->string('locale', 10)->nullable()->after('icon')->index();
        });

        // Seeded from what each blueprint already declares to the model, mapped
        // into the market vocabulary. Done in PHP rather than SQL because the
        // source is a JSON path, which is the portability problem above.
        DB::table('trained_agent_blueprints')
            ->select(['id', 'profile'])
            ->orderBy('id')
            ->chunk(200, function ($rows) {
                foreach ($rows as $row) {
                    $profile = json_decode((string) $row->profile, true);
                    $declared = strtolower((string) ($profile['language'] ?? ''));

                    $locale = match (true) {
                        str_starts_with($declared, 'pt') => 'pt_BR',
                        // Older browsers and some tooling still write Indonesian
                        // as the legacy 'in'.
                        str_starts_with($declared, 'id'), str_starts_with($declared, 'in') => 'id',
                        str_starts_with($declared, 'en') => 'en',
                        // Anything unrecognised — including blank — is Portuguese:
                        // that is what every blueprint written before this column
                        // actually contains.
                        default => 'pt_BR',
                    };

                    DB::table('trained_agent_blueprints')
                        ->where('id', $row->id)
                        ->update(['locale' => $locale]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('trained_agent_blueprints', function (Blueprint $table) {
            $table->dropIndex(['locale']);
            $table->dropColumn('locale');
        });
    }
};

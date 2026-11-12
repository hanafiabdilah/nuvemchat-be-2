<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Drops the `included_instances` quota (API Way) from every place it was stored.
 *
 * API Way instances stopped being something a plan grants a number of: they
 * are free, a WhatsApp via API Way connection gets one when it connects, and
 * `max_whatsapp_connections` is the only limit. Removing the enum case is not
 * enough on its own — the Back Office validates `quotas` against the enum, so
 * a plan still carrying the key would be refused the next time anyone pressed
 * Save on it. Same shape as 2026_09_29_000200 (max_ai_runs).
 *
 * Raw JSON rewrite, row by row: the tables are small, and going through the
 * models would fire observers and bump `updated_at` for a vocabulary cleanup.
 */
return new class extends Migration
{
    private const KEY = 'included_instances';

    public function up(): void
    {
        $this->strip('plans', 'quotas', null);
        $this->strip('subscriptions', 'quotas_snapshot', null);
        $this->strip('tenants', 'entitlement_overrides', 'quotas');
    }

    public function down(): void
    {
        // Irreversible on purpose: nothing reads the quota any more, and
        // guessing the numbers back would hand plans a limit nobody chose.
    }

    private function strip(string $table, string $column, ?string $nested): void
    {
        DB::table($table)
            ->whereNotNull($column)
            ->where($column, 'like', '%'.self::KEY.'%')
            ->orderBy('id')
            ->select(['id', $column])
            ->each(function (object $row) use ($table, $column, $nested) {
                $data = json_decode((string) $row->{$column}, true);

                if (! is_array($data)) {
                    return;
                }

                if ($nested === null) {
                    unset($data[self::KEY]);
                } elseif (is_array($data[$nested] ?? null)) {
                    unset($data[$nested][self::KEY]);
                }

                DB::table($table)->where('id', $row->id)->update([
                    $column => json_encode($data),
                ]);
            });
    }
};

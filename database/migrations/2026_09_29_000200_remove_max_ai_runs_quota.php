<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Drops the `max_ai_runs` quota from every place it was stored.
 *
 * The quota no longer exists: token cost is already covered — by the
 * workspace's own provider key, or by the prepaid balance on a rented key — so
 * a run cap in the plan charged nothing and only stopped customers who had
 * already paid. Removing the enum case is not enough on its own: the Back
 * Office validates `quotas` against the enum, so a plan still carrying the key
 * would be refused the next time anyone pressed Save on it.
 *
 * Raw JSON rewrite, row by row: the tables are small (plans, subscriptions,
 * tenants with an override), and going through the models would fire observers
 * and bump `updated_at` for what is a vocabulary cleanup.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->strip('plans', 'quotas', null);
        $this->strip('subscriptions', 'quotas_snapshot', null);
        $this->strip('tenants', 'entitlement_overrides', 'quotas');
    }

    public function down(): void
    {
        // Irreversible on purpose: the values were never read again, and
        // guessing them back would hand plans a cap nobody chose.
    }

    private function strip(string $table, string $column, ?string $nested): void
    {
        DB::table($table)
            ->whereNotNull($column)
            ->where($column, 'like', '%max_ai_runs%')
            ->orderBy('id')
            ->select(['id', $column])
            ->each(function (object $row) use ($table, $column, $nested) {
                $data = json_decode((string) $row->{$column}, true);

                if (! is_array($data)) {
                    return;
                }

                if ($nested === null) {
                    unset($data['max_ai_runs']);
                } elseif (is_array($data[$nested] ?? null)) {
                    unset($data[$nested]['max_ai_runs']);
                }

                DB::table($table)->where('id', $row->id)->update([
                    $column => json_encode($data),
                ]);
            });
    }
};

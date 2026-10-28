<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Splits the `max_connections` quota into `max_whatsapp_connections` and
 * `max_other_connections` everywhere it is stored.
 *
 * Every stored value becomes BOTH limits, at the old number (an unlimited one
 * stays unlimited on both). That never takes anything away from a workspace
 * that is running: whatever mix of channels it holds today fits each new limit
 * on its own. The real split for the public plans is set afterwards, in the
 * Back Office — a migration has no business choosing what is sold.
 *
 * Rewriting the key is not optional: the Back Office validates `quotas`
 * against the enum, so a plan still carrying `max_connections` would be
 * refused the next time anyone pressed Save on it.
 *
 * While this runs the release still serving reads no `max_connections` and
 * treats connections as unlimited until the new one takes over — a window of
 * seconds, and the permissive direction.
 *
 * Raw JSON rewrite, row by row: the tables are small, and going through the
 * models would fire observers and bump `updated_at` for a vocabulary change.
 */
return new class extends Migration
{
    private const OLD = 'max_connections';

    private const NEW = ['max_whatsapp_connections', 'max_other_connections'];

    public function up(): void
    {
        $this->split('plans', 'quotas', null);
        $this->split('subscriptions', 'quotas_snapshot', null);
        $this->split('tenants', 'entitlement_overrides', 'quotas');
    }

    public function down(): void
    {
        // Irreversible on purpose: two limits cannot be folded back into one
        // without choosing which of them the old total should have been.
    }

    private function split(string $table, string $column, ?string $nested): void
    {
        DB::table($table)
            ->whereNotNull($column)
            ->where($column, 'like', '%'.self::OLD.'%')
            ->orderBy('id')
            ->select(['id', $column])
            ->each(function (object $row) use ($table, $column, $nested) {
                $data = json_decode((string) $row->{$column}, true);

                if (! is_array($data)) {
                    return;
                }

                if ($nested === null) {
                    $data = $this->rewrite($data);
                } elseif (is_array($data[$nested] ?? null)) {
                    $data[$nested] = $this->rewrite($data[$nested]);
                } else {
                    return;
                }

                DB::table($table)->where('id', $row->id)->update([
                    $column => json_encode($data),
                ]);
            });
    }

    /**
     * @param  array<string, mixed>  $quotas
     * @return array<string, mixed>
     */
    private function rewrite(array $quotas): array
    {
        if (! array_key_exists(self::OLD, $quotas)) {
            return $quotas;
        }

        $value = $quotas[self::OLD];
        unset($quotas[self::OLD]);

        foreach (self::NEW as $key) {
            // A value already written under the new name was chosen by someone
            // after this split existed; it wins over the inherited one.
            if (! array_key_exists($key, $quotas)) {
                $quotas[$key] = $value;
            }
        }

        return $quotas;
    }
};

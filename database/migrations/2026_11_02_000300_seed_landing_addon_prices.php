<?php

use App\Models\Plan;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Price the extras of the four public plans in Brazil the way the landing page
 * sells them (nuvemchat-lp-fix/src/lib/plans.ts), monthly and yearly.
 *
 * ⚠️ Matched on the plan's **price and included quotas**, never on its name:
 * production names the R$ 699 plan ELITE and the R$ 1.399 one BLACK, the
 * landing page the other way round. The price is what the customer reads next
 * to the extras, so it is what decides which ladder step a row is.
 *
 * Yearly is the landing page's rule — twelve months minus 20% — the same one
 * the plans' own yearly rows already follow.
 *
 * Only fills rows that have no extras yet: an admin who priced them by hand
 * before this ran is not overwritten. Anything that does not match (a test
 * plan, another country, a fresh install) is left unsold, which is the safe
 * default — no extra is on sale until a price exists for it.
 */
return new class extends Migration
{
    /** monthly plan price => [included agents, included WhatsApp, extra agent, extra WhatsApp] */
    private const LADDER = [
        14990 => [2, 1, 5990, 16990],
        24990 => [4, 2, 5490, 14990],
        69900 => [5, 5, 4990, 8990],
        139900 => [5, 15, 4490, 7990],
    ];

    private const YEARLY_FACTOR = 12 * 0.8;

    public function up(): void
    {
        $rows = DB::table('market_prices')
            ->where('priceable_type', (new Plan)->getMorphClass())
            ->where('market_code', 'BR')
            ->whereNull('addon_cents')
            ->whereIn('billing_cycle', ['monthly', 'yearly'])
            ->get();

        foreach ($rows as $row) {
            $step = $this->stepFor((int) $row->amount_cents, (string) $row->billing_cycle);

            if ($step === null) {
                continue;
            }

            [$agents, $whatsapp, $extraAgent, $extraWhatsapp] = $step;
            $quotas = json_decode((string) DB::table('plans')->where('id', $row->priceable_id)->value('quotas'), true) ?: [];

            if ((int) ($quotas['max_agents'] ?? -1) !== $agents
                || (int) ($quotas['max_whatsapp_connections'] ?? -1) !== $whatsapp) {
                continue;
            }

            $factor = $row->billing_cycle === 'yearly' ? self::YEARLY_FACTOR : 1;

            DB::table('market_prices')->where('id', $row->id)->update([
                'addon_cents' => json_encode([
                    'agents' => (int) round($extraAgent * $factor),
                    'whatsapp_connections' => (int) round($extraWhatsapp * $factor),
                ]),
            ]);
        }
    }

    public function down(): void
    {
        // Prices typed afterwards cannot be told apart from these; leaving them
        // is the only answer that never takes an extra off sale by accident.
    }

    /** @return array{int, int, int, int}|null */
    private function stepFor(int $amountCents, string $cycle): ?array
    {
        foreach (self::LADDER as $monthly => $step) {
            $expected = $cycle === 'yearly' ? (int) round($monthly * self::YEARLY_FACTOR) : $monthly;

            if ($amountCents === $expected) {
                return $step;
            }
        }

        return null;
    }
};

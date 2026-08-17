<?php

namespace App\Services\Lead;

use App\Enums\Lead\StageKind;
use App\Models\LeadPipeline;
use Illuminate\Support\Facades\DB;

/**
 * Gives a tenant a funnel the first time they need one.
 *
 * Lazily rather than in a migration that loops every tenant: this way a
 * workspace created tomorrow is handled by the same code path as one that
 * existed before the feature shipped, and a tenant who never opens the board
 * never gets rows they did not ask for.
 */
class PipelineProvisioner
{
    /**
     * The funnel a Brazilian SMB selling over WhatsApp actually runs, in the
     * language the dashboard is in. Deliberately short: a board nobody can hold
     * in their head stops being dragged, and every column past the fourth is a
     * column that quietly fills with cards nobody revisits.
     *
     * Note there is no "frio / morno / quente" column. That is the temperature
     * axis, and it belongs on the card, not in the layout — see Temperature.
     */
    private const DEFAULT_STAGES = [
        ['name' => 'Novo contato', 'color' => 'slate', 'kind' => StageKind::Open],
        ['name' => 'Qualificação', 'color' => 'blue', 'kind' => StageKind::Open],
        ['name' => 'Proposta', 'color' => 'violet', 'kind' => StageKind::Open],
        ['name' => 'Negociação', 'color' => 'amber', 'kind' => StageKind::Open],
        ['name' => 'Cliente', 'color' => 'green', 'kind' => StageKind::Won],
        ['name' => 'Perdido', 'color' => 'red', 'kind' => StageKind::Lost],
    ];

    public function ensureDefault(int $tenantId): LeadPipeline
    {
        $existing = LeadPipeline::where('tenant_id', $tenantId)
            ->orderByDesc('is_default')
            ->orderBy('position')
            ->first();

        if ($existing) {
            return $existing;
        }

        return DB::transaction(function () use ($tenantId) {
            $pipeline = LeadPipeline::create([
                'tenant_id' => $tenantId,
                'name' => 'Vendas',
                'is_default' => true,
                'position' => 0,
            ]);

            foreach (self::DEFAULT_STAGES as $position => $stage) {
                $pipeline->stages()->create([
                    'name' => $stage['name'],
                    'color' => $stage['color'],
                    'kind' => $stage['kind'],
                    'position' => $position,
                ]);
            }

            return $pipeline->fresh('stages');
        });
    }
}

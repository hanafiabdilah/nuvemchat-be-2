<?php

namespace App\Services\Lead;

use App\Enums\Lead\StageKind;
use App\Models\LeadPipeline;
use App\Models\Tenant;
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
     *
     * "Atendidos" is the one column nobody drags into: a card lands there by
     * itself the first time someone from the team answers (LeadAttendance).
     * Without it "Novo contato" held every contact who ever wrote, answered or
     * not, and stopped telling anyone who still needed a first reply.
     */
    public const ATTENDED_STAGE_NAME = 'Atendidos';

    private const DEFAULT_STAGES = [
        ['name' => 'Novo contato', 'color' => 'slate', 'kind' => StageKind::Open],
        ['name' => self::ATTENDED_STAGE_NAME, 'color' => 'cyan', 'kind' => StageKind::Open],
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

            $this->pointAttendedRuleAt($tenantId, $pipeline);

            return $pipeline->fresh('stages');
        });
    }

    /**
     * Switch the "answered → Atendidos" rule on for a brand-new funnel.
     *
     * Only when the workspace never said anything about it: a key that exists,
     * even as null, is somebody's decision and is left alone.
     */
    private function pointAttendedRuleAt(int $tenantId, LeadPipeline $pipeline): void
    {
        $tenant = Tenant::find($tenantId);
        $stage = $pipeline->stages()->where('name', self::ATTENDED_STAGE_NAME)->first();

        if (! $tenant || ! $stage || array_key_exists('attended_stage_id', $tenant->lead_settings ?? [])) {
            return;
        }

        $tenant->forceFill([
            'lead_settings' => array_merge($tenant->lead_settings ?? [], ['attended_stage_id' => $stage->id]),
        ])->save();
    }
}

<?php

namespace App\Services\Crm;

use App\Enums\Crm\StageKind;
use App\Models\CrmPipeline;
use App\Models\CrmPipelineStage;
use App\Models\Deal;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The boards deals move along, and the rules that keep them usable.
 *
 * A workspace never has to set one up before its first deal: the default
 * pipeline is created the first time anything asks for pipelines. And a board
 * always has somewhere to win and somewhere to lose — removing the last Won
 * or Lost stage is refused, or a deal could never be closed.
 */
class CrmPipelines
{
    public const MAX_PIPELINES = 20;

    public const MAX_STAGES = 20;

    /**
     * The starting board, in the platform's language: four open stages with
     * rising probability, then Won and Lost.
     *
     * @return list<array{name: string, probability: int, kind: string}>
     */
    public static function defaultStages(): array
    {
        return [
            ['name' => __('crm.pipelines.default_stages.new'), 'probability' => 10, 'kind' => StageKind::Open->value],
            ['name' => __('crm.pipelines.default_stages.qualified'), 'probability' => 25, 'kind' => StageKind::Open->value],
            ['name' => __('crm.pipelines.default_stages.proposal'), 'probability' => 50, 'kind' => StageKind::Open->value],
            ['name' => __('crm.pipelines.default_stages.negotiation'), 'probability' => 75, 'kind' => StageKind::Open->value],
            ['name' => __('crm.pipelines.default_stages.won'), 'probability' => 100, 'kind' => StageKind::Won->value],
            ['name' => __('crm.pipelines.default_stages.lost'), 'probability' => 0, 'kind' => StageKind::Lost->value],
        ];
    }

    /**
     * The workspace's pipelines with their stages, creating the default one
     * the first time.
     *
     * @return Collection<int, CrmPipeline>
     */
    public static function forTenant(int $tenantId): Collection
    {
        self::ensureDefault($tenantId);

        return CrmPipeline::query()
            ->where('tenant_id', $tenantId)
            ->with('stages')
            ->orderBy('position')
            ->orderBy('id')
            ->get();
    }

    public static function ensureDefault(int $tenantId): CrmPipeline
    {
        $existing = CrmPipeline::query()->where('tenant_id', $tenantId)->orderByDesc('is_default')->orderBy('position')->first();

        if ($existing) {
            return $existing;
        }

        return Cache::lock("crm:pipelines:{$tenantId}", 10)->block(5, function () use ($tenantId) {
            $existing = CrmPipeline::query()->where('tenant_id', $tenantId)->first();

            if ($existing) {
                return $existing;
            }

            return self::create($tenantId, __('crm.pipelines.default_name'), self::defaultStages(), isDefault: true);
        });
    }

    /** @param  list<array{name: string, probability?: int, kind?: string, color?: string|null}>  $stages */
    public static function create(int $tenantId, string $name, array $stages, bool $isDefault = false): CrmPipeline
    {
        if (CrmPipeline::query()->where('tenant_id', $tenantId)->count() >= self::MAX_PIPELINES) {
            throw ValidationException::withMessages(['name' => __('crm.pipelines.too_many', ['max' => self::MAX_PIPELINES])]);
        }

        return DB::transaction(function () use ($tenantId, $name, $stages, $isDefault) {
            $pipeline = CrmPipeline::create([
                'tenant_id' => $tenantId,
                'name' => $name,
                'is_default' => $isDefault,
                'position' => (int) CrmPipeline::query()->where('tenant_id', $tenantId)->max('position') + 1,
            ]);

            foreach (array_values($stages ?: self::defaultStages()) as $index => $stage) {
                CrmPipelineStage::create([
                    'tenant_id' => $tenantId,
                    'pipeline_id' => $pipeline->id,
                    'name' => $stage['name'],
                    'probability' => (int) ($stage['probability'] ?? 0),
                    'kind' => $stage['kind'] ?? StageKind::Open->value,
                    'color' => $stage['color'] ?? null,
                    'position' => $index,
                ]);
            }

            self::assertClosable($pipeline);

            return $pipeline->load('stages');
        });
    }

    /** The first open stage — where a new deal lands when none is chosen. */
    public static function firstOpenStage(CrmPipeline $pipeline): CrmPipelineStage
    {
        $stage = $pipeline->stages()->where('kind', StageKind::Open->value)->first() ?? $pipeline->stages()->first();

        if (! $stage) {
            throw ValidationException::withMessages(['pipeline_id' => __('crm.pipelines.no_stages')]);
        }

        return $stage;
    }

    /**
     * Remove a stage. Its deals move to `$moveTo` (a stage of the same
     * pipeline); without one, a stage that still holds deals is refused.
     */
    public static function deleteStage(CrmPipelineStage $stage, ?CrmPipelineStage $moveTo): void
    {
        $count = Deal::query()->where('stage_id', $stage->id)->count();

        if ($count > 0 && ! $moveTo) {
            throw ValidationException::withMessages(['stage' => __('crm.pipelines.stage_has_deals', ['count' => $count])]);
        }

        if ($moveTo && ($moveTo->pipeline_id !== $stage->pipeline_id || $moveTo->id === $stage->id)) {
            throw ValidationException::withMessages(['move_to' => __('crm.pipelines.move_to_invalid')]);
        }

        DB::transaction(function () use ($stage, $moveTo) {
            if ($moveTo) {
                Deal::query()->where('stage_id', $stage->id)->update([
                    'stage_id' => $moveTo->id,
                    'status' => $moveTo->kind->value,
                ]);
            }

            $pipeline = $stage->pipeline;
            $stage->delete();
            self::assertClosable($pipeline);
        });
    }

    /**
     * A pipeline must keep one Won and one Lost stage — without them a deal
     * on it could never be closed.
     */
    public static function assertClosable(CrmPipeline $pipeline): void
    {
        $kinds = $pipeline->stages()->pluck('kind')->map(fn ($kind) => $kind instanceof StageKind ? $kind->value : $kind);

        if (! $kinds->contains(StageKind::Won->value) || ! $kinds->contains(StageKind::Lost->value) || ! $kinds->contains(StageKind::Open->value)) {
            throw ValidationException::withMessages(['stages' => __('crm.pipelines.needs_outcomes')]);
        }
    }
}

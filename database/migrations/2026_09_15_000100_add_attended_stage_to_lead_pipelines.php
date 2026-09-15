<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Gives every existing funnel an "Atendidos" column right after the first one,
 * and points the "answered → stage" rule at it.
 *
 * New funnels get it from PipelineProvisioner; this is for the ones created
 * before. The dashboard has no way to add a stage, so without this an existing
 * workspace could never switch the rule on.
 *
 * Only the default funnel (the one new cards are opened in). A column already
 * called Atendido/Atendidos is reused rather than duplicated, and a workspace
 * whose settings already mention the rule — even as null — is left as it is.
 * Moving the cards already sitting in "Novo contato" is a separate, reviewable
 * step: `php artisan leads:backfill-attended --dry-run`.
 *
 * Written against the query builder, not the models, so it keeps producing the
 * same result after those change.
 */
return new class extends Migration
{
    private const NAME = 'Atendidos';

    public function up(): void
    {
        DB::table('tenants')->select(['id', 'lead_settings'])->orderBy('id')
            ->chunkById(200, function ($tenants) {
                foreach ($tenants as $tenant) {
                    $this->addTo($tenant);
                }
            });
    }

    public function down(): void
    {
        // The column stays: it may hold cards by now, and removing it would
        // need somewhere to move them. Only the rule is switched off.
        DB::table('tenants')->select(['id', 'lead_settings'])->orderBy('id')
            ->chunkById(200, function ($tenants) {
                foreach ($tenants as $tenant) {
                    $settings = json_decode((string) $tenant->lead_settings, true);

                    if (is_array($settings) && array_key_exists('attended_stage_id', $settings)) {
                        unset($settings['attended_stage_id']);
                        DB::table('tenants')->where('id', $tenant->id)->update(['lead_settings' => json_encode($settings)]);
                    }
                }
            });
    }

    private function addTo(object $tenant): void
    {
        $pipeline = DB::table('lead_pipelines')
            ->where('tenant_id', $tenant->id)
            ->orderByDesc('is_default')
            ->orderBy('position')
            ->first();

        if (! $pipeline) {
            return;
        }

        $stageId = DB::table('lead_stages')
            ->where('pipeline_id', $pipeline->id)
            ->whereRaw('LOWER(name) IN (?, ?)', ['atendido', 'atendidos'])
            ->value('id');

        if (! $stageId) {
            $first = DB::table('lead_stages')
                ->where('pipeline_id', $pipeline->id)
                ->where('kind', 'open')
                ->orderBy('position')
                ->first();

            if (! $first) {
                return;
            }

            $stageId = DB::transaction(function () use ($pipeline, $first) {
                DB::table('lead_stages')
                    ->where('pipeline_id', $pipeline->id)
                    ->where('position', '>', $first->position)
                    ->increment('position');

                return DB::table('lead_stages')->insertGetId([
                    'pipeline_id' => $pipeline->id,
                    'name' => self::NAME,
                    'color' => 'cyan',
                    'kind' => 'open',
                    'position' => $first->position + 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
        }

        $settings = json_decode((string) $tenant->lead_settings, true);
        $settings = is_array($settings) ? $settings : [];

        if (! array_key_exists('attended_stage_id', $settings)) {
            $settings['attended_stage_id'] = (int) $stageId;
            DB::table('tenants')->where('id', $tenant->id)->update(['lead_settings' => json_encode($settings)]);
        }
    }
};

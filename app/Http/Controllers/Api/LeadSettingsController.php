<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\Tenant;
use App\Services\Lead\LeadAttendance;
use App\Services\Lead\LeadSettings;
use App\Services\Lead\PipelineProvisioner;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * How this workspace wants its funnel to behave.
 *
 * Behind `lead-pipelines.manage` rather than `leads.update`: deciding that
 * everyone's untouched cards get retired after 21 days is a policy for the
 * whole board, not a thing an agent does to one lead.
 */
class LeadSettingsController extends Controller
{
    public function __construct(
        private PipelineProvisioner $pipelines,
        private LeadAttendance $attendance,
    ) {}

    public function show(Request $request)
    {
        $tenant = $request->user()->tenant;

        // The dialog lists the stages the attended rule can point at, so the
        // funnel has to exist; creating it also switches that rule on.
        $this->pipelines->ensureDefault($tenant->id);
        $tenant->refresh();

        return response()->json(['data' => $this->payload($tenant, LeadSettings::for($tenant))]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'auto_create' => ['sometimes', 'boolean'],
            'auto_close_enabled' => ['sometimes', 'boolean'],
            'auto_close_days' => [
                'sometimes',
                'integer',
                'min:'.LeadSettings::MIN_AUTO_CLOSE_DAYS,
                'max:'.LeadSettings::MAX_AUTO_CLOSE_DAYS,
            ],
            'auto_close_engaged' => ['sometimes', 'boolean'],
            'attended_stage_id' => ['sometimes', 'nullable', 'integer'],
        ]);

        $tenant = $request->user()->tenant;

        if (($data['attended_stage_id'] ?? null) !== null
            && ! $this->attendance->selectableStage($tenant, (int) $data['attended_stage_id'])) {
            throw ValidationException::withMessages([
                'attended_stage_id' => 'Escolha uma etapa aberta do funil que venha depois da primeira.',
            ]);
        }

        // Merge rather than replace: the dialog may only be showing one of
        // these knobs, and a partial save must not silently reset the rest.
        $settings = LeadSettings::fromArray(
            array_merge(LeadSettings::for($tenant)->toArray(), $data)
        );

        $tenant->forceFill(['lead_settings' => $settings->toArray()])->save();

        return response()->json(['data' => $this->payload($tenant, $settings)]);
    }

    /** @return array<string, mixed> */
    private function payload(Tenant $tenant, LeadSettings $settings): array
    {
        return $settings->toArray() + [
            // What the current window would actually retire, so the dialog
            // can say "23 leads" instead of asking someone to guess what
            // they are agreeing to.
            'auto_close_preview' => $this->previewCount($tenant->id, $settings),
            'attended_stage_options' => $this->attendance->stageOptions($tenant),
            'limits' => [
                'min_days' => LeadSettings::MIN_AUTO_CLOSE_DAYS,
                'max_days' => LeadSettings::MAX_AUTO_CLOSE_DAYS,
            ],
        ];
    }

    /**
     * How many open leads the current window covers.
     *
     * Deliberately a rough count rather than a re-implementation of the
     * command's exact query: it exists to give the number a sense of scale
     * before someone commits, and the sweep itself is reversible with one drag.
     */
    private function previewCount(int $tenantId, LeadSettings $settings): int
    {
        if (! $settings->autoCloseEnabled) {
            return 0;
        }

        $cutoff = now()->subDays($settings->autoCloseDays);

        return Lead::where('tenant_id', $tenantId)
            ->open()
            ->where('created_at', '<', $cutoff)
            ->where(fn ($q) => $q->whereNull('last_inbound_at')->orWhere('last_inbound_at', '<', $cutoff))
            ->where(fn ($q) => $q->whereNull('stage_changed_at')->orWhere('stage_changed_at', '<', $cutoff))
            ->count();
    }
}

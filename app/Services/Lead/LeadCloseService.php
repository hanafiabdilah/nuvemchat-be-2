<?php

namespace App\Services\Lead;

use App\Enums\Lead\LeadStatus;
use App\Enums\Lead\StageKind;
use App\Events\LeadUpdated;
use App\Exceptions\PublicApiException;
use App\Models\Lead;
use App\Models\LeadIntake;
use App\Models\LeadStage;
use App\Models\Tenant;
use Illuminate\Support\Facades\Log;

/**
 * POST /v1/leads/close — the system that sent a lead says how it ended.
 *
 * Found by the `reference` the lead was sent with, so the caller never needs
 * Pingly's ids. Won or lost moves the card to its funnel's first won/lost
 * stage through Lead::moveToStage, the same path a drag takes — the history
 * row and the lead.won / lead.lost webhook come from there.
 *
 * Safe to retry: closing a lead the way it is already closed answers 200 and
 * only fills in a value or reason that was sent. Closing it the other way is
 * refused (409) — flipping a won sale to lost is a decision for a person on
 * the board, not for a retry.
 */
final class LeadCloseService
{
    /**
     * @param  array{reference: string, status: string, value?: float|int|string|null, lost_reason?: string|null}  $data
     * @return array{lead: Lead, changed: bool, reference: string}
     */
    public function close(Tenant $tenant, array $data): array
    {
        $reference = trim($data['reference']);
        $leadId = LeadIntake::where('tenant_id', $tenant->id)->where('reference', $reference)->value('lead_id');
        $lead = $leadId ? Lead::find($leadId) : null;

        if (! $lead) {
            throw new PublicApiException('Nenhum lead encontrado com esta reference.', 'lead_not_found', 404);
        }

        $status = LeadStatus::from($data['status']);
        $value = isset($data['value']) && $data['value'] !== '' ? round((float) $data['value'], 2) : null;
        $reason = trim((string) ($data['lost_reason'] ?? '')) ?: null;

        if ($lead->status === $status) {
            $lead->fill(array_filter([
                'value' => $value,
                'lost_reason' => $status === LeadStatus::Lost ? $reason : null,
            ], fn ($field) => $field !== null));

            $changed = $lead->isDirty();
            $lead->save();

            return ['lead' => $lead->fresh('stage'), 'changed' => $changed, 'reference' => $reference];
        }

        if ($lead->status !== LeadStatus::Open) {
            throw new PublicApiException(
                "Este lead já foi fechado como {$lead->status->value}. Para mudar o resultado, mova o cartão no Pingly.",
                'lead_already_closed',
                409,
                ['status' => $lead->status->value],
            );
        }

        $stage = LeadStage::where('pipeline_id', $lead->pipeline_id)
            ->where('kind', $status === LeadStatus::Won ? StageKind::Won : StageKind::Lost)
            ->orderBy('position')
            ->first();

        if (! $stage) {
            throw new PublicApiException(
                'O funil deste lead não tem uma etapa de '.($status === LeadStatus::Won ? 'ganho' : 'perda').'.',
                'no_closing_stage',
                422,
            );
        }

        // Saved together with the move, so the lead.won event already carries it.
        if ($value !== null) {
            $lead->forceFill(['value' => $value]);
        }

        $lead->moveToStage($stage, null, $reason);

        try {
            broadcast(new LeadUpdated($lead));
        } catch (\Throwable $e) {
            Log::warning('Lead closed through the API but could not be broadcast', ['lead_id' => $lead->id, 'error' => $e->getMessage()]);
        }

        return ['lead' => $lead->fresh('stage'), 'changed' => true, 'reference' => $reference];
    }
}

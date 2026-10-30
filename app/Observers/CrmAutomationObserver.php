<?php

namespace App\Observers;

use App\Enums\Crm\AutomationTrigger;
use App\Enums\Crm\StageKind;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\Lead;
use App\Services\Crm\Automation\CrmAutomations;
use Illuminate\Database\Eloquent\Model;

/**
 * Turns saves of leads, deals and customers into CRM automation triggers.
 *
 * On the models rather than in the services so that every path counts the
 * same — the board, a bulk action, an import, a flow, lead conversion. Status
 * and stage are read from what the save actually changed.
 */
class CrmAutomationObserver
{
    public function created(Model $model): void
    {
        match (true) {
            $model instanceof Lead => CrmAutomations::fire(AutomationTrigger::LeadCreated, $model),
            $model instanceof Deal => CrmAutomations::fire(AutomationTrigger::DealCreated, $model),
            default => null,
        };
    }

    public function updated(Model $model): void
    {
        if ($model instanceof Lead && $model->wasChanged('status')) {
            CrmAutomations::fire(AutomationTrigger::LeadStatusChanged, $model, ['to' => $model->status?->value]);
        }

        if ($model instanceof Deal) {
            if ($model->wasChanged('stage_id')) {
                CrmAutomations::fire(AutomationTrigger::DealStageChanged, $model);
            }

            if ($model->wasChanged('status')) {
                match ($model->status) {
                    StageKind::Won->value => CrmAutomations::fire(AutomationTrigger::DealWon, $model),
                    StageKind::Lost->value => CrmAutomations::fire(AutomationTrigger::DealLost, $model),
                    default => null,
                };
            }
        }

        if ($model instanceof Customer && $model->wasChanged('status')) {
            CrmAutomations::fire(AutomationTrigger::CustomerStatusChanged, $model, ['to' => $model->status?->value]);
        }
    }
}

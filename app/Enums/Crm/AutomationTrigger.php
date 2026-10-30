<?php

namespace App\Enums\Crm;

/**
 * What starts a CRM automation.
 *
 * Two kinds. Most fire the moment a record changes (a lead is created, a deal
 * moves stage) — read off the models themselves, so a change made from the
 * board, a bulk action, an import or a flow all count the same. The scanned
 * ones are about time passing with nothing happening ("a deal sat in its
 * stage for 7 days"), which no save ever announces; `crm:automations-scan`
 * looks for those.
 */
enum AutomationTrigger: string
{
    case LeadCreated = 'lead_created';
    case LeadStatusChanged = 'lead_status_changed';
    case DealCreated = 'deal_created';
    case DealStageChanged = 'deal_stage_changed';
    case DealWon = 'deal_won';
    case DealLost = 'deal_lost';
    case CustomerStatusChanged = 'customer_status_changed';
    /** An open deal has stayed in its stage for N days. */
    case DealStale = 'deal_stale';
    /** An open lead has seen no change and no logged activity for N days. */
    case LeadIdle = 'lead_idle';
    /** A task passed its due time without being completed. */
    case TaskOverdue = 'task_overdue';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $trigger) => $trigger->value, self::cases());
    }

    /** The kind of record it is about: lead | deal | customer | task. */
    public function subject(): string
    {
        return match ($this) {
            self::LeadCreated, self::LeadStatusChanged, self::LeadIdle => 'lead',
            self::DealCreated, self::DealStageChanged, self::DealWon, self::DealLost, self::DealStale => 'deal',
            self::CustomerStatusChanged => 'customer',
            self::TaskOverdue => 'task',
        };
    }

    /** Found by the scan rather than announced by a save. */
    public function scanned(): bool
    {
        return in_array($this, [self::DealStale, self::LeadIdle, self::TaskOverdue], true);
    }

    /** Takes a number of days ("for 7 days"). */
    public function takesDays(): bool
    {
        return in_array($this, [self::DealStale, self::LeadIdle], true);
    }
}

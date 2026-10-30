<?php

namespace App\Services\Crm\Automation;

use App\Enums\Crm\AutomationRunStatus;
use App\Enums\Crm\AutomationTrigger;
use App\Enums\Crm\LeadStatus;
use App\Enums\Crm\StageKind;
use App\Jobs\Crm\RunCrmAutomation;
use App\Models\CrmAutomation;
use App\Models\CrmAutomationRun;
use App\Models\CrmTask;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\Tenant;
use App\Services\Billing\SubscriptionGate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Where a CRM automation starts: a record changed (`fire`, called from the
 * models' observer, so a change made anywhere counts) or time passed
 * (`scan`, from `crm:automations-scan`).
 *
 * Either way the work is the same: a run row is written — the history entry
 * — and RunCrmAutomation does the acting, after the change that triggered it
 * has been committed.
 *
 * Changes an automation makes do not start automations. Without that, "deal
 * moved → move it to the next stage" runs until something breaks, and two
 * automations can feed each other forever; the cost is that one automation
 * cannot chain into another, which the editor says.
 */
final class CrmAutomations
{
    /** How many records one automation acts on in one scan pass. */
    public const SCAN_LIMIT = 200;

    private static int $muted = 0;

    /** Run `$work` with automations not reacting to the changes it makes. */
    public static function muted(callable $work): mixed
    {
        self::$muted++;

        try {
            return $work();
        } finally {
            self::$muted--;
        }
    }

    /**
     * A record changed in a way some automation may be waiting for.
     *
     * Never throws: the save that announced it has already happened, and an
     * automation that cannot start is no reason to fail it.
     *
     * @param  array<string, mixed>  $context  e.g. ['to' => 'qualified']
     */
    public static function fire(AutomationTrigger $trigger, Model $subject, array $context = []): void
    {
        if (self::$muted > 0) {
            return;
        }

        try {
            $tenantId = (int) $subject->getAttribute('tenant_id');

            $automations = CrmAutomation::query()
                ->where('tenant_id', $tenantId)
                ->where('trigger', $trigger->value)
                ->where('is_active', true)
                ->get();

            if ($automations->isEmpty() || ! self::enabledFor($tenantId)) {
                return;
            }

            foreach ($automations as $automation) {
                if (! self::configMatches($automation, $subject, $context)
                    || ! AutomationConditions::matches($subject, $automation->match, $automation->conditions ?? [])) {
                    continue;
                }

                self::queue($automation, $subject, null);
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * The time-based triggers: records that crossed an automation's "for N
     * days" since the last pass. Each record is claimed once per automation
     * and per occurrence (a deal's stay in one stage, a task's due time), so
     * passing over it again never repeats it.
     *
     * @return int runs queued
     */
    public static function scan(?int $tenantId = null): int
    {
        $queued = 0;

        $automations = CrmAutomation::query()
            ->where('is_active', true)
            ->whereIn('trigger', array_map(fn (AutomationTrigger $t) => $t->value, array_filter(AutomationTrigger::cases(), fn ($t) => $t->scanned())))
            ->when($tenantId, fn (Builder $q) => $q->where('tenant_id', $tenantId))
            ->get();

        foreach ($automations->groupBy('tenant_id') as $tenant => $group) {
            if (! self::enabledFor((int) $tenant)) {
                continue;
            }

            foreach ($group as $automation) {
                try {
                    $queued += self::scanOne($automation);
                } catch (Throwable $e) {
                    report($e);
                }
            }
        }

        return $queued;
    }

    private static function scanOne(CrmAutomation $automation): int
    {
        $queued = 0;
        $config = $automation->trigger_config ?? [];

        foreach (self::due($automation)->limit(self::SCAN_LIMIT * 2)->get() as $subject) {
            if ($queued >= self::SCAN_LIMIT) {
                break;
            }

            if (! AutomationConditions::matches($subject, $automation->match, $automation->conditions ?? [])) {
                continue;
            }

            if (self::queue($automation, $subject, self::occurrence($automation->trigger, $subject))) {
                $queued++;
            }
        }

        return $queued;
    }

    /** The records a scanned trigger is currently true for. */
    public static function due(CrmAutomation $automation): Builder
    {
        $config = $automation->trigger_config ?? [];
        $days = (int) ($config['days'] ?? 0);

        return match ($automation->trigger) {
            AutomationTrigger::DealStale => Deal::query()
                ->where('tenant_id', $automation->tenant_id)
                ->where('status', StageKind::Open->value)
                ->where('stage_entered_at', '<=', now()->subDays($days))
                ->when($config['pipeline_id'] ?? null, fn (Builder $q, $pipeline) => $q->where('pipeline_id', $pipeline))
                ->when($config['stage_ids'] ?? null, fn (Builder $q, $stages) => $q->whereIn('stage_id', $stages))
                ->orderBy('id'),
            AutomationTrigger::LeadIdle => Lead::query()
                ->where('tenant_id', $automation->tenant_id)
                ->whereIn('status', self::openLeadStatuses())
                ->where('updated_at', '<=', now()->subDays($days))
                // A logged call or note is a sign of life the lead row never sees.
                ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('crm_activities')
                    ->whereColumn('crm_activities.subject_id', 'crm_leads.id')
                    ->where('crm_activities.subject_type', 'lead')
                    ->where('crm_activities.occurred_at', '>', now()->subDays($days)))
                ->orderBy('id'),
            AutomationTrigger::TaskOverdue => CrmTask::query()
                ->where('tenant_id', $automation->tenant_id)
                ->whereNull('completed_at')
                ->whereNotNull('due_at')
                ->where('due_at', '<=', now())
                ->orderBy('id'),
            default => Lead::query()->whereRaw('1 = 0'),
        };
    }

    /**
     * Which occurrence of the condition this is — what makes it fire once:
     * a deal's stay in a stage, a lead's quiet spell since its last change,
     * a task's due time.
     */
    public static function occurrence(AutomationTrigger $trigger, Model $subject): string
    {
        return match ($trigger) {
            AutomationTrigger::DealStale => 'deal:'.$subject->getKey().':'.$subject->getAttribute('stage_entered_at')?->getTimestamp(),
            AutomationTrigger::LeadIdle => 'lead:'.$subject->getKey().':'.$subject->getAttribute('updated_at')?->getTimestamp(),
            AutomationTrigger::TaskOverdue => 'task:'.$subject->getKey().':'.$subject->getAttribute('due_at')?->getTimestamp(),
            default => AutomationSubjects::typeOf($subject).':'.$subject->getKey(),
        };
    }

    /**
     * Write the history row and hand the work to the queue. A scanned
     * occurrence already claimed by an earlier pass is refused by the
     * database, not by a check that two passes could both get past.
     */
    private static function queue(CrmAutomation $automation, Model $subject, ?string $dedupeKey): bool
    {
        try {
            $run = CrmAutomationRun::create([
                'tenant_id' => $automation->tenant_id,
                'automation_id' => $automation->id,
                'trigger' => $automation->trigger->value,
                'subject_type' => AutomationSubjects::typeOf($subject),
                'subject_id' => $subject->getKey(),
                'subject_title' => mb_substr(AutomationSubjects::titleOf($subject), 0, 255),
                'customer_id' => AutomationSubjects::customerOf($subject)?->id,
                'dedupe_key' => $dedupeKey,
                'status' => AutomationRunStatus::Queued->value,
            ]);
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        RunCrmAutomation::dispatch($run->id)->afterCommit();

        return true;
    }

    /** @param  array<string, mixed>  $context */
    private static function configMatches(CrmAutomation $automation, Model $subject, array $context): bool
    {
        $config = $automation->trigger_config ?? [];

        if (! empty($config['to']) && ! in_array($context['to'] ?? null, $config['to'], true)) {
            return false;
        }

        if (! empty($config['pipeline_id']) && (int) $subject->getAttribute('pipeline_id') !== (int) $config['pipeline_id']) {
            return false;
        }

        if (! empty($config['stage_ids']) && ! in_array((int) $subject->getAttribute('stage_id'), array_map('intval', $config['stage_ids']), true)) {
            return false;
        }

        return true;
    }

    /** CRM automations are part of the `crm` plan feature. */
    public static function enabledFor(int $tenantId): bool
    {
        if (! config('services.billing.enforce')) {
            return true;
        }

        $tenant = Tenant::find($tenantId);

        return $tenant !== null && app(SubscriptionGate::class)->feature($tenant, 'crm');
    }

    /** @return list<string> */
    private static function openLeadStatuses(): array
    {
        return array_values(array_filter(LeadStatus::values(), fn ($status) => LeadStatus::from($status)->isOpen()));
    }
}

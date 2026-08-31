<?php

namespace App\Enums\Billing;

/**
 * Canonical numeric quota keys. Absent or null always means unlimited.
 *
 * Same contract as Feature: this enum is the list, the Back Office plan editor
 * renders whatever is here, and AdminPlanController refuses anything else. A
 * quota nobody enforces is worse than no quota — it gets sold and then silently
 * ignored — so `enforced()` states, per key, where the check actually lives.
 */
enum Quota: string
{
    case MaxConnections = 'max_connections';
    case MaxAgents = 'max_agents';
    case MaxAiRuns = 'max_ai_runs';
    case IncludedInstances = 'included_instances';
    case IncludedTrainedAgents = 'included_trained_agents';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $q) => $q->value, self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::MaxConnections => 'Connections',
            self::MaxAgents => 'Agents',
            self::MaxAiRuns => 'AI runs / month',
            self::IncludedInstances => 'Included API Way instances',
            self::IncludedTrainedAgents => 'Included trained agents',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::MaxConnections => 'Channels the workspace may connect at once.',
            self::MaxAgents => 'User seats in the workspace.',
            self::MaxAiRuns => 'AI Hub runs per billing month. Resets with the subscription period.',
            self::IncludedInstances => 'API Way instances provisioned free with the plan.',
            self::IncludedTrainedAgents => 'Pre-trained catalog agents the plan may hire at no extra cost.',
        };
    }

    /** Where the limit is actually applied, for the editor to show honestly. */
    public function enforcedAt(): string
    {
        return match ($this) {
            self::MaxConnections => 'On connecting a new channel.',
            self::MaxAgents => 'On inviting a new agent.',
            self::MaxAiRuns => 'On each AI agent run; over the limit, the flow hands off instead.',
            self::IncludedInstances => 'On provisioning; extra instances are billed per unit.',
            self::IncludedTrainedAgents => 'On hiring from the catalog; past the limit the agent is a one-off purchase.',
        };
    }
}

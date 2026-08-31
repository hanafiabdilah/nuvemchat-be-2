<?php

namespace App\Enums\TrainedAgent;

/**
 * How a tenant came to own a trained agent.
 *
 * The distinction is a billing one, not a functional one: both end up as an
 * ordinary, fully editable AiHubAgent. `included` spends one slot of the plan's
 * `included_trained_agents` quota, `purchased` was paid for once and is
 * therefore permanent regardless of what the plan later says.
 */
enum HireSource: string
{
    case Included = 'included';
    case Purchased = 'purchased';

    public function label(): string
    {
        return match ($this) {
            self::Included => 'Included in plan',
            self::Purchased => 'Purchased',
        };
    }
}

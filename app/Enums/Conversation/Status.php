<?php

namespace App\Enums\Conversation;

enum Status: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Resolved = 'resolved';

    /**
     * AI (flow agent) is currently handling the conversation. When the AI stops
     * handling (handoff / flow end) the conversation moves back to Pending.
     */
    case AiHandling = 'ai_handling';

    /**
     * Statuses in which the automation flow / AI is allowed to run. Both the
     * unassigned Pending queue and an active AI turn keep the flow engine live.
     *
     * @return array<int, self>
     */
    public static function flowEligible(): array
    {
        return [self::Pending, self::AiHandling];
    }
}

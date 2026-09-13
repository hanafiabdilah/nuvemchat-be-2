<?php

namespace App\Enums\Conversation;

enum Status: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Resolved = 'resolved';

    /**
     * An AI Agent flow node is serving the conversation: set by FlowExecutor
     * when the node starts answering (never for groups or e-mail, never when the
     * node sends the customer straight to a person). When the AI stops serving
     * — handoff for any reason, the flow moving past the node or ending — the
     * conversation goes back to Pending; accepting it ("Assumir da IA") makes
     * it Active and stops the flow. Only the engine writes this status.
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

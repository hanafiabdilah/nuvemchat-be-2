<?php

namespace App\Services\Incident;

use App\Models\Conversation;
use Illuminate\Support\Facades\Log;

/**
 * What the workspace's AI agents are told while an incident is live on the
 * conversation's connection.
 *
 * The notice answers the customer's first message; their next one — "ainda
 * não voltou", "e quanto tempo falta?" — goes back to the flow, and an AI
 * agent that does not know about the outage will troubleshoot the customer's
 * own setup, or contradict the notice sitting right above its reply. So every
 * AI turn on a covered connection (the AI Agent node, "Respond with AI")
 * carries the incident's brief ahead of the customer's words, for as long as
 * the incident is live.
 *
 * Only when the incident has a brief: a fixed-message incident without one
 * changes nothing here, and the notice itself already reaches the agent as an
 * "Automated message" in the transcript (AiConversationContext).
 *
 * ⚠️ The brief is the business's own text and travels in `message.content`,
 * which the hub's handoff detector reads. The form warns against handoff words
 * in it; nothing here can rewrite what the business wrote.
 */
final class IncidentAiContext
{
    public static function block(Conversation $conversation): ?string
    {
        try {
            if (! IncidentResponder::covers($conversation)) {
                return null;
            }

            $incident = IncidentResponder::liveFor((int) $conversation->connection_id);
            $brief = trim((string) $incident?->ai_brief);

            if ($brief === '') {
                return null;
            }

            return <<<BLOCK
[Service notice from the business, in effect right now — not written by the customer]
The business is dealing with a known problem, and customers on this channel are being told about it:
"""
{$brief}
"""
If the customer's message is about it, answer with these facts: it is known and being handled. Do not suggest fixes on the customer's side the facts do not mention, and do not promise anything beyond them.
[End of service notice]
BLOCK;
        } catch (\Throwable $th) {
            // A turn without the notice is a turn; a turn that throws is silence.
            Log::warning('IncidentAiContext: could not read the incident', ['conversation_id' => $conversation->id, 'error' => $th->getMessage()]);

            return null;
        }
    }

    /** The turn's input with the notice ahead of it, when there is one. */
    public static function prepend(Conversation $conversation, string $input): string
    {
        $block = self::block($conversation);

        return $block === null ? $input : "{$block}\n\n{$input}";
    }
}

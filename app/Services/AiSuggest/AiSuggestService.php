<?php

namespace App\Services\AiSuggest;

use App\Enums\Connection\Channel;
use App\Enums\Message\SenderType;
use App\Models\Conversation;
use App\Services\AiAgentHub\AiAgentHubTenantService;
use RuntimeException;

/**
 * "Respond with AI" — drafts a reply suggestion for the agent from the recent
 * conversation transcript. The result is only pasted into the composer; the
 * agent edits and sends it manually, so nothing here touches the send path.
 *
 * Suggestions run on the AI Hub agent linked to the connection — the same
 * trained agent (persona, knowledge, skills) the flow AIAgent nodes use, so
 * one agent serves both features. Each draft runs under a synthetic hub
 * conversation id so drafting never touches the hub-side state of the real
 * conversation.
 */
class AiSuggestService
{
    /** How many recent messages to include in the prompt. */
    protected const TRANSCRIPT_LIMIT = 20;

    public function __construct(
        private readonly AiAgentHubTenantService $hub,
    ) {}

    public function suggest(Conversation $conversation): string
    {
        $agent = $conversation->connection->aiSuggestAgent;

        if (!$agent) {
            throw new RuntimeException('No AI agent is linked to this connection.');
        }

        // The hub has no channel mapping for email, and the webmail composer
        // never offers the suggest button — fail with a clear message if the
        // endpoint is hit anyway.
        if ($conversation->connection->channel === Channel::Email) {
            throw new RuntimeException('AI suggestions are not available for email conversations.');
        }

        [$instruction, $lastMessageId] = $this->buildInstruction($conversation);

        $run = $this->hub->runAgent(
            $agent,
            $conversation,
            $instruction,
            metadata: ['purpose' => 'ai_suggest'],
            // Fresh hub conversation per draft: hub state is keyed by this id,
            // and a draft must neither inherit nor contaminate the state of
            // the real conversation (or of earlier drafts).
            conversationExternalId: "suggest:{$conversation->id}:m{$lastMessageId}",
        );

        $suggestion = trim((string) ($run->output_message ?? ''));

        if ($suggestion === '') {
            throw new RuntimeException('The AI agent returned an empty suggestion.');
        }

        return $suggestion;
    }

    /**
     * One self-contained instruction: the drafting task plus the recent
     * transcript. The hub tracks history per conversation and this run uses a
     * fresh conversation id, so all context must travel in the message itself.
     *
     * @return array{0: string, 1: int} [instruction, last transcript message id]
     */
    protected function buildInstruction(Conversation $conversation): array
    {
        $contactName = $conversation->contact?->name ?: 'the customer';

        $recent = $conversation->messages()
            ->whereNull('unsend_at')
            ->orderByDesc('id')
            ->limit(self::TRANSCRIPT_LIMIT)
            ->get()
            ->reverse();

        $lines = [];
        $lastId = 0;

        foreach ($recent as $message) {
            $body = trim((string) ($message->body ?? ''));

            if ($body === '') {
                $type = $message->message_type?->value ?? 'message';
                $body = $type === 'text' ? '' : "[{$type}]";
            }

            if ($body === '') {
                continue;
            }

            $speaker = $message->sender_type === SenderType::Incoming ? 'Customer' : 'Agent';
            $lines[] = "{$speaker}: {$body}";
            $lastId = max($lastId, (int) $message->id);
        }

        if (empty($lines)) {
            throw new RuntimeException('There are no messages to base a suggestion on.');
        }

        $transcript = implode("\n", $lines);

        $instruction = <<<PROMPT
You are assisting a human support agent. Based on the conversation transcript below, draft the agent's next reply to the customer ({$contactName}).

Rules:
- Reply in the same language the customer is writing in.
- Be helpful, friendly and concise, in a tone appropriate for chat messaging.
- Output ONLY the message text to send — no quotes, labels, options or explanations.
- If information is missing to fully resolve the request, ask the customer a clear follow-up question instead of inventing facts.

Transcript:
{$transcript}
PROMPT;

        return [$instruction, $lastId];
    }
}

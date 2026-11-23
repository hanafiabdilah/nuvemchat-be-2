<?php

namespace App\Services\Incident;

use App\Enums\Billing\Feature;
use App\Enums\Message\MessageType;
use App\Enums\Message\SenderType;
use App\Models\AiHubAgent;
use App\Models\AiHubRun;
use App\Models\Conversation;
use App\Models\Incident;
use App\Models\IncidentDelivery;
use App\Models\Message;
use App\Services\AiAgentHub\AiAgentHubTenantService;
use App\Services\Billing\SubscriptionGate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The notice written by an AI agent ("Mode IA"): one reply per customer, from
 * the incident's brief and what that customer actually said.
 *
 * What it buys over the fixed message: the customer's language, and an answer
 * to the rest of what they asked — "meu proxy caiu, e quero comprar mais 10"
 * gets both halves, not a paragraph about DNS.
 *
 * Four rules, each from something that has already gone wrong elsewhere:
 *
 *  - **Never the conversation's own hub history.** The run uses its own hub
 *    conversation (`incident:{id}:c{conversation}`), like AI-suggest does: the
 *    instruction below is ours, and written into the real history it would be
 *    replayed as something the customer said — and scanned by the hub's
 *    handoff detector — on every turn after it.
 *  - **The instruction does not use handoff vocabulary** (the welcome incident,
 *    53 of 53 runs handed off). The brief and the customer's words are quoted
 *    because they are the point; ours stays clear of them.
 *  - **A handoff signal on this run is ignored.** The run answers a notice; the
 *    flow, the queue and the attendants are where a request for a person is
 *    served, and they still get the customer's next message.
 *  - **Never silent.** No agent, no balance, the hub down, an empty answer:
 *    null, and IncidentSender sends the fixed message instead.
 */
class IncidentAiWriter
{
    /** How much of what the customer said goes into the run. */
    private const MAX_CUSTOMER_MESSAGES = 5;

    private const MAX_CUSTOMER_CHARS = 1500;

    public function __construct(private AiAgentHubTenantService $hub) {}

    /**
     * The reply, and the run that wrote it — or null when the fixed message
     * has to go instead (and why, for the row).
     *
     * @return array{text: string, run: AiHubRun, agent: AiHubAgent}|array{text: null, reason: string}
     */
    public function write(Incident $incident, Conversation $conversation, IncidentDelivery $delivery): array
    {
        $agent = $this->agentFor($incident);

        if (! $agent) {
            return ['text' => null, 'reason' => 'ai_unavailable'];
        }

        try {
            $run = $this->hub->runAgent(
                $agent,
                $conversation,
                self::prompt((string) $incident->ai_brief, $this->customerWords($conversation, $delivery)),
                metadata: ['purpose' => 'incident_notice', 'incidentId' => $incident->id],
                // Its own hub conversation per incident and customer — never
                // the thread's history (see the class docblock).
                conversationExternalId: "incident:{$incident->id}:c{$conversation->id}",
            );
        } catch (\Throwable $th) {
            Log::warning('IncidentAiWriter: the AI could not write the notice, sending the fixed message', [
                'incident_id' => $incident->id,
                'conversation_id' => $conversation->id,
                'exception' => $th::class,
                'error' => $th->getMessage(),
            ]);

            return ['text' => null, 'reason' => $th instanceof \App\Exceptions\Billing\CreditExhaustedException ? 'ai_no_credit' : 'ai_failed'];
        }

        $text = trim((string) $run->output_message);

        if ($text === '' || ! empty(($run->metadata ?? [])['responseSuppressed'])) {
            return ['text' => null, 'reason' => 'ai_failed'];
        }

        return ['text' => Str::limit($text, 4000, ''), 'run' => $run, 'agent' => $agent];
    }

    /**
     * The agent the incident names, if it can still run: in this workspace,
     * active, and — where plans are enforced — on a plan that has AI agents.
     */
    public function agentFor(Incident $incident): ?AiHubAgent
    {
        if (! $incident->writesWithAi() || trim((string) $incident->ai_brief) === '') {
            return null;
        }

        $agent = AiHubAgent::with('aiHubTenant')->find($incident->ai_hub_agent_id);

        if (! $agent || $agent->status !== 'ACTIVE' || (int) $agent->aiHubTenant?->tenant_id !== (int) $incident->tenant_id) {
            return null;
        }

        if (config('services.billing.enforce') && $incident->tenant
            && ! app(SubscriptionGate::class)->feature($incident->tenant, Feature::AiAgentHub->value)) {
            return null;
        }

        return $agent;
    }

    /**
     * What we ask for. English and written for the model; the reply comes out
     * in the customer's language because the instruction says so.
     */
    public static function prompt(string $brief, string $customer): string
    {
        $brief = trim($brief);
        $customer = trim($customer) !== '' ? trim($customer) : '(the customer sent media or a message without text)';

        return <<<PROMPT
[Service notice from the business — this is not the customer speaking]
The business is dealing with a problem right now and wants every customer who writes in to hear about it. Facts to pass on, written by the business:
"""
{$brief}
"""

[What the customer wrote]
"""
{$customer}
"""

Write the message this business sends this customer now. Tell them about the problem using only the facts above, in the same language the customer wrote in, short and warm, as plain chat text (no markdown, no headings). If they also asked about something else, answer that briefly when the facts allow it, otherwise say it will be looked at as soon as possible. Never promise anything the facts do not say. Reply with the message only.
PROMPT;
    }

    /**
     * The customer's last few messages up to the one that decided — "oi" and
     * "meu proxy caiu" are one thought, and answering only the half that
     * arrived last is how an AI ends up asking for something it was given.
     */
    private function customerWords(Conversation $conversation, IncidentDelivery $delivery): string
    {
        $upTo = $delivery->trigger_message_id;

        $messages = Message::where('conversation_id', $conversation->id)
            ->where('sender_type', SenderType::Incoming)
            ->where('message_type', '!=', MessageType::Info)
            ->whereNull('unsend_at')
            ->when($upTo, fn ($query) => $query->where('id', '<=', $upTo))
            ->where('created_at', '>=', now()->subHours(6))
            ->orderByDesc('id')
            ->limit(self::MAX_CUSTOMER_MESSAGES)
            ->get()
            ->reverse();

        $lines = $messages
            ->map(fn (Message $message) => trim((string) ($message->body ?: data_get($message->meta, 'transcription.text') ?: '')))
            ->filter()
            ->implode("\n");

        return mb_substr($lines, -self::MAX_CUSTOMER_CHARS);
    }
}

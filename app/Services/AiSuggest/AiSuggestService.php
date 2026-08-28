<?php

namespace App\Services\AiSuggest;

use App\Enums\Connection\Channel;
use App\Enums\Message\SenderType;
use App\Models\Conversation;
use App\Services\AiAgentHub\AiAgentHubTenantService;
use App\Services\AiAgentHub\AiAttachments;
use App\Services\AiAgentHub\AiTranscripts;
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
    /** Hard cap on how many messages are considered for the transcript. */
    protected const TRANSCRIPT_LIMIT = 200;

    /**
     * Character budget for the whole transcript. Walking newest-to-oldest we
     * stop adding messages once the budget is spent, so long conversations
     * still fit in a single hub run.
     */
    protected const TRANSCRIPT_CHAR_BUDGET = 12000;

    /** Older messages are clipped to this length; the latest ones stay whole. */
    protected const OLD_MESSAGE_CHAR_CAP = 600;

    /**
     * Customer media sent along with the draft request, newest first.
     *
     * Deliberately tighter than a flow turn's budget: a suggestion is one
     * throwaway draft an agent may not even use, and each image is billed
     * input tokens — each voice note, minutes of transcription — every time
     * the button is pressed.
     */
    protected const MAX_ATTACHMENTS = 2;

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

        [$instruction, $lastMessageId, $attachmentEntries] = $this->buildInstruction($conversation);

        $run = $this->hub->runAgent(
            $agent,
            $conversation,
            $instruction,
            metadata: ['purpose' => 'ai_suggest'],
            // Fresh hub conversation per draft: hub state is keyed by this id,
            // and a draft must neither inherit nor contaminate the state of
            // the real conversation (or of earlier drafts).
            conversationExternalId: "suggest:{$conversation->id}:m{$lastMessageId}",
            attachments: array_column($attachmentEntries, 'attachment'),
        );

        // A draft the agent may well discard still leaves the thread better
        // than it found it: the voice note it transcribed keeps its words.
        AiTranscripts::store($run, $attachmentEntries);

        $suggestion = trim((string) ($run->output_message ?? ''));

        if ($suggestion === '') {
            throw new RuntimeException('The AI agent returned an empty suggestion.');
        }

        return $suggestion;
    }

    /**
     * One self-contained instruction: the drafting task plus the conversation
     * transcript. The hub tracks history per conversation and this run uses a
     * fresh conversation id, so all context must travel in the message itself.
     *
     * The transcript covers as much of the conversation as fits the character
     * budget (newest first), so the agent understands the full context — but
     * the prompt singles out the customer's latest message as the one the
     * reply must address.
     *
     * The customer's most recent screenshots and voice notes ride along as
     * real attachments. A transcript can only say that an image exists; half
     * the support threads that most need a drafted reply are the ones where
     * the answer is inside the picture — or was never typed at all.
     *
     * @return array{0: string, 1: int, 2: array<int, array{attachment: array<string, mixed>, message: \App\Models\Message}>}
     *         [instruction, last transcript message id, attachments with their sources]
     */
    protected function buildInstruction(Conversation $conversation): array
    {
        $contactName = $conversation->contact?->name ?: 'the customer';

        $recent = $conversation->messages()
            ->whereNull('unsend_at')
            ->orderByDesc('id')
            ->limit(self::TRANSCRIPT_LIMIT)
            ->get();

        // Walk newest-to-oldest so the budget is always spent on the most
        // recent context; older lines are clipped, and once the budget runs
        // out the rest of the history is dropped.
        $lines = [];
        $lastId = 0;
        $latestCustomerMessage = null;
        $budget = self::TRANSCRIPT_CHAR_BUDGET;
        $truncated = false;
        $entries = [];

        foreach ($recent as $index => $message) {
            $isIncoming = $message->sender_type === SenderType::Incoming;
            $body = trim((string) ($message->body ?? ''));

            // What an earlier run already heard in this voice note. Written by
            // AiTranscripts; empty for everything that is not spoken audio.
            $spoken = trim((string) data_get($message->meta, 'transcription.text'));

            // Only the customer's media is worth sending: one the agent
            // already sent is something this side chose and knows about.
            // Audio that has already been written down is skipped — the words
            // are in the transcript below, and transcribing them a second time
            // buys the same sentence at the same per-minute price.
            $attachment = ($isIncoming && $spoken === '' && count($entries) < self::MAX_ATTACHMENTS)
                ? AiAttachments::forMessage($message)
                : null;

            if ($attachment !== null) {
                $entries[] = ['attachment' => $attachment, 'message' => $message];
            }

            // Mark the attached ones apart from the media that only exists in
            // the transcript as a word, so the agent knows which picture it is
            // actually looking at.
            $marker = $attachment !== null ? ' [attached to this request]' : '';

            if ($body === '') {
                $type = $message->message_type?->value ?? 'message';
                $body = $spoken !== ''
                    ? "[{$type}] {$spoken}"
                    : ($type === 'text' ? '' : "[{$type}]{$marker}");
            } else {
                $body .= $marker;
            }

            if ($body === '') {
                continue;
            }

            if ($latestCustomerMessage === null && $isIncoming) {
                $latestCustomerMessage = $body;
            }

            // The few newest messages travel whole; everything older is
            // clipped so one giant old message can't eat the whole budget.
            if ($index >= 5 && mb_strlen($body) > self::OLD_MESSAGE_CHAR_CAP) {
                $body = mb_substr($body, 0, self::OLD_MESSAGE_CHAR_CAP) . '…';
            }

            $speaker = $isIncoming ? 'Customer' : 'Agent';
            $line = "{$speaker}: {$body}";

            if (mb_strlen($line) > $budget) {
                $truncated = true;
                break;
            }

            $budget -= mb_strlen($line) + 1;
            $lines[] = $line;
            $lastId = max($lastId, (int) $message->id);
        }

        if (empty($lines)) {
            throw new RuntimeException('There are no messages to base a suggestion on.');
        }

        if (!$truncated && $recent->count() >= self::TRANSCRIPT_LIMIT) {
            $truncated = true;
        }

        $lines = array_reverse($lines);

        if ($truncated) {
            array_unshift($lines, '[earlier messages omitted]');
        }

        $transcript = implode("\n", $lines);

        $focus = $latestCustomerMessage !== null
            ? <<<FOCUS
The customer's most recent message — the one your reply must address:
"{$latestCustomerMessage}"
FOCUS
            : 'The customer has not written since the agent\'s last message; draft a natural follow-up that moves the conversation forward.';

        $imageRule = $entries === []
            ? ''
            : "\n- The customer's file(s) marked \"[attached to this request]\" in the transcript are attached to this message; read or listen to them before replying, and answer what they actually contain.";

        $instruction = <<<PROMPT
You are assisting a human support agent. Read the FULL conversation transcript below to understand the context — what the customer already said, what has been answered, and any details they already provided — then draft the agent's next reply to the customer ({$contactName}).

{$focus}

Rules:
- Your reply must respond primarily to the customer's most recent message; use the rest of the transcript only as supporting context.{$imageRule}
- Never ask for information the customer already gave earlier in the conversation, and never repeat an answer the agent already gave.
- Reply in the same language the customer is writing in.
- Be helpful, friendly and concise, in a tone appropriate for chat messaging.
- Output ONLY the message text to send — no quotes, labels, options or explanations.
- If information is missing to fully resolve the request, ask the customer a clear follow-up question instead of inventing facts.

Transcript (oldest to newest):
{$transcript}
PROMPT;

        // Collected newest-first to keep the cap on the recent ones; handed
        // over oldest-first, like the transcript that points at them.
        return [$instruction, $lastId, array_reverse($entries)];
    }
}

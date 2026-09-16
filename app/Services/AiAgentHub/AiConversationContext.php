<?php

namespace App\Services\AiAgentHub;

use App\Enums\Message\MessageType;
use App\Enums\Message\SenderType;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Support\Collection;

/**
 * What happened in a conversation that an AI Agent node's hub conversation
 * never saw.
 *
 * The hub keeps the dialogue it took part in — the customer messages it was
 * sent and the replies it wrote — and nothing else. Everything the flow said
 * before the node was reached ("our Cipayung server is down, a power outage")
 * went straight to the channel, and so did whatever the customer answered it
 * with. An agent handed only the customer's newest line answers a question
 * with none of the facts the customer was already given, and asks for things
 * the customer already said.
 *
 * So each turn carries, ahead of the customer's words, a transcript of the
 * messages the hub has not seen yet, oldest first. A watermark per node makes
 * it a delta: once the hub has a line in its own history it is never sent
 * again.
 */
class AiConversationContext
{
    /** Most messages a single transcript will consider. */
    protected const MESSAGE_LIMIT = 60;

    /**
     * Character budget for the transcript. Walked newest-first, so a long
     * history spends it on what was said most recently.
     */
    protected const CHAR_BUDGET = 6000;

    /** The newest few lines travel whole; older ones are clipped to this. */
    protected const OLD_LINE_CHAR_CAP = 600;

    protected const WHOLE_LINES = 5;

    /** How the node's own opening is labelled in the transcript. */
    protected const WELCOME_SPEAKER = 'You (welcome message)';

    /**
     * What the node's opening is reduced to before it travels.
     *
     * Everything in this block is sent to the hub as the *customer's* message
     * — that is the only field a run has — and the hub scans that field for
     * handoff keywords. So a welcome that politely offers "falar com um
     * atendente humano" reads to the detector as the customer asking for one,
     * and every conversation is handed to a person on its first turn. The
     * agent needs to know it has already greeted; it does not need the
     * sentence back.
     */
    protected const WELCOME_PLACEHOLDER = '(your opening message was delivered to the customer)';

    /**
     * The id up to which the customer counts as answered when the node is
     * entered: the newest message anybody sent them.
     *
     * A customer message followed by an outgoing one was dealt with by
     * whatever sent that — a Response node took it as input, a Message node
     * answered it. Handing it to the agent as something still owed a reply is
     * how the agent ends up answering "the power is out" a second time, after
     * the flow already did. It belongs in the transcript instead.
     */
    public static function answeredUpTo(int $conversationId): int
    {
        return (int) Message::where('conversation_id', $conversationId)
            ->where('sender_type', SenderType::Outgoing)
            ->where('message_type', '!=', MessageType::Info)
            ->whereNull('unsend_at')
            ->max('id');
    }

    /**
     * The transcript of what the hub has not seen, and the id it now covers.
     *
     * $input is the turn's own messages — sent separately, as the thing to
     * answer, so they are left out here. Customer messages newer than the
     * input belong to the next turn and are left out too; outgoing ones are
     * not (a person, or a welcome sent up front, can write after the input).
     *
     * Null when there is nothing worth saying: the agent's own replies are in
     * the hub already, and a thread that is only the customer's greeting and
     * this node's welcome tells the agent nothing it needs.
     *
     * @param  Collection<int, Message>  $input  oldest first, not empty
     * @return array{0: string|null, 1: int}
     */
    public static function build(Conversation $conversation, int $seenUpTo, Collection $input): array
    {
        $lastInputId = (int) $input->last()->id;

        $messages = Message::where('conversation_id', $conversation->id)
            ->where('id', '>', $seenUpTo)
            ->whereNotIn('id', $input->pluck('id')->all())
            ->whereNull('unsend_at')
            ->where('message_type', '!=', MessageType::Info)
            ->where(fn ($query) => $query->where('sender_type', SenderType::Outgoing)
                ->orWhere('id', '<=', $lastInputId))
            ->with('sentByUser:id,name')
            ->orderByDesc('id')
            ->limit(self::MESSAGE_LIMIT)
            ->get();

        $covered = max($seenUpTo, $lastInputId, (int) $messages->max('id'));

        $lines = [];
        $budget = self::CHAR_BUDGET;
        $truncated = $messages->count() >= self::MESSAGE_LIMIT;
        $informative = false;

        foreach ($messages as $message) {
            // Written by the hub, so already in its history.
            //
            // A proactive message (AiProactiveMessageService) has no run behind
            // it on our side and so carries no run id — it needs its own flag,
            // and it is not cosmetic. Handing the hub back its own sentence is
            // the smaller half; the larger is that anything landing in
            // `message.content` is read by the hub's handoff detector, so a
            // proactive message mentioning an "atendente" would hand the
            // conversation to a human on the next turn.
            if (data_get($message->meta, 'ai_hub_run_id') !== null
                || data_get($message->meta, 'ai_hub_proactive') !== null) {
                continue;
            }

            $body = self::body($message);

            if ($body === '') {
                continue;
            }

            [$speaker, $counts] = self::speaker($message);

            if ($speaker === self::WELCOME_SPEAKER) {
                $body = self::WELCOME_PLACEHOLDER;
            }

            if (count($lines) >= self::WHOLE_LINES && mb_strlen($body) > self::OLD_LINE_CHAR_CAP) {
                $body = mb_substr($body, 0, self::OLD_LINE_CHAR_CAP).'…';
            }

            $line = "{$speaker}: {$body}";

            if (mb_strlen($line) > $budget) {
                $truncated = true;
                break;
            }

            $budget -= mb_strlen($line) + 1;
            $lines[] = $line;
            $informative = $informative || $counts;
        }

        if (! $informative) {
            return [null, $covered];
        }

        $lines = array_reverse($lines);

        if ($truncated) {
            array_unshift($lines, '[earlier messages omitted]');
        }

        $transcript = implode("\n", $lines);

        $block = <<<CONTEXT
[Conversation so far — messages exchanged with this customer that you have not seen yet, oldest first. Lines marked "Automated message" were sent to the customer by this business's automated flow before you joined: treat what they say as already told to the customer, never contradict them without reason, and never ask for something the customer already answered.]
{$transcript}
[End of conversation so far]
CONTEXT;

        return [$block, $covered];
    }

    /** The turn's text with the transcript ahead of it, when there is one. */
    public static function compose(?string $context, string $text): string
    {
        if ($context === null) {
            return $text;
        }

        return "{$context}\n\nThe customer's new message — reply to this:\n{$text}";
    }

    /**
     * The turn's input, told that the node's welcome goes out immediately
     * before this reply.
     *
     * The welcome is held back for the AI's first answer and sent in the bubble
     * right above it. A model that does not know that greets and introduces
     * itself as well, and two openings stacked on top of each other read as a
     * bot that is not listening to itself.
     *
     * The welcome is named, never quoted. This whole string is delivered to the
     * hub as the customer's message, and the hub scans that field for handoff
     * keywords: quoting a welcome that ends "se quiser falar com um atendente
     * humano, é só pedir" made the detector read every opening turn as the
     * customer asking for a person — 53 of 53 such runs were handed off. The
     * instruction that has to survive is "you already greeted", and that needs
     * no copy of the sentence. $welcome is still taken so an empty one means
     * there is nothing to say.
     */
    public static function precededByWelcome(string $welcome, string $input): string
    {
        if (trim($welcome) === '') {
            return $input;
        }

        return "[Your welcome message is being sent to the customer in the bubble immediately above this reply. Do not greet the customer or introduce yourself again.]\n\n{$input}";
    }

    protected static function body(Message $message): string
    {
        $body = trim((string) $message->body);

        if ($body !== '') {
            return $body;
        }

        // A voice note an earlier run already wrote down reads as its words.
        $spoken = trim((string) data_get($message->meta, 'transcription.text'));
        $marker = AiAttachments::describe($message);

        if ($spoken !== '') {
            return "{$marker} {$spoken}";
        }

        return $message->message_type === MessageType::Text ? '' : $marker;
    }

    /**
     * Who said it, and whether that line is news to the agent.
     *
     * @return array{0: string, 1: bool}
     */
    protected static function speaker(Message $message): array
    {
        if ($message->sender_type === SenderType::Incoming) {
            return ['Customer', false];
        }

        // Sent in the agent's name, from the node's settings — the agent never
        // wrote it, but it is the agent's own opening.
        if (data_get($message->meta, 'ai_welcome') === true) {
            return [self::WELCOME_SPEAKER, false];
        }

        if ($message->sent_by_user_id !== null) {
            $name = trim((string) $message->sentByUser?->name);

            return [$name !== '' ? "Human agent ({$name})" : 'Human agent', true];
        }

        return ['Automated message', true];
    }
}

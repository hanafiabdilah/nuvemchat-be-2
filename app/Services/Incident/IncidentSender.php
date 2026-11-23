<?php

namespace App\Services\Incident;

use App\Enums\Conversation\Status as ConversationStatus;
use App\Enums\Incident\DeliveryKind;
use App\Enums\Incident\DeliverySource;
use App\Enums\Incident\DeliveryStatus;
use App\Enums\Incident\ResolutionAudience;
use App\Events\ConversationUpdated;
use App\Events\MessageReceived;
use App\Exceptions\ChannelCapabilityException;
use App\Exceptions\UpstreamServiceException;
use App\Models\Conversation;
use App\Models\Incident;
use App\Models\IncidentDelivery;
use App\Models\Message;
use App\Services\Conversation\SystemMessage;
use App\Services\Message\MessageService;
use App\Services\Messaging\FreeFormWindow;
use App\Services\V1\SendMessage\OutgoingMessage;
use App\Support\Errors\HasUserSafeMessage;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Sends one incident row into its conversation: the notice, or the "it is
 * fixed" message.
 *
 * Claims the row first (pending → sending, one UPDATE), so a paced pump and a
 * watchdog that revived it cannot both send to the same person. Nothing is
 * retried: a send that timed out may still have reached WhatsApp, and a
 * second attempt would be a second bubble in front of a customer who is
 * already upset. A failed row says why on the incident page, and the
 * conversation gets a note so the attendant who opens it knows.
 *
 * The notice a customer triggered by writing is sent without a window check —
 * they wrote seconds ago. Everything sent on the platform's initiative (the
 * people who wrote before the incident was switched on, a notice sent by hand
 * later, the resolution) is checked like a campaign: opted-out contacts and
 * closed windows are skipped, never forced through.
 */
class IncidentSender
{
    public function __construct(
        private MessageService $messages,
        private IncidentAiWriter $writer,
    ) {}

    public function send(IncidentDelivery $delivery): DeliveryStatus
    {
        $claimed = IncidentDelivery::whereKey($delivery->id)
            ->where('status', DeliveryStatus::Pending)
            ->update(['status' => DeliveryStatus::Sending, 'updated_at' => now()]);

        if ($claimed === 0) {
            return $delivery->refresh()->status;
        }

        $delivery->refresh();

        try {
            return $this->deliver($delivery);
        } catch (\Throwable $th) {
            Log::error('IncidentSender: send failed', [
                'delivery_id' => $delivery->id,
                'incident_id' => $delivery->incident_id,
                'conversation_id' => $delivery->conversation_id,
                'exception' => $th::class,
                'error' => $th->getMessage(),
            ]);

            $this->noteFailure($delivery);

            return $this->finish($delivery, DeliveryStatus::Failed, IncidentDelivery::REASON_SEND_FAILED, $this->failureReason($th));
        }
    }

    private function deliver(IncidentDelivery $delivery): DeliveryStatus
    {
        $incident = Incident::find($delivery->incident_id);
        $conversation = Conversation::with(['connection', 'contact'])->find($delivery->conversation_id);

        if (! $incident || ! $conversation || ! $conversation->getRelationValue('connection')) {
            return $this->finish($delivery, DeliveryStatus::Skipped, IncidentDelivery::REASON_CONVERSATION_GONE);
        }

        if ($delivery->source !== DeliverySource::Reactive) {
            $reason = $this->reasonToHoldBack($incident, $delivery, $conversation);

            if ($reason !== null) {
                return $this->finish($delivery, DeliveryStatus::Skipped, $reason);
            }
        }

        $isResolution = $delivery->kind === DeliveryKind::Resolution;
        $text = IncidentText::render(
            (string) ($isResolution ? $incident->resolution_message : $incident->message),
            $conversation,
        );

        // Mode IA: the notice written for this customer. Anything short of a
        // reply — no balance, hub down, empty answer — keeps the fixed text
        // above, and the row says why, so nobody is left without the notice.
        $written = null;
        $fallback = null;

        if (! $isResolution && $incident->writesWithAi()) {
            $result = $this->writer->write($incident, $conversation, $delivery);

            if ($result['text'] !== null) {
                $written = $result;
                $text = $result['text'];
            } else {
                $fallback = $result['reason'];
            }
        }

        $message = $isResolution
            ? $this->messages->sendMessage($conversation, ['message' => $text])
            : $this->sendNotice($incident, $conversation, $text);

        if (! $message) {
            $this->noteFailure($delivery);

            return $this->finish($delivery, DeliveryStatus::Failed, IncidentDelivery::REASON_SEND_FAILED);
        }

        $meta = array_merge((array) ($message->meta ?? []), [
            IncidentResponder::SENT_META => array_filter([
                'id' => $incident->id,
                'kind' => $delivery->kind->value,
                'title' => $incident->title,
                'keyword' => $delivery->matched_keyword,
                'ai' => $written !== null ? true : null,
            ], fn ($value) => $value !== null),
        ]);

        if ($written !== null) {
            // Written by an agent, so it is credited to one — but under its own
            // key, never `ai_hub_run_id`: that one tells AiConversationContext
            // "the hub already knows this", and the run happened in a hub
            // conversation of its own. Left as a transcript line, the AI node
            // that answers the customer's next message knows the notice went.
            $meta['ai_generated'] = true;
            $meta['incident_ai_run_id'] = $written['run']->id;
            $message->sent_by_ai_hub_agent_id = $written['agent']->id;
        }

        $message->forceFill(['meta' => $meta])->saveQuietly();

        if ($written !== null) {
            $written['run']->update(['message_id' => $message->id]);
        }

        broadcast(new MessageReceived($message));
        broadcast(new ConversationUpdated($conversation->load('contact')));

        $delivery->forceFill(['message_id' => $message->id]);

        IncidentEvents::changed($incident, throttled: true);

        return $this->finish($delivery, DeliveryStatus::Sent, $fallback);
    }

    /**
     * The notice, with its file when it has one. The words go in the caption
     * where the channel takes captions on that kind of file; elsewhere
     * (Instagram, Messenger, audio anywhere) they follow as their own message,
     * because a caption the channel drops is a notice that says nothing.
     */
    private function sendNotice(Incident $incident, Conversation $conversation, string $text): ?Message
    {
        $url = trim((string) $incident->media_url);
        $type = (string) ($incident->media_type ?: 'image');

        if ($url === '') {
            return $this->messages->sendMessage($conversation, ['message' => $text]);
        }

        $channel = $conversation->getRelationValue('connection')->channel;
        $captioned = in_array($type, OutgoingMessage::captionTypes($channel), true);

        $media = $this->sendMedia($conversation, $type, $url, $captioned ? $text : null);

        if ($captioned) {
            return $media;
        }

        return $this->messages->sendMessage($conversation, ['message' => $text]) ?? $media;
    }

    private function sendMedia(Conversation $conversation, string $type, string $url, ?string $caption): ?Message
    {
        $data = array_filter(['media_url' => $url, 'message' => $caption], fn ($value) => $value !== null);

        return match ($type) {
            'video' => $this->messages->sendVideo($conversation, $data),
            'document' => $this->messages->sendDocument($conversation, $data),
            'audio' => $this->messages->sendAudio($conversation, ['media_url' => $url]),
            default => $this->messages->sendImage($conversation, $data),
        };
    }

    /** Why a send the platform starts on its own must not go out now, or null. */
    private function reasonToHoldBack(Incident $incident, IncidentDelivery $delivery, Conversation $conversation): ?string
    {
        if ($delivery->kind === DeliveryKind::Notice && ! $incident->isLive()) {
            return IncidentDelivery::REASON_INCIDENT_OVER;
        }

        if ($delivery->kind === DeliveryKind::Notice
            && $delivery->source === DeliverySource::Retro
            && $conversation->status === ConversationStatus::Resolved) {
            return IncidentDelivery::REASON_CONVERSATION_RESOLVED;
        }

        if ($delivery->kind === DeliveryKind::Resolution
            && $incident->resolution_audience === ResolutionAudience::Open
            && $conversation->status === ConversationStatus::Resolved) {
            return IncidentDelivery::REASON_CONVERSATION_RESOLVED;
        }

        if ($conversation->getRelationValue('contact')?->hasOptedOutOfBroadcasts()) {
            return IncidentDelivery::REASON_OPTED_OUT;
        }

        if (FreeFormWindow::closed($conversation)) {
            return IncidentDelivery::REASON_WINDOW_CLOSED;
        }

        return null;
    }

    /**
     * A line in the thread for the attendant who opens it: the customer was
     * meant to be told and was not. Only for the notice — a resolution that
     * failed is reported on the incident page and nobody is left waiting on it.
     */
    private function noteFailure(IncidentDelivery $delivery): void
    {
        if ($delivery->kind !== DeliveryKind::Notice) {
            return;
        }

        try {
            $conversation = Conversation::find($delivery->conversation_id);
            $incident = Incident::find($delivery->incident_id);

            if ($conversation && $incident) {
                SystemMessage::info(
                    $conversation,
                    "The incident notice \"{$incident->title}\" could not be delivered.",
                    'incident_notice_failed',
                    ['incident' => $incident->title],
                );
            }
        } catch (\Throwable $th) {
            Log::warning('IncidentSender: could not write the failure note', ['error' => $th->getMessage()]);
        }
    }

    /**
     * The channel's refusal, in the words MessageService already translated
     * it into — or null when it is something unclassified, which the reason
     * code says well enough on its own.
     */
    private function failureReason(\Throwable $th): ?string
    {
        if ($th instanceof ValidationException) {
            return (string) $th->validator->errors()->first() ?: null;
        }

        if ($th instanceof UpstreamServiceException || $th instanceof ChannelCapabilityException || $th instanceof HasUserSafeMessage) {
            return $th->getMessage();
        }

        return null;
    }

    private function finish(IncidentDelivery $delivery, DeliveryStatus $status, ?string $reason, ?string $error = null): DeliveryStatus
    {
        $delivery->forceFill([
            'status' => $status,
            'reason' => $reason,
            'error' => $error !== null ? mb_substr($error, 0, 500) : null,
            'sent_at' => $status === DeliveryStatus::Sent ? now() : null,
        ])->save();

        return $status;
    }
}

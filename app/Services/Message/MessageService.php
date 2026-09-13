<?php

namespace App\Services\Message;

use App\Exceptions\ChannelCapabilityException;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Message\Contracts\MarksMessagesAsRead;
use App\Services\Message\Contracts\SendsTypingIndicator;
use App\Services\Message\Handlers\WhatsappOfficialHandler;
use App\Support\Errors\HasUserSafeMessage;
use App\Support\Errors\UpstreamError;
use App\Support\Errors\UpstreamProvider;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class MessageService
{
    public function sendMessage(Conversation $conversation, array $data): ?Message
    {
        return $this->guard($conversation, 'send a message', function () use ($conversation, $data) {
            $handler = MessageFactory::make($conversation->connection->channel, $data);

            return $handler->handleSendMessage($conversation, $data);
        });
    }

    /**
     * Send a WhatsApp message template. Templates are a WhatsApp Official (Cloud
     * API) concept only, so this rejects any other channel rather than adding a
     * no-op to every handler.
     */
    public function sendTemplate(Conversation $conversation, array $data): ?Message
    {
        return $this->guard($conversation, 'send a template', function () use ($conversation, $data) {
            $handler = MessageFactory::make($conversation->connection->channel, $data);

            if (!$handler instanceof WhatsappOfficialHandler) {
                throw new ChannelCapabilityException('Templates só podem ser enviados em conexões WhatsApp Oficial.');
            }

            return $handler->handleSendTemplate($conversation, $data);
        });
    }

    public function sendInteractive(Conversation $conversation, array $data): ?Message
    {
        return $this->guard($conversation, 'send an interactive message', function () use ($conversation, $data) {
            $handler = MessageFactory::make($conversation->connection->channel, $data);

            if (!$handler instanceof WhatsappOfficialHandler) {
                throw new ChannelCapabilityException('Mensagens com botões só podem ser enviadas em conexões WhatsApp Oficial.');
            }

            return $handler->handleSendInteractive($conversation, $data);
        });
    }

    /**
     * Reflect a read back onto the channel, so the customer's own app shows it.
     *
     * Best-effort in the same way as sendTyping(), and for a stronger reason:
     * this runs from a queued job behind an agent who has already moved on, so
     * there is nobody left to show an error to. A channel that cannot do it is
     * a silent false; one that can but fails is a logged warning.
     *
     * @param  Collection<int, Message>|null  $messages  The messages that just
     *         flipped to read, when the caller knows them (see the contract).
     */
    public function markAsRead(Conversation $conversation, ?Collection $messages = null): bool
    {
        $handler = MessageFactory::make($conversation->connection->channel);

        if (! $handler instanceof MarksMessagesAsRead) {
            return false;
        }

        try {
            return $handler->handleMarkAsRead($conversation, $messages ?? collect());
        } catch (\Throwable $th) {
            Log::warning('MessageService: read receipt failed', [
                'conversation_id' => $conversation->id,
                'channel' => $conversation->connection->channel->value,
                'error' => $th->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Show (or withdraw) "typing…" to the customer on the channel.
     *
     * Called from the composer while an agent writes, so it runs far more often
     * than anything else here and must never cost the agent anything: a channel
     * that cannot do it is a silent false, and a channel that can but fails is a
     * logged warning, never an exception. A dropped indicator is invisible; a
     * composer that throws is not.
     */
    public function sendTyping(Conversation $conversation, bool $typing = true): bool
    {
        $handler = MessageFactory::make($conversation->connection->channel);

        if (! $handler instanceof SendsTypingIndicator) {
            return false;
        }

        try {
            return $handler->handleTyping($conversation, $typing);
        } catch (\Throwable $th) {
            Log::warning('MessageService: typing indicator failed', [
                'conversation_id' => $conversation->id,
                'channel' => $conversation->connection->channel->value,
                'typing' => $typing,
                'error' => $th->getMessage(),
            ]);

            return false;
        }
    }

    public function sendImage(Conversation $conversation, array $data): ?Message
    {
        return $this->guard($conversation, 'send an image', function () use ($conversation, $data) {
            $handler = MessageFactory::make($conversation->connection->channel, $data);

            return $handler->handleSendImage($conversation, $data);
        });
    }

    public function sendAudio(Conversation $conversation, array $data): ?Message
    {
        return $this->guard($conversation, 'send audio', function () use ($conversation, $data) {
            $handler = MessageFactory::make($conversation->connection->channel, $data);

            return $handler->handleSendAudio($conversation, $data);
        });
    }

    public function sendVideo(Conversation $conversation, array $data): ?Message
    {
        return $this->guard($conversation, 'send a video', function () use ($conversation, $data) {
            $handler = MessageFactory::make($conversation->connection->channel, $data);

            return $handler->handleSendVideo($conversation, $data);
        });
    }

    public function sendDocument(Conversation $conversation, array $data): ?Message
    {
        return $this->guard($conversation, 'send a document', function () use ($conversation, $data) {
            $handler = MessageFactory::make($conversation->connection->channel, $data);

            return $handler->handleSendDocument($conversation, $data);
        });
    }

    public function editMessage(Message $message, array $data): ?Message
    {
        return $this->guard($message->conversation, 'edit a message', function () use ($message, $data) {
            $handler = MessageFactory::make($message->conversation->connection->channel, $data);

            return $handler->handleEditMessage($message, $data);
        });
    }

    public function deleteMessage(Message $message): bool
    {
        return (bool) $this->guard($message->conversation, 'delete a message', function () use ($message) {
            $handler = MessageFactory::make($message->conversation->connection->channel, []);

            return $handler->handleDeleteMessage($message);
        });
    }

    /**
     * The single door every outbound send goes through — and therefore the
     * single place a channel's own words are turned into ours.
     *
     * The handlers below build their exception messages out of whatever the
     * channel answered: `$responseArray['error']['message']`, a Guzzle string,
     * a Go error from the API Way core. Those used to travel unchanged into the
     * agent's toast and into the `error` column of a failed campaign row, where
     * "(#131047) Re-engagement message outside the allowed window" is read by
     * someone who has never heard of a re-engagement message.
     *
     * Two kinds of failure leave here unchanged, because each is already ours
     * and each says more than a translation could:
     *
     *  - ValidationException — we rejected the request before anyone was called.
     *  - anything marked HasUserSafeMessage — the marker means "getMessage() is
     *    copy we wrote". That covers a rule of the platform
     *    (ChannelCapabilityException: "Instagram não permite editar", final, so
     *    retrying is pointless and the agent should be told so), a channel class
     *    phrasing for the person who has to act (ConnectionException: mailbox
     *    credentials, an unpaired instance), an already-translated upstream
     *    refusal (UpstreamServiceException), and a sentence we chose outright
     *    (UserFacingException: "send an .mp3 instead").
     *
     * ⚠️ Matching on the interface rather than on a list of classes is the
     * point. The list was the whole of it once, and a sentence written for the
     * agent — the audio converter's "try an .mp3" — was flattened into "não foi
     * possível enviar" purely by not being named in it, which is the failure
     * the marker exists to prevent and the one nothing records.
     *
     * @template T
     * @param  callable(): T  $send
     * @return T
     */
    private function guard(Conversation $conversation, string $action, callable $send)
    {
        try {
            return $send();
        } catch (ValidationException|HasUserSafeMessage $th) {
            throw $th;
        } catch (\Throwable $th) {
            throw UpstreamError::exception(
                UpstreamProvider::forChannel($conversation->connection->channel),
                $th->getMessage(),
                context: [
                    'conversation_id' => $conversation->id,
                    'connection_id' => $conversation->connection_id,
                    'channel' => $conversation->connection->channel->value,
                    'action' => $action,
                    'exception' => $th::class,
                ],
                previous: $th,
            );
        }
    }
}

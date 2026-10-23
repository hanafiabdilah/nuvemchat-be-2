<?php

namespace App\Services\V1\SendMessage\Handlers;

use App\Enums\Connection\Channel;
use App\Models\Connection;
use App\Services\V1\SendMessage\OutgoingMessage;
use App\Services\V1\SendMessage\SendMessageHandlerInterface;
use App\Services\V1\SendMessage\SentMessageRecorder;
use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsappOfficialHandler implements SendMessageHandlerInterface
{
    public function handleSendMessage(Connection $connection, array $data): array
    {
        $outgoing = OutgoingMessage::fromData(Channel::WhatsappOfficial, $data, [
            'to' => 'required|string',
        ]);

        try {
            $response = Http::withToken($connection->credentials['access_token'])
                ->post('https://graph.facebook.com/v25.0/' . $connection->credentials['phone_number_id'] . '/messages', [
                    'messaging_product' => 'whatsapp',
                    'recipient_type' => 'individual',
                    'to' => $data['to'],
                ] + $this->content($outgoing));

            if(!$response->successful()) {
                Log::error('WhatsappOfficialHandler: Failed to send message', [
                    'response_status' => $response->status(),
                    'response_body' => $response->body(),
                    'connection_id' => $connection->id,
                ]);

                throw new Exception('Failed to send WhatsApp message: ' . $response->body());
            }

            $responseArray = $response->json() ?? [];
            $messageId = $responseArray['messages'][0]['id'] ?? null;

            Log::info('WhatsappOfficialHandler: Message sent successfully', [
                'connection_id' => $connection->id,
                'to' => $data['to'],
                'type' => $outgoing->type,
                'message_id' => $messageId,
            ]);

            // Filed under the number Meta resolved `to` into — the spelling its
            // webhook uses when they answer — not the one the caller typed.
            SentMessageRecorder::whatsapp(
                $connection,
                (string) ($responseArray['contacts'][0]['wa_id'] ?? $data['to']),
                $outgoing,
                $messageId,
                $responseArray,
            );

            return $responseArray;
        } catch (\Throwable $th) {
            Log::error('WhatsappOfficialHandler: Failed to send message', [
                'error' => $th->getMessage(),
                'connection_id' => $connection->id,
            ]);

            throw new Exception('Failed to send WhatsApp message: ' . $th->getMessage(), 0, $th);
        }
    }

    /**
     * The Cloud API names the object after its type — `text`, `image`… — and
     * fetches a `link` itself, so nothing is downloaded here.
     */
    private function content(OutgoingMessage $outgoing): array
    {
        if ($outgoing->isText()) {
            return ['type' => 'text', 'text' => ['body' => $outgoing->text]];
        }

        $media = ['link' => $outgoing->url()];

        if ($outgoing->caption() !== null) {
            $media['caption'] = $outgoing->caption();
        }

        if ($outgoing->type === 'document') {
            $media['filename'] = $outgoing->filename();
        }

        // What makes WhatsApp draw a voice note rather than a file attachment.
        // Meta ignores it for anything but Ogg/Opus (same rule as the
        // dashboard's WhatsappOfficialHandler::voiceNoteFields()).
        if ($outgoing->type === 'audio' && in_array($outgoing->extension(), ['ogg', 'opus'], true)) {
            $media['voice'] = true;
        }

        return ['type' => $outgoing->type, $outgoing->type => $media];
    }
}

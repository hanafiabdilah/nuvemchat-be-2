<?php

namespace App\Services\V1\SendMessage\Handlers;

use App\Enums\Connection\Channel;
use App\Models\Connection;
use App\Services\Message\Handlers\WhatsappApiwayHandler as PanelHandler;
use App\Services\V1\SendMessage\OutgoingMessage;
use App\Services\V1\SendMessage\SendMessageHandlerInterface;
use App\Services\V1\SendMessage\SentMessageRecorder;
use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsappApiwayHandler implements SendMessageHandlerInterface
{
    private function base(): string
    {
        return \App\Services\Connection\Proxy\ApiwayConfig::baseUrl();
    }

    public function handleSendMessage(Connection $connection, array $data): array
    {
        $outgoing = OutgoingMessage::fromData(Channel::WhatsappApiway, $data, [
            'phone' => 'required|string',
        ]);

        // One endpoint per kind: send-text, send-image, send-video, send-audio,
        // send-document. Each media endpoint takes the URL in the field named
        // after it, the same fast path the dashboard's handler uses.
        $endpoint = $outgoing->isText() ? 'send-text' : 'send-' . $outgoing->type;

        try {
            $payload = ['phone' => $data['phone']] + $this->content($outgoing);

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $connection->credentials['token'],
            ])->post($this->base() . '/v1/message/' . $endpoint . '?instanceId=' . $connection->credentials['instance_id'], $payload);

            if (!$response->successful()) {
                Log::error('WhatsappApiwayHandler: Failed to send message', [
                    'response_status' => $response->status(),
                    'response_body' => $response->body(),
                    'connection_id' => $connection->id,
                    'endpoint' => $endpoint,
                ]);

                throw new Exception('Failed to send WhatsApp message: ' . $response->body());
            }

            $responseArray = $response->json() ?? [];

            // The core keeps moving the id around its envelope; the panel's
            // handler already knows every place it has been seen.
            $messageId = (new PanelHandler())->getMessageId($responseArray);

            Log::info('WhatsappApiwayHandler: Message sent successfully', [
                'connection_id' => $connection->id,
                'phone' => $data['phone'],
                'type' => $outgoing->type,
                'message_id' => $messageId,
                'response' => $responseArray,
            ]);

            SentMessageRecorder::whatsapp($connection, $data['phone'], $outgoing, $messageId, $responseArray);

            return $responseArray;
        } catch (\Throwable $th) {
            Log::error('WhatsappApiwayHandler: Failed to send message', [
                'error' => $th->getMessage(),
                'connection_id' => $connection->id,
            ]);

            throw new Exception('Failed to send WhatsApp message: ' . $th->getMessage(), 0, $th);
        }
    }

    private function content(OutgoingMessage $outgoing): array
    {
        if ($outgoing->isText()) {
            return ['message' => $outgoing->text];
        }

        $fields = [$outgoing->type => $outgoing->url(), 'caption' => $outgoing->caption()];

        if ($outgoing->type === 'document') {
            $fields['fileName'] = $outgoing->filename();
            $fields['extension'] = $outgoing->extension() ?: null;
        }

        return array_filter($fields, fn ($value) => $value !== null);
    }
}

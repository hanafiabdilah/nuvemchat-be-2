<?php

namespace App\Services\Connection\TikTok;

use App\Models\Connection;
use App\Services\Connection\Channels\TikTokChannel;
use Exception;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Client for the TikTok Business Messaging endpoints that operate on a
 * connection (send, media). Access tokens only live ~24h, so every call
 * resolves a fresh token via TikTokChannel::ensureValidAccessToken().
 */
class TikTokMessagingClient
{
    protected string $businessId;

    public function __construct(protected Connection $connection)
    {
        $this->businessId = $connection->credentials['business_id'] ?? '';
    }

    /**
     * Send a text DM. Returns TikTok's message_id.
     */
    public function sendText(string $conversationId, string $text, ?string $referencedMessageId = null): string
    {
        $body = [
            'business_id' => $this->businessId,
            'recipient_type' => 'CONVERSATION',
            'recipient' => $conversationId,
            'message_type' => 'TEXT',
            'text' => ['body' => $text],
        ];

        if ($referencedMessageId) {
            $body['referenced_message_info'] = ['referenced_message_id' => $referencedMessageId];
        }

        $data = $this->request('post', '/business/message/send/', $body, 'Failed to send TikTok message');

        return $data['message']['message_id'] ?? '';
    }

    /**
     * Send an already-uploaded image. Returns TikTok's message_id.
     */
    public function sendImage(string $conversationId, string $mediaId): string
    {
        $data = $this->request('post', '/business/message/send/', [
            'business_id' => $this->businessId,
            'recipient_type' => 'CONVERSATION',
            'recipient' => $conversationId,
            'message_type' => 'IMAGE',
            'image' => ['media_id' => $mediaId],
        ], 'Failed to send TikTok image');

        return $data['message']['message_id'] ?? '';
    }

    /**
     * Upload an image and return its media_id. The Business Messaging API only
     * supports IMAGE uploads today.
     */
    public function uploadMedia(string $contents, string $filename, string $mimeType): string
    {
        $response = Http::acceptJson()
            ->withHeaders(['Access-Token' => $this->accessToken()])
            ->attach('file', $contents, $filename, ['Content-Type' => $mimeType])
            ->post(TikTokAuthClient::API_BASE . '/business/message/media/upload/', [
                'business_id' => $this->businessId,
                'media_type' => 'IMAGE',
            ]);

        $json = $response->json() ?? [];

        if (! $response->successful() || ($json['code'] ?? -1) !== 0) {
            Log::error('Failed to upload TikTok media', [
                'status' => $response->status(),
                'body' => $json,
            ]);

            throw new Exception('Failed to upload TikTok media: ' . ($json['message'] ?? 'HTTP ' . $response->status()));
        }

        return $json['data']['media_id'] ?? '';
    }

    /**
     * Resolve the short-lived CDN download URL of an inbound media file.
     */
    public function mediaDownloadUrl(string $conversationId, string $messageId, string $mediaId, string $mediaType = 'IMAGE'): string
    {
        $data = $this->request('post', '/business/message/media/download/', [
            'business_id' => $this->businessId,
            'conversation_id' => $conversationId,
            'message_id' => $messageId,
            'media_id' => $mediaId,
            'media_type' => $mediaType,
        ], 'Failed to fetch TikTok media download URL');

        return $data['download_url'] ?? '';
    }

    /**
     * Download the media binary. TikTok's CDN authorizes via the `x-user`
     * header carrying the access token.
     */
    public function downloadMedia(string $downloadUrl): Response
    {
        return Http::withHeaders(['x-user' => $this->accessToken()])->get($downloadUrl);
    }

    protected function accessToken(): string
    {
        return (new TikTokChannel())->ensureValidAccessToken($this->connection);
    }

    protected function request(string $method, string $path, array $payload, string $errorPrefix): array
    {
        $pending = Http::acceptJson()->withHeaders(['Access-Token' => $this->accessToken()]);

        $response = $method === 'get'
            ? $pending->get(TikTokAuthClient::API_BASE . $path, $payload)
            : $pending->asJson()->post(TikTokAuthClient::API_BASE . $path, $payload);

        $json = $response->json() ?? [];

        if (! $response->successful() || ($json['code'] ?? -1) !== 0) {
            Log::error($errorPrefix, [
                'connection_id' => $this->connection->id,
                'status' => $response->status(),
                'body' => $json,
            ]);

            throw new Exception($errorPrefix . ': ' . ($json['message'] ?? 'HTTP ' . $response->status()));
        }

        return $json['data'] ?? [];
    }
}

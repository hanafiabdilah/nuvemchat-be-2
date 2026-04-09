<?php

namespace App\Services\Message\Handlers;

use App\Enums\Message\MessageType;
use App\Enums\Message\SenderType;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Message\MessageHandlerInterface;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class InstagramHandler implements MessageHandlerInterface
{
    public function getMessageId(array $payload): string
    {
        return $payload['message_id'] ?? $payload['mid'] ?? uniqid('ig_', true);
    }

    public function getMessageSentAt(array $payload): Carbon
    {
        return Carbon::now();
    }

    public function handleSendMessage(Conversation $conversation, array $data): ?Message
    {
        validator($data, [
            'message' => 'required|string',
        ])->validate();

        $connection = $conversation->connection;

        try {
            $response = Http::withToken($connection->credentials['access_token'])
                ->post('https://graph.instagram.com/v25.0/me/messages', [
                    'recipient' => [
                        'id' => $conversation->external_id,
                    ],
                    'message' => [
                        'text' => $data['message'],
                    ],
                ]);

            $responseArray = $response->json();

            if (!$response->successful()) {
                Log::error('InstagramHandler: Failed to send message', [
                    'response' => $responseArray,
                    'conversation_id' => $conversation->id,
                ]);
                throw new Exception($responseArray['error']['message'] ?? 'Failed to send Instagram message');
            }

            Log::info('InstagramHandler: Message sent successfully', [
                'response' => $responseArray,
                'conversation_id' => $conversation->id,
            ]);

            $message = $conversation->messages()->create([
                'external_id' => $this->getMessageId($responseArray),
                'sender_type' => SenderType::Outgoing,
                'message_type' => MessageType::Text,
                'body' => $data['message'],
                'sent_at' => $this->getMessageSentAt($responseArray),
                'delivery_at' => $this->getMessageSentAt($responseArray),
                'meta' => $responseArray,
            ]);

            return $message;
        } catch (\Throwable $th) {
            Log::error('InstagramHandler: Failed to send message', [
                'error' => $th->getMessage(),
                'conversation_id' => $conversation->id,
                'connection_id' => $connection->id,
            ]);

            throw new Exception('Failed to send Instagram message: ' . $th->getMessage());
        }
    }

    public function handleSendImage(Conversation $conversation, array $data): ?Message
    {
        validator($data, [
            'url' => 'required|string|url',
        ])->validate();

        $connection = $conversation->connection;

        try {
            $response = Http::withToken($connection->credentials['access_token'])
                ->post('https://graph.instagram.com/v25.0/me/messages', [
                    'recipient' => [
                        'id' => $conversation->external_id,
                    ],
                    'message' => [
                        'attachment' => [
                            'type' => 'image',
                            'payload' => [
                                'url' => $data['url'],
                                'is_reusable' => $data['is_reusable'] ?? false,
                            ],
                        ],
                    ],
                ]);

            $responseArray = $response->json();

            if (!$response->successful()) {
                Log::error('InstagramHandler: Failed to send image', [
                    'response' => $responseArray,
                    'conversation_id' => $conversation->id,
                ]);
                throw new Exception($responseArray['error']['message'] ?? 'Failed to send Instagram image');
            }

            Log::info('InstagramHandler: Image sent successfully', [
                'response' => $responseArray,
                'conversation_id' => $conversation->id,
            ]);

            $message = $conversation->messages()->create([
                'external_id' => $this->getMessageId($responseArray),
                'sender_type' => SenderType::Outgoing,
                'message_type' => MessageType::Image,
                'body' => $data['url'],
                'sent_at' => $this->getMessageSentAt($responseArray),
                'delivery_at' => $this->getMessageSentAt($responseArray),
                'meta' => $responseArray,
            ]);

            return $message;
        } catch (\Throwable $th) {
            Log::error('InstagramHandler: Failed to send image', [
                'error' => $th->getMessage(),
                'conversation_id' => $conversation->id,
                'connection_id' => $connection->id,
            ]);

            throw new Exception('Failed to send Instagram image: ' . $th->getMessage());
        }
    }

    public function handleSendAudio(Conversation $conversation, array $data): ?Message
    {
        validator($data, [
            'url' => 'required|string|url',
        ])->validate();

        $connection = $conversation->connection;

        try {
            $response = Http::withToken($connection->credentials['access_token'])
                ->post('https://graph.instagram.com/v25.0/me/messages', [
                    'recipient' => [
                        'id' => $conversation->external_id,
                    ],
                    'message' => [
                        'attachment' => [
                            'type' => 'audio',
                            'payload' => [
                                'url' => $data['url'],
                                'is_reusable' => $data['is_reusable'] ?? false,
                            ],
                        ],
                    ],
                ]);

            $responseArray = $response->json();

            if (!$response->successful()) {
                Log::error('InstagramHandler: Failed to send audio', [
                    'response' => $responseArray,
                    'conversation_id' => $conversation->id,
                ]);
                throw new Exception($responseArray['error']['message'] ?? 'Failed to send Instagram audio');
            }

            Log::info('InstagramHandler: Audio sent successfully', [
                'response' => $responseArray,
                'conversation_id' => $conversation->id,
            ]);

            $message = $conversation->messages()->create([
                'external_id' => $this->getMessageId($responseArray),
                'sender_type' => SenderType::Outgoing,
                'message_type' => MessageType::Audio,
                'body' => $data['url'],
                'sent_at' => $this->getMessageSentAt($responseArray),
                'delivery_at' => $this->getMessageSentAt($responseArray),
                'meta' => $responseArray,
            ]);

            return $message;
        } catch (\Throwable $th) {
            Log::error('InstagramHandler: Failed to send audio', [
                'error' => $th->getMessage(),
                'conversation_id' => $conversation->id,
                'connection_id' => $connection->id,
            ]);

            throw new Exception('Failed to send Instagram audio: ' . $th->getMessage());
        }
    }

    public function handleSendVideo(Conversation $conversation, array $data): ?Message
    {
        validator($data, [
            'url' => 'required|string|url',
        ])->validate();

        $connection = $conversation->connection;

        try {
            $response = Http::withToken($connection->credentials['access_token'])
                ->post('https://graph.instagram.com/v25.0/me/messages', [
                    'recipient' => [
                        'id' => $conversation->external_id,
                    ],
                    'message' => [
                        'attachment' => [
                            'type' => 'video',
                            'payload' => [
                                'url' => $data['url'],
                                'is_reusable' => $data['is_reusable'] ?? false,
                            ],
                        ],
                    ],
                ]);

            $responseArray = $response->json();

            if (!$response->successful()) {
                Log::error('InstagramHandler: Failed to send video', [
                    'response' => $responseArray,
                    'conversation_id' => $conversation->id,
                ]);
                throw new Exception($responseArray['error']['message'] ?? 'Failed to send Instagram video');
            }

            Log::info('InstagramHandler: Video sent successfully', [
                'response' => $responseArray,
                'conversation_id' => $conversation->id,
            ]);

            $message = $conversation->messages()->create([
                'external_id' => $this->getMessageId($responseArray),
                'sender_type' => SenderType::Outgoing,
                'message_type' => MessageType::Video,
                'body' => $data['url'],
                'sent_at' => $this->getMessageSentAt($responseArray),
                'delivery_at' => $this->getMessageSentAt($responseArray),
                'meta' => $responseArray,
            ]);

            return $message;
        } catch (\Throwable $th) {
            Log::error('InstagramHandler: Failed to send video', [
                'error' => $th->getMessage(),
                'conversation_id' => $conversation->id,
                'connection_id' => $connection->id,
            ]);

            throw new Exception('Failed to send Instagram video: ' . $th->getMessage());
        }
    }

    public function handleSendDocument(Conversation $conversation, array $data): ?Message
    {
        validator($data, [
            'url' => 'required|string|url',
        ])->validate();

        $connection = $conversation->connection;

        try {
            $response = Http::withToken($connection->credentials['access_token'])
                ->post('https://graph.instagram.com/v25.0/me/messages', [
                    'recipient' => [
                        'id' => $conversation->external_id,
                    ],
                    'message' => [
                        'attachment' => [
                            'type' => 'file',
                            'payload' => [
                                'url' => $data['url'],
                                'is_reusable' => $data['is_reusable'] ?? false,
                            ],
                        ],
                    ],
                ]);

            $responseArray = $response->json();

            if (!$response->successful()) {
                Log::error('InstagramHandler: Failed to send document', [
                    'response' => $responseArray,
                    'conversation_id' => $conversation->id,
                ]);
                throw new Exception($responseArray['error']['message'] ?? 'Failed to send Instagram document');
            }

            Log::info('InstagramHandler: Document sent successfully', [
                'response' => $responseArray,
                'conversation_id' => $conversation->id,
            ]);

            $message = $conversation->messages()->create([
                'external_id' => $this->getMessageId($responseArray),
                'sender_type' => SenderType::Outgoing,
                'message_type' => MessageType::Document,
                'body' => $data['url'],
                'sent_at' => $this->getMessageSentAt($responseArray),
                'delivery_at' => $this->getMessageSentAt($responseArray),
                'meta' => $responseArray,
            ]);

            return $message;
        } catch (\Throwable $th) {
            Log::error('InstagramHandler: Failed to send document', [
                'error' => $th->getMessage(),
                'conversation_id' => $conversation->id,
                'connection_id' => $connection->id,
            ]);

            throw new Exception('Failed to send Instagram document: ' . $th->getMessage());
        }
    }
}

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
use Illuminate\Support\Facades\Storage;

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
            'image' => 'required|image|mimes:jpeg,png,jpg|max:8192',
        ])->validate();

        $connection = $conversation->connection;
        $tempPublicPath = null;

        try {
            // Store image temporarily in public directory
            $imageContent = file_get_contents($data['image']->getRealPath());
            $tempFileName = 'temp_' . uniqid() . '.' . $data['image']->getClientOriginalExtension();
            $tempPublicPath = 'images/' . $tempFileName;

            Storage::disk('public')->put($tempPublicPath, $imageContent);

            // Generate public URL
            $imageUrl = url('storage/' . $tempPublicPath);

            Log::info('InstagramHandler: Sending image via URL', [
                'url' => $imageUrl,
                'size' => strlen($imageContent),
                'conversation_id' => $conversation->id,
            ]);

            $response = Http::withToken($connection->credentials['access_token'])
                ->post('https://graph.instagram.com/v25.0/me/messages', [
                    'recipient' => [
                        'id' => $conversation->external_id,
                    ],
                    'message' => [
                        'attachment' => [
                            'type' => 'image',
                            'payload' => [
                                'url' => $imageUrl,
                                'is_reusable' => true,
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
                'body' => null,
                'sent_at' => $this->getMessageSentAt($responseArray),
                'delivery_at' => $this->getMessageSentAt($responseArray),
                'meta' => $responseArray,
            ]);

            $mediaPath = 'media/' . $message->id . '_' . uniqid() . '.' . $data['image']->getClientOriginalExtension();
            Storage::disk('local')->put($mediaPath, $imageContent);

            $message->update([
                'attachment' => $mediaPath,
            ]);

            // Delete temporary public file
            // Storage::disk('public')->delete($tempPublicPath);

            return $message;
        } catch (\Throwable $th) {
            // Clean up temporary file if exists
            if ($tempPublicPath && Storage::disk('public')->exists($tempPublicPath)) {
                Storage::disk('public')->delete($tempPublicPath);
            }

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
            'audio' => 'required|file|mimes:aac,m4a,wav,mp4,mp3,ogg,opus,webm|max:25600',
        ])->validate();

        $connection = $conversation->connection;
        $tempPublicPath = null;
        $convertedFilePath = null;

        try {
            $audioContent = file_get_contents($data['audio']->getRealPath());

            if ($audioContent === false) {
                throw new Exception('Failed to read uploaded audio file');
            }

            if (strlen($audioContent) === 0) {
                throw new Exception('Uploaded audio file is empty');
            }

            $extension = strtolower($data['audio']->getClientOriginalExtension());
            $originalExtension = $extension;

            Log::info('InstagramHandler: Audio file received', [
                'original_format' => $extension,
                'original_size' => strlen($audioContent),
                'file_path' => $data['audio']->getRealPath(),
            ]);

            // Instagram supported formats: aac, m4a, wav, mp4
            $supportedFormats = ['aac', 'm4a', 'wav', 'mp4'];

            // Convert if not in supported formats
            if (!in_array($extension, $supportedFormats)) {
                Log::info('InstagramHandler: Converting audio to MP4', [
                    'original_format' => $extension,
                    'conversation_id' => $conversation->id,
                ]);

                $inputPath = $data['audio']->getRealPath();
                $convertedFilePath = sys_get_temp_dir() . '/' . uniqid() . '.mp4';

                // Convert using FFmpeg to MP4 container with AAC codec
                $command = sprintf(
                    'ffmpeg -y -i %s -c:a aac -b:a 128k %s 2>&1',
                    escapeshellarg($inputPath),
                    escapeshellarg($convertedFilePath)
                );

                exec($command, $output, $returnVar);

                if ($returnVar !== 0 || !file_exists($convertedFilePath)) {
                    Log::error('InstagramHandler: Audio conversion failed', [
                        'command' => $command,
                        'output' => $output,
                        'return_var' => $returnVar,
                    ]);
                    throw new Exception('Failed to convert audio format. FFmpeg error: ' . implode("\n", $output));
                }

                // Verify converted file is valid
                if (filesize($convertedFilePath) === 0) {
                    @unlink($convertedFilePath);
                    throw new Exception('Audio conversion produced empty file');
                }

                $audioContent = file_get_contents($convertedFilePath);

                if ($audioContent === false) {
                    @unlink($convertedFilePath);
                    throw new Exception('Failed to read converted audio file');
                }

                if (strlen($audioContent) === 0) {
                    @unlink($convertedFilePath);
                    throw new Exception('Converted audio file is empty');
                }

                $extension = 'mp4';

                Log::info('InstagramHandler: Audio converted successfully', [
                    'from' => $originalExtension,
                    'to' => $extension,
                    'converted_size' => strlen($audioContent),
                    'file_size' => filesize($convertedFilePath),
                    'original_size' => strlen(file_get_contents($data['audio']->getRealPath())),
                ]);
            } else {
                Log::info('InstagramHandler: Audio format supported, no conversion needed', [
                    'format' => $extension,
                    'size' => strlen($audioContent),
                ]);
            }

            // Store audio temporarily in public directory
            $tempFileName = 'temp_' . uniqid() . '.' . $extension;
            $tempPublicPath = 'audios/' . $tempFileName;

            $saved = Storage::disk('public')->put($tempPublicPath, $audioContent);

            if (!$saved || !Storage::disk('public')->exists($tempPublicPath)) {
                throw new Exception('Failed to save audio file to public storage');
            }

            // Verify file was saved correctly
            $savedSize = Storage::disk('public')->size($tempPublicPath);
            if ($savedSize === 0) {
                Storage::disk('public')->delete($tempPublicPath);
                throw new Exception('Saved audio file is empty');
            }

            // Generate public URL
            $audioUrl = url('storage/' . $tempPublicPath);

            Log::info('InstagramHandler: Sending audio via URL', [
                'url' => $audioUrl,
                'format' => $extension,
                'size' => strlen($audioContent),
                'saved_size' => $savedSize,
                'full_path' => Storage::disk('public')->path($tempPublicPath),
                'conversation_id' => $conversation->id,
            ]);

            $response = Http::withToken($connection->credentials['access_token'])
                ->post('https://graph.instagram.com/v25.0/me/messages', [
                    'recipient' => [
                        'id' => $conversation->external_id,
                    ],
                    'message' => [
                        'attachment' => [
                            'type' => 'audio',
                            'payload' => [
                                'url' => $audioUrl,
                                'is_reusable' => true,
                            ],
                        ],
                    ],
                ]);

            $responseArray = $response->json();

            if (!$response->successful()) {
                Log::error('InstagramHandler: Failed to send audio', [
                    'response' => $responseArray,
                    'conversation_id' => $conversation->id,
                    'audio_url' => $audioUrl,
                    'audio_format' => $extension,
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
                'body' => null,
                'sent_at' => $this->getMessageSentAt($responseArray),
                'delivery_at' => $this->getMessageSentAt($responseArray),
                'meta' => $responseArray,
            ]);

            // Store the converted audio content permanently
            $mediaPath = 'media/' . $message->id . '_' . uniqid() . '.' . $extension;
            Storage::disk('local')->put($mediaPath, $audioContent);

            $message->update([
                'attachment' => $mediaPath,
            ]);

            // Clean up converted file if exists
            if ($convertedFilePath && file_exists($convertedFilePath)) {
                @unlink($convertedFilePath);
            }

            // Delete temporary public file
            // Storage::disk('public')->delete($tempPublicPath);

            return $message;
        } catch (\Throwable $th) {
            // Clean up temporary files if exist
            if ($tempPublicPath && Storage::disk('public')->exists($tempPublicPath)) {
                Storage::disk('public')->delete($tempPublicPath);
            }

            if ($convertedFilePath && file_exists($convertedFilePath)) {
                @unlink($convertedFilePath);
            }

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
            'video' => 'required|file|mimes:mp4,ogg,avi,mov,webm|max:25600',
        ])->validate();

        $connection = $conversation->connection;
        $tempPublicPath = null;

        try {
            // Store video temporarily in public directory
            $videoContent = file_get_contents($data['video']->getRealPath());
            $tempFileName = 'temp_' . uniqid() . '.' . $data['video']->getClientOriginalExtension();
            $tempPublicPath = 'videos/' . $tempFileName;

            Storage::disk('public')->put($tempPublicPath, $videoContent);

            // Generate public URL
            $videoUrl = url('storage/' . $tempPublicPath);

            Log::info('InstagramHandler: Sending video via URL', [
                'url' => $videoUrl,
                'size' => strlen($videoContent),
                'conversation_id' => $conversation->id,
            ]);

            $response = Http::withToken($connection->credentials['access_token'])
                ->post('https://graph.instagram.com/v25.0/me/messages', [
                    'recipient' => [
                        'id' => $conversation->external_id,
                    ],
                    'message' => [
                        'attachment' => [
                            'type' => 'video',
                            'payload' => [
                                'url' => $videoUrl,
                                'is_reusable' => true,
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
                'body' => null,
                'sent_at' => $this->getMessageSentAt($responseArray),
                'delivery_at' => $this->getMessageSentAt($responseArray),
                'meta' => $responseArray,
            ]);

            $mediaPath = 'media/' . $message->id . '_' . uniqid() . '.' . $data['video']->getClientOriginalExtension();
            Storage::disk('local')->put($mediaPath, $videoContent);

            $message->update([
                'attachment' => $mediaPath,
            ]);

            // Delete temporary public file
            // Storage::disk('public')->delete($tempPublicPath);

            return $message;
        } catch (\Throwable $th) {
            // Clean up temporary file if exists
            if ($tempPublicPath && Storage::disk('public')->exists($tempPublicPath)) {
                Storage::disk('public')->delete($tempPublicPath);
            }

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
            'document' => 'required|file|mimes:pdf|max:25600',
        ])->validate();

        $connection = $conversation->connection;
        $tempPublicPath = null;

        try {
            // Store document temporarily in public directory
            $documentContent = file_get_contents($data['document']->getRealPath());
            $tempFileName = 'temp_' . uniqid() . '.' . $data['document']->getClientOriginalExtension();
            $tempPublicPath = 'documents/' . $tempFileName;

            Storage::disk('public')->put($tempPublicPath, $documentContent);

            // Generate public URL
            $documentUrl = url('storage/' . $tempPublicPath);

            // Get original filename
            $filename = $data['document']->getClientOriginalName();

            Log::info('InstagramHandler: Sending document via URL', [
                'url' => $documentUrl,
                'filename' => $filename,
                'size' => strlen($documentContent),
                'conversation_id' => $conversation->id,
            ]);

            $response = Http::withToken($connection->credentials['access_token'])
                ->post('https://graph.instagram.com/v25.0/me/messages', [
                    'recipient' => [
                        'id' => $conversation->external_id,
                    ],
                    'message' => [
                        'attachment' => [
                            'type' => 'file',
                            'payload' => [
                                'url' => $documentUrl,
                                'is_reusable' => true,
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
                'body' => null,
                'sent_at' => $this->getMessageSentAt($responseArray),
                'delivery_at' => $this->getMessageSentAt($responseArray),
                'meta' => array_merge($responseArray, ['filename' => $filename]),
            ]);

            $mediaPath = 'media/' . $message->id . '_' . uniqid() . '.' . $data['document']->getClientOriginalExtension();
            Storage::disk('local')->put($mediaPath, $documentContent);

            $message->update([
                'attachment' => $mediaPath,
            ]);

            // Delete temporary public file
            // Storage::disk('public')->delete($tempPublicPath);

            return $message;
        } catch (\Throwable $th) {
            // Clean up temporary file if exists
            if ($tempPublicPath && Storage::disk('public')->exists($tempPublicPath)) {
                Storage::disk('public')->delete($tempPublicPath);
            }

            Log::error('InstagramHandler: Failed to send document', [
                'error' => $th->getMessage(),
                'conversation_id' => $conversation->id,
                'connection_id' => $connection->id,
            ]);

            throw new Exception('Failed to send Instagram document: ' . $th->getMessage());
        }
    }

    public function handleEditMessage(Message $message, array $data): ?Message
    {
        throw new Exception('Message editing not implemented for Instagram API');
    }

    public function handleDeleteMessage(Message $message): bool
    {
        throw new Exception('Message deletion not supported for Instagram API');
    }
}

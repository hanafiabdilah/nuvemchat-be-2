<?php

namespace App\Services\Contact\Photo\Resolvers;

use App\Models\Connection;
use App\Models\Contact;
use App\Services\Contact\Photo\PhotoResolver;
use App\Services\Contact\Photo\PhotoSource;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Telegram exposes pictures through two different calls, and neither works for
 * the other kind of chat:
 *   - people  => getUserProfilePhotos, an array of size variants
 *   - groups  => getChat, whose chat.photo carries big_file_id
 * Both then need getFile to turn a file_id into a downloadable path.
 */
class TelegramPhotoResolver implements PhotoResolver
{
    public function resolve(Contact $contact, Connection $connection): ?PhotoSource
    {
        $token = $connection->credentials['token'] ?? null;

        if (! $token) {
            throw new RuntimeException('Telegram connection has no bot token');
        }

        $fileId = $contact->is_group
            ? $this->groupPhotoFileId($token, $contact->external_id)
            : $this->userPhotoFileId($token, $contact->external_id);

        if (! $fileId) {
            return null;
        }

        $response = Http::timeout(20)->get("https://api.telegram.org/bot{$token}/getFile", [
            'file_id' => $fileId,
        ]);

        if ($response->failed()) {
            throw new RuntimeException('Telegram getFile failed: ' . $response->status());
        }

        $filePath = $response->json('result.file_path');

        if (! $filePath) {
            throw new RuntimeException('Telegram getFile returned no file_path');
        }

        return new PhotoSource(
            url: "https://api.telegram.org/file/bot{$token}/{$filePath}",
            extension: $this->extensionFromPath($filePath),
        );
    }

    private function userPhotoFileId(string $token, string $userId): ?string
    {
        $response = Http::timeout(20)->get("https://api.telegram.org/bot{$token}/getUserProfilePhotos", [
            'user_id' => $userId,
            'limit' => 1,
        ]);

        if ($response->failed()) {
            throw new RuntimeException('Telegram getUserProfilePhotos failed: ' . $response->status());
        }

        $photos = $response->json('result.photos');

        if (empty($photos) || empty($photos[0])) {
            // Confirmed: this user has no picture, or hides it from the bot.
            return null;
        }

        // Sizes come ascending — the last entry is the largest.
        $sizes = $photos[0];

        return $sizes[count($sizes) - 1]['file_id'] ?? null;
    }

    private function groupPhotoFileId(string $token, string $chatId): ?string
    {
        $response = Http::timeout(20)->get("https://api.telegram.org/bot{$token}/getChat", [
            'chat_id' => $chatId,
        ]);

        if ($response->failed()) {
            throw new RuntimeException('Telegram getChat failed: ' . $response->status());
        }

        // No chat.photo at all means the group never set one (or the bot was
        // removed) — either way there is nothing to store.
        return $response->json('result.photo.big_file_id')
            ?? $response->json('result.photo.small_file_id');
    }

    private function extensionFromPath(string $filePath): ?string
    {
        $parts = explode('.', $filePath);

        return count($parts) > 1 ? end($parts) : null;
    }
}

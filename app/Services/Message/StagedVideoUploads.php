<?php

namespace App\Services\Message;

use App\Models\Conversation;
use App\Models\User;
use App\Services\Media\ChunkedUpload;
use App\Services\Media\MediaFilename;
use App\Services\Media\MediaStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * A video the composer uploaded in pieces, waiting for the send that names it.
 *
 * Two requests rather than one because of size: the composer takes videos up to
 * `media.video.max_upload_mb` (200 MB), PHP takes at most 64 MB in one request,
 * and a proxy in front may take less. So the file arrives in 8 MB pieces
 * (ChunkedUpload), lands on the staging disk, and the send request carries the
 * token handed out here instead of the bytes.
 *
 * The token is bound to the person and the conversation it was uploaded for:
 * it is a capability to send a specific file, and naming it from another
 * thread, or as somebody else, finds nothing.
 */
final class StagedVideoUploads
{
    /** By the name the browser gave; ChunkedUpload then checks the content sniffs as video. */
    public const EXTENSIONS = ['mp4', 'mov', 'm4v', 'avi', 'wmv', 'flv', 'webm', 'mkv', '3gp'];

    /** Long enough for an agent to type a caption over a slow upload; the file is swept after STALE_HOURS. */
    private const TTL_MINUTES = 120;

    /** A staged file older than this was never sent, or its job died; it is deleted. */
    public const STALE_HOURS = 24;

    public static function maxBytes(): int
    {
        return max(1, (int) config('media.video.max_upload_mb', 200)) * 1024 * 1024;
    }

    public function chunks(): ChunkedUpload
    {
        return new ChunkedUpload(
            'chat-video',
            self::EXTENSIONS,
            self::maxBytes(),
            MediaStorage::staging(),
            'video/',
            'Escolha um arquivo de vídeo (MP4, MOV, WEBM…).',
        );
    }

    public static function directory(int $tenantId): string
    {
        return 'chat-video/'.$tenantId;
    }

    /**
     * Hand the assembled file a token the send request can name.
     *
     * @param  array{path: string, name: string, size: int, mime?: ?string}  $file  ChunkedUpload's result
     */
    public function remember(User $user, Conversation $conversation, array $file): string
    {
        $token = Str::random(40);

        Cache::put($this->key($token), [
            'user_id' => $user->id,
            'conversation_id' => $conversation->id,
            'path' => $file['path'],
            'name' => $file['name'],
            'size' => (int) $file['size'],
        ], now()->addMinutes(self::TTL_MINUTES));

        return $token;
    }

    /**
     * The staged file a token names, when it belongs to this person and thread
     * and is still on disk.
     *
     * @return array{path: string, name: string, size: int}|null
     */
    public function find(string $token, User $user, Conversation $conversation): ?array
    {
        $entry = Cache::get($this->key($token));

        if (! is_array($entry)
            || (int) ($entry['user_id'] ?? 0) !== (int) $user->id
            || (int) ($entry['conversation_id'] ?? 0) !== (int) $conversation->id
            || ! MediaStorage::staging()->exists($entry['path'] ?? '')) {
            return null;
        }

        return ['path' => $entry['path'], 'name' => $entry['name'], 'size' => (int) $entry['size']];
    }

    /** The token is spent: whatever happens to the file now belongs to whoever took it. */
    public function forgetToken(string $token): void
    {
        Cache::forget($this->key($token));
    }

    public static function absolutePath(string $path): string
    {
        return MediaStorage::staging()->path($path);
    }

    /** The staged file as an upload a channel handler accepts. */
    public static function asUploadedFile(array $entry): UploadedFile
    {
        return new UploadedFile(self::absolutePath($entry['path']), $entry['name'], null, null, true);
    }

    /**
     * Keep a file that came in one request (under PHP's limit) for the queued
     * job: PHP deletes its upload temp file when the request ends.
     *
     * @return array{path: string, name: string, size: int}
     */
    public static function keep(UploadedFile $file, int $tenantId): array
    {
        $name = $file->getClientOriginalName() ?: 'video.mp4';
        $path = MediaFilename::path(
            self::directory($tenantId),
            MediaFilename::token(),
            $name,
            strtolower($file->getClientOriginalExtension()) ?: 'mp4',
        );

        MediaStorage::staging()->putFileAs(dirname($path), $file, basename($path));

        return ['path' => $path, 'name' => basename($path), 'size' => (int) $file->getSize()];
    }

    /**
     * A staged file and the one-file directory MediaFilename made for it.
     * Also used by the gallery for its own large uploads (same disk).
     */
    public static function delete(string $path): void
    {
        $disk = MediaStorage::staging();

        $disk->delete($path);

        $directory = dirname($path);

        if ($directory !== '.' && $directory !== '' && $disk->files($directory) === [] && $disk->directories($directory) === []) {
            $disk->deleteDirectory($directory);
        }
    }

    /**
     * Staged files nobody sent, or whose job died before cleaning up — the
     * composer's videos and the gallery's large uploads alike, since both land
     * on the staging disk and neither is meant to outlive a day there.
     */
    public static function sweep(): int
    {
        $disk = MediaStorage::staging();
        $cutoff = now()->subHours(self::STALE_HOURS)->getTimestamp();
        $removed = 0;

        foreach ($disk->allFiles() as $path) {
            if ($disk->lastModified($path) < $cutoff) {
                self::delete($path);
                $removed++;
            }
        }

        return $removed;
    }

    private function key(string $token): string
    {
        return 'staged-video:'.hash('sha256', $token);
    }
}

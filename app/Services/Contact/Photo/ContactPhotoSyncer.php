<?php

namespace App\Services\Contact\Photo;

use App\Enums\Conversation\Status;
use App\Events\ConversationUpdated;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Keeps `contacts.photo_profile` in step with the channel.
 *
 * Photos used to be downloaded once, inline, gated on wasRecentlyCreated — so
 * they never followed a change, and several blocking HTTP round-trips ran
 * inside the message-ingest transaction. This runs off the queue instead
 * (see SyncContactPhoto) and re-checks on a TTL.
 *
 * Works for group contacts too: a group is just a Contact with is_group, and
 * each resolver knows which channel call applies.
 */
class ContactPhotoSyncer
{
    /** How long a fetched photo is trusted before it is checked again. */
    public const TTL_HOURS = 24;

    /**
     * How long to wait after a failed lookup. Without this, a connection with
     * an expired token would fire one doomed request per inbound message, for
     * every member of a busy group.
     */
    public const RETRY_HOURS = 1;

    /**
     * True when this contact is worth a (queued) lookup right now. Checked
     * before dispatch so ingest bursts don't pile identical jobs on the queue.
     */
    public static function isStale(Contact $contact): bool
    {
        if (! $contact->channel || ! PhotoResolverFactory::supports($contact->channel)) {
            return false;
        }

        return $contact->photo_synced_at === null
            || $contact->photo_synced_at->lt(now()->subHours(self::TTL_HOURS));
    }

    /**
     * Re-read the picture and store it if it changed. Returns true when
     * `photo_profile` actually moved.
     */
    public function sync(Contact $contact, Connection $connection): bool
    {
        $resolver = PhotoResolverFactory::for($contact->channel);

        if (! $resolver) {
            return false;
        }

        try {
            $source = $resolver->resolve($contact, $connection);
        } catch (Throwable $e) {
            // Transient: keep whatever we have, and back off rather than retry
            // on the very next inbound message.
            Log::warning('ContactPhotoSyncer: lookup failed', [
                'contact_id' => $contact->id,
                'channel' => $contact->channel->value,
                'error' => $e->getMessage(),
            ]);

            $this->backOff($contact);

            return false;
        }

        if (! $source) {
            // The channel confirmed there is no picture — drop a stale one.
            return $this->clear($contact);
        }

        try {
            $response = Http::timeout(30)->get($source->url);
        } catch (Throwable $e) {
            Log::warning('ContactPhotoSyncer: download failed', [
                'contact_id' => $contact->id,
                'error' => $e->getMessage(),
            ]);

            $this->backOff($contact);

            return false;
        }

        if (! $response->successful()) {
            Log::warning('ContactPhotoSyncer: download returned an error', [
                'contact_id' => $contact->id,
                'status' => $response->status(),
            ]);

            $this->backOff($contact);

            return false;
        }

        $body = $response->body();

        if ($body === '') {
            $this->backOff($contact);

            return false;
        }

        $previousPath = $contact->photo_profile;

        // Every channel hands out a fresh URL per fetch (signed CDN links,
        // rotating file paths), so only the bytes can tell us the picture is
        // unchanged. Without this, each TTL pass would rewrite the file and
        // push a pointless conversation-updated to every open dashboard.
        if ($previousPath && $this->isSameImage($previousPath, $body)) {
            $contact->forceFill(['photo_synced_at' => now()])->save();

            return false;
        }

        $extension = $source->extension
            ?: $this->extensionFromMimeType($response->header('Content-Type'));

        $path = 'profile_photos/' . $contact->id . '_' . uniqid() . '.' . $extension;
        Storage::disk('local')->put($path, $body);

        $contact->forceFill([
            'photo_profile' => $path,
            'photo_synced_at' => now(),
        ])->save();

        $this->deleteFile($previousPath);
        $this->broadcastToOpenConversations($contact);

        Log::info('ContactPhotoSyncer: photo updated', [
            'contact_id' => $contact->id,
            'is_group' => $contact->is_group,
            'path' => $path,
        ]);

        return true;
    }

    /**
     * Forget the stored picture (the channel says there is none — e.g. a
     * Telegram delete_chat_photo service message).
     */
    public function clear(Contact $contact): bool
    {
        $previousPath = $contact->photo_profile;

        $contact->forceFill([
            'photo_profile' => null,
            'photo_synced_at' => now(),
        ])->save();

        if (! $previousPath) {
            return false;
        }

        $this->deleteFile($previousPath);
        $this->broadcastToOpenConversations($contact);

        return true;
    }

    /**
     * The dashboard renders the avatar off the conversation row, so a changed
     * photo only reaches an open inbox through conversation-updated.
     */
    private function broadcastToOpenConversations(Contact $contact): void
    {
        $conversations = Conversation::with(['contact', 'connection'])
            ->where('contact_id', $contact->id)
            ->whereIn('status', [Status::Active, Status::Pending, Status::AiHandling])
            ->get();

        foreach ($conversations as $conversation) {
            broadcast(new ConversationUpdated($conversation));
        }
    }

    /**
     * Push the next attempt out by RETRY_HOURS by back-dating the stamp — the
     * TTL is the only scheduling dial there is, so a failure is recorded as a
     * check that is already most of the way to expiring.
     */
    private function backOff(Contact $contact): void
    {
        $contact->forceFill([
            'photo_synced_at' => now()->subHours(self::TTL_HOURS - self::RETRY_HOURS),
        ])->save();
    }

    private function isSameImage(string $storedPath, string $body): bool
    {
        $disk = Storage::disk('local');

        if (! $disk->exists($storedPath)) {
            return false;
        }

        return md5($disk->get($storedPath)) === md5($body);
    }

    private function deleteFile(?string $path): void
    {
        if (! $path) {
            return;
        }

        try {
            Storage::disk('local')->delete($path);
        } catch (Throwable $e) {
            Log::warning('ContactPhotoSyncer: could not delete the previous photo', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function extensionFromMimeType(?string $mimeType): string
    {
        return match (explode(';', (string) $mimeType)[0]) {
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            default => 'jpg',
        };
    }
}

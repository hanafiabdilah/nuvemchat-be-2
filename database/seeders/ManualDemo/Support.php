<?php

namespace Database\Seeders\ManualDemo;

use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Shared state and helpers for the manual demo workspace.
 *
 * Everything is written with forceFill + save rather than create(): half of
 * these rows carry timestamps in the past (the statistics page is useless on a
 * workspace born a minute ago), and create() would stamp them all "now".
 */
trait Support
{
    protected Carbon $now;
    protected ?string $mediaDir = null;
    protected Tenant $tenant;

    /** @var array<string, \App\Models\User> */
    protected array $users = [];
    /** @var array<string, Connection> */
    protected array $conn = [];
    /** @var array<string, \App\Models\Tag> */
    protected array $tags = [];
    /** @var array<string, \App\Models\Flow> */
    protected array $flows = [];
    /** @var array<string, \App\Models\FlowNode> */
    protected array $nodes = [];
    /** @var array<string, \App\Models\AiHubAgent> */
    protected array $ai = [];
    /** @var array<string, \App\Models\AiHubProviderCredential> */
    protected array $creds = [];
    /** @var array<string, Contact> named contacts, reused across sections */
    protected array $people = [];
    /** @var array<string, array<int, Contact>> contacts per connection key, for history */
    protected array $pool = [];
    /** @var array<string, string> */
    protected array $copied = [];

    protected function make(string $class, array $attributes): Model
    {
        /** @var Model $model */
        $model = new $class();
        $model->forceFill($attributes);
        $model->save();

        return $model;
    }

    protected function ago(int $minutes): Carbon
    {
        return $this->now->copy()->subMinutes($minutes);
    }

    /** A wall-clock moment in São Paulo, N days ago, handed back in UTC. */
    protected function brt(int $daysAgo, int $hour, int $minute = 0): Carbon
    {
        return Carbon::now('America/Sao_Paulo')->subDays($daysAgo)->setTime($hour, $minute)->utc();
    }

    /** Copies a generated demo file onto the private disk once and returns its path. */
    protected function media(string $file, string $folder = 'media/manual-demo'): ?string
    {
        $path = $folder . '/' . $file;

        if (isset($this->copied[$path])) {
            return $path;
        }

        $source = $this->mediaDir ? rtrim($this->mediaDir, '/') . '/' . $file : null;

        if ($source === null || ! is_file($source)) {
            $this->command?->warn("Manual demo: media file missing ({$file}) — attachment left empty.");

            return null;
        }

        Storage::disk('local')->put($path, file_get_contents($source));

        return $this->copied[$path] = $path;
    }

    /** Public URL for media the platform references by link (carousels, IG, templates). */
    protected function mediaUrl(string $file): string
    {
        return rtrim((string) env('MANUAL_DEMO_MEDIA_URL', 'http://127.0.0.1:8099'), '/') . '/' . $file;
    }

    protected function contact(Connection $connection, string $externalId, string $name, array $extra = []): Contact
    {
        $photo = $extra['photo'] ?? null;
        unset($extra['photo']);

        $created = $extra['created_at'] ?? $this->now->copy()->subDays(75);

        /** @var Contact $contact */
        $contact = $this->make(Contact::class, array_merge([
            'tenant_id' => $this->tenant->id,
            'external_id' => $externalId,
            'name' => $name,
            'channel' => $connection->channel,
            'created_at' => $created,
            'updated_at' => $created,
        ], $extra));

        if ($photo && ($path = $this->media($photo, 'avatars/manual-demo'))) {
            $contact->forceFill(['photo_profile' => $path, 'photo_synced_at' => $this->now])->saveQuietly();
        }

        return $contact;
    }

    protected function conversation(Connection $connection, Contact $contact, array $attributes = []): Conversation
    {
        /** @var Conversation $conversation */
        $conversation = $this->make(Conversation::class, array_merge([
            'connection_id' => $connection->id,
            'contact_id' => $contact->id,
            'external_id' => $contact->external_id,
            'status' => 'active',
            'type' => 'private',
        ], $attributes));

        return $conversation;
    }

    /**
     * One message. `$direction` is "in" (customer) or "out" (us). Messages must
     * be written oldest first: Message::created moves last_message_at forward.
     */
    protected function msg(Conversation $conversation, string $direction, Carbon $at, array $attributes = []): Message
    {
        $message = new Message();
        $message->forceFill(array_merge([
            'conversation_id' => $conversation->id,
            'sender_type' => $direction === 'in' ? 'incoming' : 'outgoing',
            'message_type' => 'text',
            'sent_at' => $at,
        ], $attributes));
        $message->created_at = $attributes['created_at'] ?? $at;
        $message->updated_at = $attributes['updated_at'] ?? $at;
        $message->save();

        return $message;
    }

    /** Outgoing text that reached the customer and was read. */
    protected function sent(Conversation $conversation, Carbon $at, string $body, array $attributes = []): Message
    {
        return $this->msg($conversation, 'out', $at, array_merge([
            'body' => $body,
            'delivery_at' => $at->copy()->addSeconds(4),
            'read_at' => $at->copy()->addMinutes(1),
        ], $attributes));
    }

    /** Incoming text, already read by the team unless `$unread`. */
    protected function received(Conversation $conversation, Carbon $at, string $body, bool $unread = false, array $attributes = []): Message
    {
        return $this->msg($conversation, 'in', $at, array_merge([
            'body' => $body,
            'read_at' => $unread ? null : $at->copy()->addMinutes(1),
        ], $attributes));
    }

    /** A platform note in the thread (transfers, status changes, calls…). */
    protected function note(Conversation $conversation, Carbon $at, string $code, array $params, string $body): Message
    {
        return $this->msg($conversation, 'out', $at, [
            'message_type' => 'info',
            'body' => $body,
            'meta' => ['info' => array_filter(['code' => $code, 'params' => $params ?: null])],
        ]);
    }

    protected function react(Message $message, string $emoji, string $senderType = 'incoming', ?int $contactId = null): void
    {
        $at = $message->created_at->copy()->addMinutes(2);

        DB::table('message_reactions')->insert([
            'message_id' => $message->id,
            'emoji' => $emoji,
            'sender_type' => $senderType,
            'contact_id' => $contactId,
            'created_at' => $at,
            'updated_at' => $at,
        ]);
    }

    /** @param array<int, string> $keys */
    protected function tagConversation(Conversation $conversation, array $keys): void
    {
        foreach ($keys as $key) {
            DB::table('conversation_tags')->insert([
                'conversation_id' => $conversation->id,
                'tag_id' => $this->tags[$key]->id,
                'created_at' => $this->now,
                'updated_at' => $this->now,
            ]);
        }
    }

    /** @param array<int, string> $keys */
    protected function tagContact(Contact $contact, array $keys): void
    {
        foreach ($keys as $key) {
            DB::table('contact_tags')->insertOrIgnore([
                'contact_id' => $contact->id,
                'tag_id' => $this->tags[$key]->id,
                'created_at' => $this->now,
                'updated_at' => $this->now,
            ]);
        }
    }

    protected function resolveAt(Conversation $conversation, Carbon $at, ?int $userId): void
    {
        $conversation->forceFill([
            'status' => 'resolved',
            'resolved_at' => $at,
            'resolved_by_user_id' => $userId,
        ])->saveQuietly();
    }
}

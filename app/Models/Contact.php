<?php

namespace App\Models;

use App\Enums\Connection\Channel;
use App\Events\ContactCreated;
use Illuminate\Database\Eloquent\Model;

class Contact extends Model
{
    protected $fillable = [
        'tenant_id',
        'external_id',
        'channel',
        'name',
        'name_locked',
        'username',
        'photo_profile',
        'meta'
    ];

    public static function createFromExternalData(Connection $connection, string $externalId, string $name, ?string $username = null): self
    {
        $contact = self::firstOrCreate([
            'external_id' => $externalId,
            'tenant_id' => $connection->tenant_id,
        ], [
            'name' => $name,
            'username' => $username,
            'channel' => $connection->channel,
        ]);

        if (!$contact->wasRecentlyCreated) {
            $updates = [];

            // Update name hanya jika belum di-lock oleh admin, data baru valid (bukan placeholder), dan berbeda.
            // Khusus Instagram: tolak juga jika $name berupa numeric ID (placeholder id user/bisnis Instagram).
            $isInstagramIdPlaceholder = $connection->channel === Channel::Instagram && ctype_digit($name);

            if (!$contact->name_locked && $name && $name !== $externalId && !$isInstagramIdPlaceholder && $contact->name !== $name) {
                $updates['name'] = $name;
            }

            // Update username jika ada dan berbeda
            if ($username && $contact->username !== $username) {
                $updates['username'] = $username;
            }

            if (!empty($updates)) {
                $contact->update($updates);
            }
        }

        return $contact;
    }

    protected $casts = [
        'channel' => Channel::class,
        'name_locked' => 'boolean',
    ];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function conversations()
    {
        return $this->hasMany(Conversation::class);
    }
}

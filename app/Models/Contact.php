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

            // Update name jika data baru valid (bukan placeholder yang sama dengan external_id) dan berbeda
            if ($name && $name !== $externalId && $contact->name !== $name) {
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

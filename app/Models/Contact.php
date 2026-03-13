<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Contact extends Model
{
    protected $fillable = [
        'user_id',
        'external_id',
        'name',
        'username',
        'meta'
    ];

    public static function createFromExternalData(Connection $connection, string $externalId, string $name, ?string $username = null): self
    {
        $contact = self::firstOrCreate([
            'external_id' => $externalId,
            'user_id' => $connection->user_id,
        ], [
            'name' => $name,
            'username' => $username,
        ]);

        return $contact;
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function conversations()
    {
        return $this->hasMany(Conversation::class);
    }
}

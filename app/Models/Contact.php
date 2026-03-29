<?php

namespace App\Models;

use App\Events\ContactCreated;
use Illuminate\Database\Eloquent\Model;

class Contact extends Model
{
    protected $fillable = [
        'tenant_id',
        'external_id',
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
        ]);

        return $contact;
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function conversations()
    {
        return $this->hasMany(Conversation::class);
    }
}

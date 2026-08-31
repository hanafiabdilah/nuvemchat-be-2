<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Foundation\Auth\User as Authenticatable;

class AuditLog extends Model
{
    protected $fillable = [
        'actor_id',
        'actor_type',
        'actor_name',
        'action',
        'description',
        'ip_address',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    /**
     * Almost every row here is written by an Admin, but not all of them —
     * revealing an API Way instance token is audited too, and that is a tenant
     * user doing it. Since admins and users are now separate tables, the id on
     * its own no longer says which, so the type travels with it.
     */
    public function actor(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Record a Back Office action. Actor defaults to the current request user,
     * which is an Admin on the `/admin` surface and a User everywhere else.
     */
    public static function record(
        string $action,
        ?string $description = null,
        array $metadata = [],
        ?Authenticatable $actor = null,
    ): void {
        $actor = $actor ?? request()->user();

        static::create([
            'actor_id' => $actor?->getKey(),
            'actor_type' => $actor ? $actor::class : null,
            // Snapshot: the trail has to stay readable after the account is gone.
            'actor_name' => $actor?->name,
            'action' => $action,
            'description' => $description,
            'ip_address' => request()->ip(),
            'metadata' => $metadata ?: null,
        ]);
    }
}

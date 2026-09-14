<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A credential for the public API (/api/v1/*), owned by a workspace.
 *
 * The plain key exists exactly once — in the response that created it. After
 * that only its hash is here, so "show me the key again" is answered by
 * creating a new one, the same way every payment gateway does it.
 */
class ApiKey extends Model
{
    public const PREFIX = 'pk_';

    public const MAX_ACTIVE_PER_TENANT = 10;

    protected $fillable = [
        'tenant_id',
        'name',
        'key_hash',
        'hint',
        'created_by',
        'last_used_at',
        'last_used_ip',
        'revoked_at',
    ];

    protected $hidden = ['key_hash'];

    protected $casts = [
        'last_used_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at');
    }

    public static function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }

    /**
     * @return array{0: self, 1: string} the stored row and the only copy of the plain key
     */
    public static function issue(Tenant $tenant, string $name, ?User $creator = null): array
    {
        $plain = self::PREFIX.Str::random(48);

        $key = self::create([
            'tenant_id' => $tenant->id,
            'name' => $name,
            'key_hash' => self::hash($plain),
            'hint' => substr($plain, 0, 8).'…'.substr($plain, -4),
            'created_by' => $creator?->id,
        ]);

        return [$key, $plain];
    }

    public static function findActive(string $plain): ?self
    {
        return self::active()->where('key_hash', self::hash($plain))->first();
    }

    /**
     * "Last used" is for spotting a forgotten integration, not an audit log —
     * a minute of precision is plenty, and writing on every request of a busy
     * integration would turn one row into thousands of updates an hour.
     */
    public function recordUse(?string $ip): void
    {
        if ($this->last_used_at && $this->last_used_at->gt(now()->subMinute())) {
            return;
        }

        self::whereKey($this->id)->update([
            'last_used_at' => now(),
            'last_used_ip' => $ip,
        ]);
    }
}

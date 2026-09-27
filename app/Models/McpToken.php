<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * An access or refresh token, stored as a hash.
 *
 * ⚠️ Deliberately NOT a Sanctum personal access token, and this is the load-
 * bearing decision of the whole surface. Nothing in the ~388 routes under /api
 * calls `tokenCan()` — abilities are written and never read — so a Sanctum
 * token minted here with scope `mcp:flows.read` would be accepted, in full, on
 * every dashboard endpoint there is. A separate table is what keeps an MCP
 * credential an MCP credential.
 *
 * Hashed rather than encrypted, like `api_keys`: the server only ever has to
 * recognise these, never replay them.
 */
class McpToken extends Model
{
    public const TYPE_ACCESS = 'access';

    public const TYPE_REFRESH = 'refresh';

    /** Access tokens are sent on every call; the prefix makes one obvious in a log or a paste. */
    public const PREFIX_ACCESS = 'mcp_at_';

    public const PREFIX_REFRESH = 'mcp_rt_';

    protected $fillable = [
        'token_hash',
        'mcp_connection_id',
        'type',
        'expires_at',
        'revoked_at',
        'replaced_by_id',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function connection(): BelongsTo
    {
        return $this->belongsTo(McpConnection::class, 'mcp_connection_id');
    }

    public static function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }

    public static function mint(string $type): string
    {
        $prefix = $type === self::TYPE_REFRESH ? self::PREFIX_REFRESH : self::PREFIX_ACCESS;

        return $prefix.Str::random(48);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at')->where('expires_at', '>', now());
    }

    public function isUsable(): bool
    {
        return $this->revoked_at === null && $this->expires_at->isFuture();
    }
}

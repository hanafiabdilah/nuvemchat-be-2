<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A one-shot authorization code, alive for sixty seconds.
 *
 * It travels through a browser redirect — a URL that lands in history, in
 * referrers and sometimes in a proxy log — so it is worth nothing on its own:
 * redeeming it needs the PKCE verifier the client kept to itself, and it can be
 * redeemed once.
 */
class McpAuthorizationCode extends Model
{
    protected $fillable = [
        'code_hash',
        'mcp_client_id',
        'user_id',
        'scopes',
        'redirect_uri',
        'code_challenge',
        'resource',
        'expires_at',
        'consumed_at',
    ];

    protected $casts = [
        'scopes' => 'array',
        'expires_at' => 'datetime',
        'consumed_at' => 'datetime',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(McpClient::class, 'mcp_client_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }

    public function isUsable(): bool
    {
        return $this->consumed_at === null && $this->expires_at->isFuture();
    }

    /** @return list<string> */
    public function scopeList(): array
    {
        return array_values(array_filter((array) $this->scopes, 'is_string'));
    }
}

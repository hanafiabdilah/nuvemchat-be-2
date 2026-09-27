<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One person's standing grant to one MCP client.
 *
 * This is the durable thing: tokens come and go under it, and revoking the
 * connection kills every token ever minted from it without having to find them.
 * It is also the only row the dashboard lists — "Claude Code, approved by
 * Marina, last used an hour ago".
 *
 * ⚠️ The grant is bound to a person, not to the workspace, and that is the
 * whole design. `scopes` can only narrow: what the caller may actually do is
 * re-read from this user's Spatie permissions on every single tool call. Take
 * the flow role away in the dashboard and the connection stops writing flows
 * the next second, with nobody revoking anything.
 */
class McpConnection extends Model
{
    protected $fillable = [
        'tenant_id',
        'user_id',
        'mcp_client_id',
        'client_name',
        'scopes',
        'last_used_at',
        'last_used_ip',
        'revoked_at',
    ];

    protected $casts = [
        'scopes' => 'array',
        'last_used_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(McpClient::class, 'mcp_client_id');
    }

    public function tokens(): HasMany
    {
        return $this->hasMany(McpToken::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at');
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }

    /** @return list<string> */
    public function scopeList(): array
    {
        return array_values(array_filter((array) $this->scopes, 'is_string'));
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopeList(), true);
    }

    /**
     * Revoking takes the tokens with it. Belt and braces: AuthenticateMcp
     * already refuses a token whose connection is revoked, but a refresh token
     * left usable is a live credential sitting in someone's editor, and
     * "revoked" has to mean revoked in the table too.
     */
    public function revoke(): void
    {
        $this->forceFill(['revoked_at' => now()])->save();

        $this->tokens()->whereNull('revoked_at')->update(['revoked_at' => now()]);
    }

    /**
     * "Last used" is for recognising a forgotten editor, not an audit log — a
     * minute of precision is plenty, and writing on every tool call of a busy
     * session would turn one row into thousands of updates an hour.
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

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A registered MCP client — an editor or agent runtime, not a person.
 *
 * One row is shared by every workspace connecting from the same client build,
 * which is why nothing here is tenant-scoped and why nothing here is a secret:
 * these are public clients (no client secret), and OAuth 2.1 says so plainly.
 * What proves the caller in the token exchange is PKCE, not the client id.
 */
class McpClient extends Model
{
    /** Registered itself at POST /mcp/oauth/register (RFC 7591). */
    public const SOURCE_DCR = 'dcr';

    /** Its client_id is an https URL we read the metadata from (CIMD). */
    public const SOURCE_CIMD = 'cimd';

    /** How long a CIMD document is trusted before it is read again. */
    public const METADATA_TTL_HOURS = 24;

    protected $fillable = [
        'client_id',
        'client_name',
        'source',
        'redirect_uris',
        'client_uri',
        'metadata_fetched_at',
    ];

    protected $casts = [
        'redirect_uris' => 'array',
        'metadata_fetched_at' => 'datetime',
    ];

    public function connections(): HasMany
    {
        return $this->hasMany(McpConnection::class);
    }

    /**
     * Exact string comparison, on purpose.
     *
     * OAuth 2.1 removed every form of partial redirect matching, and the reason
     * is this exact surface: a client that may register its own redirect URI
     * and then be matched by prefix can claim `https://good.example/cb` and be
     * sent to `https://good.example/cb.attacker.test`. A loopback client asking
     * for a different port each run must register each one.
     */
    public function allowsRedirect(string $uri): bool
    {
        foreach ((array) $this->redirect_uris as $registered) {
            if (hash_equals((string) $registered, $uri)) {
                return true;
            }
        }

        return false;
    }

    public function metadataIsStale(): bool
    {
        return $this->source === self::SOURCE_CIMD
            && (! $this->metadata_fetched_at
                || $this->metadata_fetched_at->lt(now()->subHours(self::METADATA_TTL_HOURS)));
    }
}

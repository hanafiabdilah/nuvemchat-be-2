<?php

namespace App\Services\Mcp;

/**
 * Every address this server publishes about itself, in one place.
 *
 * They all go through `url()`, which App\Support\PlatformUrl has already pinned
 * to PLATFORM_URL — so a discovery document fetched through a country domain
 * still names the platform host. That is not a nicety: these URLs are written
 * into a client's stored configuration and into token audiences, and a country
 * domain can be retired while the platform host cannot.
 *
 * ⚠️ The consequence, and it is a real one: the consent screen lives on the
 * platform host too, so somebody who signs in at a country domain is sent to
 * chat.pingly.com.br to approve the connection and has to sign in there once.
 * OAuth allows exactly one issuer per protected resource, so this cannot follow
 * the market — see docs/mcp.md.
 */
final class McpUrls
{
    /** RFC 8414 issuer. No trailing slash: clients compare it as a string. */
    public static function issuer(): string
    {
        return rtrim(url('/'), '/');
    }

    /**
     * The canonical resource identifier (RFC 8707), and the MCP endpoint
     * itself. Without a trailing slash, as the specification prefers.
     */
    public static function resource(): string
    {
        return self::issuer().'/mcp';
    }

    /**
     * ⚠️ A route in the SPA, not in Laravel.
     *
     * The dashboard authenticates with a bearer token in localStorage and sends
     * no cookies, so a server-rendered consent page would have no session to
     * read and no way to know who is looking at it. The React route reads the
     * query string, asks the API who the client is, and posts the approval with
     * the token it already holds.
     */
    public static function authorizationEndpoint(): string
    {
        return self::issuer().'/oauth/mcp/authorize';
    }

    public static function tokenEndpoint(): string
    {
        return self::issuer().'/mcp/oauth/token';
    }

    public static function registrationEndpoint(): string
    {
        return self::issuer().'/mcp/oauth/register';
    }

    public static function revocationEndpoint(): string
    {
        return self::issuer().'/mcp/oauth/revoke';
    }

    public static function protectedResourceMetadata(): string
    {
        return self::issuer().'/.well-known/oauth-protected-resource';
    }

    /**
     * Whether a client's `resource` parameter names this server.
     *
     * Absent is accepted: the specification tells clients to always send it,
     * but these tokens are opaque rows in our own table and are valid nowhere
     * else, so an absent parameter cannot widen an audience. A *present* one
     * that names somebody else is refused — that is a client about to hand our
     * token to another server, and it is worth stopping.
     */
    public static function isOwnResource(?string $resource): bool
    {
        if ($resource === null || trim($resource) === '') {
            return true;
        }

        $normalise = static fn (string $value): string => rtrim(strtolower(trim($value)), '/');

        $given = $normalise($resource);

        return $given === $normalise(self::resource()) || $given === $normalise(self::issuer());
    }
}

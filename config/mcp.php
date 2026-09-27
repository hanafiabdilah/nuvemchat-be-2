<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Model Context Protocol server
    |--------------------------------------------------------------------------
    |
    | Lets a workspace drive Pingly from an LLM client — Claude Code, Claude
    | Desktop, Codex — over the MCP endpoint at POST /mcp. Phase one exposes the
    | flow builder and nothing else.
    |
    | This is the platform's first surface where an outside program acts as a
    | named person rather than as the workspace: the caller holds an OAuth token
    | minted for one user, and every tool re-reads that user's permissions on
    | every call. A scope can only narrow what the person may already do.
    |
    | Off by default. The endpoint answering 404 is the correct state until the
    | Caddyfile has been deployed (a path missing from @backend is served the
    | SPA's index.html with status 200, which an MCP client reports as a JSON
    | parse error rather than a missing route) and the Health row is green.
    | Turning it off revokes nothing: existing tokens simply stop being accepted.
    |
    | See App\Services\Mcp\Server and docs/mcp.md.
    |
    */

    'enabled' => (bool) env('MCP_ENABLED', false),

    /*
    | Identity reported by server/discover and by the legacy initialize result.
    | The version is the server's own, not the protocol's.
    */

    'server_name' => 'pingly',
    'server_version' => '1.0.0',

    /*
    | Protocol revisions this server speaks, newest first.
    |
    | Two eras, deliberately. `2026-07-28` is stateless: version and client
    | identity ride in each request's `_meta` and there is no handshake. The
    | three before it open with `initialize`. Claude Code moved to the new one;
    | Claude Desktop and several other clients have not, and a server that
    | speaks only the new revision answers them with a protocol error they have
    | no way to act on — which reads as a broken product, not an old client.
    |
    | Dropping the legacy entries is the eventual cleanup, not a switch to flip
    | early: clients cache a server's era, so the day this list loses them is
    | the day those installs stop working.
    */

    'protocol_versions' => ['2026-07-28', '2025-11-25', '2025-06-18', '2025-03-26'],

    /*
    |--------------------------------------------------------------------------
    | OAuth
    |--------------------------------------------------------------------------
    |
    | Lifetimes. The authorization code is single-use and measured in seconds
    | because it travels through a browser redirect and nothing legitimate needs
    | longer. The access token is short because it is replayed on every tool
    | call and lives in a client's credential store; the refresh token is what
    | keeps a working setup working, and it rotates on every use so a stolen one
    | is good for a single exchange before the theft becomes visible.
    |
    */

    'authorization_code_ttl' => (int) env('MCP_AUTHORIZATION_CODE_TTL', 60),

    'access_token_ttl_minutes' => (int) env('MCP_ACCESS_TOKEN_TTL_MINUTES', 60),

    'refresh_token_ttl_days' => (int) env('MCP_REFRESH_TOKEN_TTL_DAYS', 30),

    /*
    | How many live connections one person may hold. A ceiling rather than a
    | policy: each editor a person connects from is its own connection, and a
    | handful is normal — a hundred means something is registering in a loop.
    */

    'max_connections_per_user' => (int) env('MCP_MAX_CONNECTIONS_PER_USER', 10),

    /*
    | Client ID Metadata Documents (the successor to Dynamic Client
    | Registration, which the MCP specification now marks deprecated): the
    | client_id is an https URL and the authorization server fetches the client's
    | metadata from it.
    |
    | ⚠️ That fetch is an outbound request to an address the caller chose, so it
    | goes through App\Support\OutboundHttp like every other one. Turning this
    | off leaves DCR, which every current client still supports and which makes
    | no outbound request at all.
    */

    'client_id_metadata_documents' => (bool) env('MCP_CIMD_ENABLED', true),

    'client_metadata_max_bytes' => (int) env('MCP_CLIENT_METADATA_MAX_BYTES', 32768),

];

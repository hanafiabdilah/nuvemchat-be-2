<?php

namespace App\Http\Middleware\Mcp;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The MCP kill switch.
 *
 * 404 rather than 503, and rather than an empty discovery document. A client
 * that gets 404 from `/.well-known/oauth-protected-resource` concludes this
 * server does not offer MCP and says so; one that gets a valid document and
 * then a 503 at the endpoint has been led halfway through an authorization it
 * cannot finish, and the person is left reading an error about a service they
 * were never told was off.
 */
class EnsureMcpEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless((bool) config('mcp.enabled'), 404);

        return $next($request);
    }
}

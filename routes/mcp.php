<?php

use App\Http\Controllers\Mcp\McpController;
use App\Http\Controllers\Mcp\MetadataController;
use App\Http\Controllers\Mcp\RegistrationController;
use App\Http\Controllers\Mcp\TokenController;
use App\Http\Middleware\Mcp\AuthenticateMcp;
use App\Http\Middleware\Mcp\EnsureMcpEnabled;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Model Context Protocol
|--------------------------------------------------------------------------
|
| The endpoint an LLM client (Claude, Codex) drives this workspace through,
| and the OAuth 2.1 server that lets a person authorize one.
|
| Outside the `api` group on purpose: these are addresses handed to programs
| on other people's machines, the discovery documents have to sit at
| `/.well-known/*` by specification, and nothing here wants a session, a
| cookie or CSRF. Platform host only — the URLs are written into a client's
| stored configuration and into token audiences, and a country domain can be
| retired while the platform host cannot.
|
| ⚠️ Three of these paths are unauthenticated and two of them write rows, so
| each carries its own limit. Registration is the expensive one: every call
| creates a client. Reading a discovery document is free and is left alone,
| because a client fetches it once per connection and a limit there would
| only ever bite a legitimate one.
|
*/

Route::middleware(EnsureMcpEnabled::class)->group(function () {
    // RFC 9728 and RFC 8414. Served at the root because that is where a client
    // that knows only the endpoint will look — and, for the resource document,
    // also under the endpoint's own path, which is the first of the two places
    // the specification tells clients to try.
    Route::get('/.well-known/oauth-protected-resource', [MetadataController::class, 'protectedResource']);
    Route::get('/.well-known/oauth-protected-resource/mcp', [MetadataController::class, 'protectedResource']);
    Route::get('/.well-known/oauth-authorization-server', [MetadataController::class, 'authorizationServer']);

    Route::prefix('mcp/oauth')->group(function () {
        // Open by design: a client_id is public and confers nothing until a
        // person approves it on the consent screen. The limit is what stops
        // this being a way to fill a table.
        Route::post('register', [RegistrationController::class, 'store'])
            ->middleware('throttle:mcp-register');

        Route::post('token', [TokenController::class, 'store'])
            ->middleware('throttle:mcp-token');

        Route::post('revoke', [TokenController::class, 'revoke'])
            ->middleware('throttle:mcp-token');
    });

    Route::post('/mcp', [McpController::class, 'handle'])
        ->middleware([AuthenticateMcp::class, 'throttle:mcp']);

    // GET was the standalone event stream and DELETE ended a session; revision
    // 2026-07-28 removed both. Answering 405 is what tells an older client to
    // stop asking rather than leaving it waiting on a stream that will not open.
    Route::match(['get', 'delete'], '/mcp', [McpController::class, 'methodNotAllowed']);
});

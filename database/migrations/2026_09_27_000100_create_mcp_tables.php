<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The MCP server's OAuth 2.1 storage.
 *
 * Four tables and one idea: a *connection* is the durable thing a person sees
 * and revokes ("Claude Code on my laptop"), and tokens are the disposable
 * things that point at it. Revoking the connection therefore kills every token
 * ever minted under it without hunting them down.
 *
 * Secrets are stored as sha256, the same reasoning as `api_keys`: they are 48
 * random characters, so a slow hash buys nothing against something that cannot
 * be guessed and would cost a bcrypt on every tool call.
 */
return new class extends Migration
{
    public function up(): void
    {
        // The registered OAuth client — an editor or agent runtime, not a
        // person and not a workspace. One row is shared by every workspace that
        // connects from the same client build, which is why nothing tenant-
        // scoped lives here.
        Schema::create('mcp_clients', function (Blueprint $table) {
            $table->id();
            // Either an opaque id we minted (Dynamic Client Registration) or the
            // https URL the client published its metadata at (Client ID Metadata
            // Documents). Both are unique and both are public by design.
            $table->string('client_id', 512)->unique();
            $table->string('client_name', 200);
            $table->string('source', 16)->default('dcr');
            $table->json('redirect_uris');
            $table->string('client_uri', 512)->nullable();
            // CIMD only: the document is re-read periodically, because the
            // client's redirect URIs can change without us being told.
            $table->timestamp('metadata_fetched_at')->nullable();
            $table->timestamps();
        });

        // What the person granted, and the only row the dashboard lists.
        Schema::create('mcp_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            // The grant belongs to one person: every tool call re-reads this
            // user's permissions, so removing them from the workspace closes
            // the door without anyone revoking anything.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('mcp_client_id')->constrained('mcp_clients')->cascadeOnDelete();
            // Snapshot: what the person saw on the consent screen. The client
            // row can be renamed by a later registration; the audit trail of
            // what was approved must not move with it.
            $table->string('client_name', 200);
            $table->json('scopes');
            $table->timestamp('last_used_at')->nullable();
            $table->string('last_used_ip', 45)->nullable();
            // Kept rather than deleted: the audit log names the connection.
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'revoked_at']);
            $table->index(['user_id', 'revoked_at']);
        });

        // Single-use, 60 seconds, and it never leaves the browser redirect it
        // was minted for.
        Schema::create('mcp_authorization_codes', function (Blueprint $table) {
            $table->id();
            $table->string('code_hash', 64)->unique();
            $table->foreignId('mcp_client_id')->constrained('mcp_clients')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->json('scopes');
            $table->string('redirect_uri', 2048);
            // PKCE S256 only — the plain method is not offered, and public
            // clients are the only kind this server registers.
            $table->string('code_challenge', 128);
            // RFC 8707. Bound here at authorization time and compared again at
            // the token endpoint, so a code minted for this server cannot be
            // redeemed for a token addressed anywhere else.
            $table->string('resource', 512)->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();

            $table->index('expires_at');
        });

        Schema::create('mcp_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('token_hash', 64)->unique();
            $table->foreignId('mcp_connection_id')->constrained('mcp_connections')->cascadeOnDelete();
            $table->string('type', 16); // access | refresh
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            // Refresh rotation: the row that replaced this one. A second use of
            // an already-rotated refresh token is the signature of a stolen
            // one, and it takes the whole connection down rather than just
            // failing — see AuthorizationService::refresh().
            $table->foreignId('replaced_by_id')->nullable()->constrained('mcp_tokens')->nullOnDelete();
            $table->timestamps();

            $table->index(['mcp_connection_id', 'type']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_tokens');
        Schema::dropIfExists('mcp_authorization_codes');
        Schema::dropIfExists('mcp_connections');
        Schema::dropIfExists('mcp_clients');
    }
};

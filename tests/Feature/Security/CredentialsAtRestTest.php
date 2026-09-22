<?php

use App\Enums\Connection\Channel;
use App\Models\Connection;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Connection\ConnectionCredentials;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/**
 * `connections.credentials` holds the most valuable thing this platform stores
 * for a customer, and it held it in plaintext — so every database backup
 * carried every channel token on the platform in the clear.
 *
 * The half of this that is easy to get wrong is the other half: twenty-two
 * queries read *into* this JSON to route inbound webhooks, and encrypting the
 * whole column breaks all of them with no error — just messages that stop
 * arriving. These cover both sides.
 */
uses(RefreshDatabase::class);

function credentialsConnection(array $credentials, Channel $channel = Channel::Telegram): Connection
{
    $user = User::factory()->create(['email' => 'creds-'.uniqid().'@example.test']);
    $tenant = Tenant::create(['user_id' => $user->id]);
    $user->forceFill(['tenant_id' => $tenant->id])->save();

    return Connection::create([
        'tenant_id' => $tenant->id,
        'name' => 'Test connection',
        'channel' => $channel,
        'credentials' => $credentials,
    ]);
}

/** The bytes actually on disk, without the cast in the way. */
function storedCredentials(Connection $connection): string
{
    return (string) DB::table('connections')->where('id', $connection->id)->value('credentials');
}

it('keeps secrets out of the stored column but hands them back to the app', function () {
    $connection = credentialsConnection([
        'id' => 12345,
        'username' => 'loja_aurora_bot',
        'token' => '7654321:AAH-super-secret-bot-token',
    ]);

    expect(storedCredentials($connection))->not->toContain('AAH-super-secret-bot-token');

    // and the application never notices
    expect($connection->fresh()->credentials['token'])->toBe('7654321:AAH-super-secret-bot-token');
});

it('leaves the identity keys queryable, which is the whole reason it is per value', function () {
    // These are not cosmetic: `credentials->page_id` is how a Messenger webhook
    // finds its connection, `->business_account_id` a WhatsApp one. Encrypting
    // the blob makes every one of these match nothing, and the symptom is
    // messages silently never arriving.
    $connection = credentialsConnection([
        'page_id' => '998877',
        'business_account_id' => '112233',
        'app_id' => 'wgt_abc',
        'access_token' => 'EAAG-page-token',
    ], Channel::Messenger);

    expect(storedCredentials($connection))->toContain('998877')
        ->and(storedCredentials($connection))->not->toContain('EAAG-page-token');

    foreach (['page_id' => '998877', 'business_account_id' => '112233', 'app_id' => 'wgt_abc'] as $key => $value) {
        expect(Connection::where("credentials->{$key}", $value)->whereKey($connection->id)->exists())
            ->toBeTrue("credentials->{$key} must stay queryable");
    }
});

it('protects secrets nested inside the payload', function () {
    // `pending_pages` is a list of Facebook pages each carrying its own access
    // token, and `released_instance` keeps the token of the API Way instance it
    // let go. A one-level pass leaves both in the clear.
    $connection = credentialsConnection([
        'pending_pages' => [
            ['page_id' => '1', 'name' => 'Loja', 'access_token' => 'EAAG-nested-one'],
            ['page_id' => '2', 'name' => 'Ateliê', 'access_token' => 'EAAG-nested-two'],
        ],
    ], Channel::Messenger);

    $stored = storedCredentials($connection);

    expect($stored)->not->toContain('EAAG-nested-one')
        ->and($stored)->not->toContain('EAAG-nested-two')
        // The non-secret fields beside them are untouched. (Asserted on the
        // ASCII one: the column is plain json_encode, as it has always been,
        // so "Ateliê" is stored escaped as \u00ea.)
        ->and($stored)->toContain('Loja')
        ->and($stored)->toContain('"page_id":"2"');

    expect($connection->fresh()->credentials['pending_pages'][1]['access_token'])->toBe('EAAG-nested-two');
});

it('hands back a row written before any of this existed, untouched', function () {
    // Decryption is driven by a marker on the value, not by the key list —
    // that is what makes this deployable without a migration, and what this
    // test exists to hold.
    $connection = credentialsConnection(['id' => 1, 'token' => 'placeholder']);

    DB::table('connections')->where('id', $connection->id)->update([
        'credentials' => json_encode(['id' => 1, 'token' => 'legacy-plaintext-token']),
    ]);

    expect($connection->fresh()->credentials['token'])->toBe('legacy-plaintext-token');
});

it('does not encrypt the fields that merely sound like secrets', function () {
    // `token_expires_at` sits in the same payloads as `token`. Matching by
    // substring would encrypt it and turn an expiry comparison into a
    // comparison of ciphertext.
    $connection = credentialsConnection([
        'token' => 'real-secret',
        'token_expires_at' => '2027-01-01T00:00:00Z',
        'token_type' => 'bearer',
    ]);

    $stored = storedCredentials($connection);

    expect($stored)->toContain('2027-01-01')
        ->and($stored)->toContain('bearer')
        ->and($stored)->not->toContain('real-secret');
});

it('reports a plaintext row as needing protection, and an encrypted one as not', function () {
    expect(ConnectionCredentials::needsProtecting(['token' => 'plain']))->toBeTrue()
        ->and(ConnectionCredentials::needsProtecting(['page_id' => '42']))->toBeFalse()
        ->and(ConnectionCredentials::needsProtecting(ConnectionCredentials::protect(['token' => 'plain'])))->toBeFalse()
        ->and(ConnectionCredentials::needsProtecting(null))->toBeFalse();
});

it('converts the platform in one pass and reports nothing left on the second', function () {
    $connection = credentialsConnection(['id' => 9, 'token' => 'placeholder']);

    DB::table('connections')->where('id', $connection->id)->update([
        'credentials' => json_encode(['id' => 9, 'token' => 'legacy-plaintext-token']),
    ]);

    $this->artisan('connections:encrypt-credentials')
        ->expectsOutputToContain('Encrypted 1 connection(s)')
        ->assertSuccessful();

    expect(storedCredentials($connection))->not->toContain('legacy-plaintext-token');
    expect($connection->fresh()->credentials['token'])->toBe('legacy-plaintext-token');

    // Idempotent: a second run must not rewrite rows it already converted.
    $this->artisan('connections:encrypt-credentials')
        ->expectsOutputToContain('Encrypted 0 connection(s)')
        ->assertSuccessful();
});

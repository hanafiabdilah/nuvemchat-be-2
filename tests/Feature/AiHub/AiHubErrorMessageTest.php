<?php

use App\Exceptions\UpstreamServiceException;
use App\Services\AiAgentHub\AiAgentHubTenantService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * What the hub's refusal becomes on the way out.
 *
 * Two guarantees, and they pull in opposite directions on purpose:
 *
 *  - Nothing the hub wrote reaches the customer. "provider must be one of the
 *    following values: OPENAI" names a field they never filled in, in a service
 *    they do not know they are using.
 *  - Everything the hub wrote survives, in full, on the exception's rawMessage
 *    (and so in the log line UpstreamError writes). That is the half this file
 *    was originally written for: `['message'][0]` on a PHP *string* is its first
 *    letter, so every plain-text 400 the hub ever sent was being reduced to "p"
 *    before anyone could read it.
 */
// The service resolves its base URL from the `settings` table on construction.
uses(RefreshDatabase::class);

function hubRefusal(array $body, int $status): Response
{
    Http::fake(['*' => Http::response($body, $status)]);

    return Http::get('https://hub.test/v1/agents');
}

function callEnsure(Response $response): void
{
    (new class extends AiAgentHubTenantService
    {
        public function check(Response $response): void
        {
            $this->ensureSuccessful($response, 'create agent');
        }
    })->check($response);
}

/** Run callEnsure and hand back the exception it raised. */
function refusalFrom(array $body, int $status): UpstreamServiceException
{
    try {
        callEnsure(hubRefusal($body, $status));
    } catch (UpstreamServiceException $e) {
        return $e;
    }

    throw new RuntimeException('ensureSuccessful accepted a failed response');
}

it('keeps a plain-string rejection whole in the log, not its first letter', function () {
    $e = refusalFrom(['message' => 'provider must be one of the following values: OPENAI'], 400);

    expect($e->rawMessage)->toBe('provider must be one of the following values: OPENAI');
});

it('joins a list of validation messages instead of dropping all but one', function () {
    // NestJS class-validator's shape. Recording only the first makes a request
    // with three faults take three attempts to understand.
    $e = refusalFrom([
        'message' => ['model should not be empty', 'providerCredentialId must be a string'],
    ], 400);

    expect($e->rawMessage)->toBe('model should not be empty providerCredentialId must be a string');
});

it('falls back when the hub sends no message at all', function () {
    expect(refusalFrom(['error' => 'Bad Request'], 400)->rawMessage)->toBe('Bad Request');
});

it('never shows the hub\'s own words to the customer', function () {
    $e = refusalFrom(['message' => 'provider must be one of the following values: OPENAI'], 400);

    expect($e->getMessage())
        ->not->toContain('provider must be one of')
        ->not->toContain('OPENAI')
        ->toContain('agente');
    expect($e->getErrorCode())->toBe('ai_invalid_configuration');
    expect($e->httpStatus)->toBe(422);
});

it('translates a credential the hub no longer accepts into the screen that fixes it', function () {
    $e = refusalFrom(['message' => 'Provider credential not found or disabled'], 400);

    expect($e->getErrorCode())->toBe('ai_credential_invalid');
    expect($e->getMessage())->toContain('Credenciais');
});

it('answers a name conflict with 409 and no mention of tenants or credentials', function () {
    $e = refusalFrom([
        'message' => 'Provider credential already exists for this tenant, provider and name.',
    ], 409);

    expect($e->httpStatus)->toBe(409);
    expect($e->getErrorCode())->toBe('ai_name_conflict');
    expect($e->getMessage())->not->toContain('tenant');
});

it('hands back a reference the customer can quote', function () {
    $e = refusalFrom(['message' => 'anything'], 400);

    expect($e->reference)->toHaveLength(8);
    expect($e->toResponse()->getData(true))->toHaveKey('ref', $e->reference);
});

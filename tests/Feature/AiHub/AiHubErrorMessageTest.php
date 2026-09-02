<?php

use App\Services\AiAgentHub\AiAgentHubTenantService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

/**
 * What the customer is told when the hub refuses a request.
 *
 * This file exists because of one character. `['message'][0]` on a PHP string
 * is its first letter, so a hub rejection of "provider must be one of ..."
 * reached the dashboard as "p" — and had been doing so for every plain-text 400
 * the hub has ever sent.
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

it('reports a plain-string rejection in full, not its first letter', function () {
    $response = hubRefusal(['message' => 'provider must be one of the following values: OPENAI'], 400);

    expect(fn () => callEnsure($response))
        ->toThrow(ValidationException::class, 'provider must be one of the following values: OPENAI');
});

it('joins a list of validation messages instead of dropping all but one', function () {
    // NestJS class-validator's shape. Reporting only the first makes a request
    // with three faults take three attempts to understand.
    $response = hubRefusal([
        'message' => ['model should not be empty', 'providerCredentialId must be a string'],
    ], 400);

    expect(fn () => callEnsure($response))
        ->toThrow(ValidationException::class, 'model should not be empty providerCredentialId must be a string');
});

it('falls back when the hub sends no message at all', function () {
    expect(fn () => callEnsure(hubRefusal(['error' => 'Bad Request'], 400)))
        ->toThrow(ValidationException::class, 'Bad Request');
});

it('reports a conflict message rather than the word Conflict', function () {
    $response = hubRefusal([
        'message' => 'Provider credential already exists for this tenant, provider and name.',
    ], 409);

    expect(fn () => callEnsure($response))
        ->toThrow(Exception::class, 'Provider credential already exists for this tenant, provider and name.');
});

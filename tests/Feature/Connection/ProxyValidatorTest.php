<?php

use App\Exceptions\ConnectionException;
use App\Services\Connection\Proxy\ProxyValidator;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->validator = new ProxyValidator();
});

it('parses a valid proxy string', function () {
    $parts = $this->validator->parse('1.2.3.4:8000:user:pass');

    expect($parts)->toMatchArray([
        'host' => '1.2.3.4',
        'port' => 8000,
        'username' => 'user',
        'password' => 'pass',
    ]);
});

it('rejects a malformed proxy string', function () {
    $this->validator->parse('1.2.3.4:8000:user');
})->throws(ValidationException::class);

it('rejects an out-of-range port', function () {
    $this->validator->parse('1.2.3.4:99999:user:pass');
})->throws(ValidationException::class);

it('detects http when the http probe succeeds', function () {
    Http::fake([
        'api.ipify.org*' => Http::response(['ip' => '1.2.3.4'], 200),
    ]);

    $result = $this->validator->detectAndBuild([
        'host' => '1.2.3.4', 'port' => 8000, 'username' => 'user', 'password' => 'pass',
    ]);

    expect($result['scheme'])->toBe('http')
        ->and($result['url'])->toBe('http://user:pass@1.2.3.4:8000');
});

it('falls back to socks5 when http fails but socks5 succeeds', function () {
    $calls = 0;
    Http::fake(function () use (&$calls) {
        $calls++;
        // First probe (http) fails, second (socks5) succeeds.
        return $calls === 1
            ? Http::response('', 500)
            : Http::response(['ip' => '1.2.3.4'], 200);
    });

    $result = $this->validator->detectAndBuild([
        'host' => '1.2.3.4', 'port' => 8000, 'username' => 'user', 'password' => 'pass',
    ]);

    expect($result['scheme'])->toBe('socks5')
        ->and($result['url'])->toBe('socks5://user:pass@1.2.3.4:8000');
});

it('throws when neither scheme can reach the proxy', function () {
    Http::fake([
        'api.ipify.org*' => Http::response('', 500),
    ]);

    $this->validator->detectAndBuild([
        'host' => '1.2.3.4', 'port' => 8000, 'username' => 'user', 'password' => 'pass',
    ]);
})->throws(ConnectionException::class);

<?php

use App\Services\Connection\Meta\GraphApi;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Sleep::fake(); // no real waiting during backoff
});

test('returns immediately on a successful response without sleeping', function () {
    Http::fake(['*' => Http::response(['id' => '123'], 200)]);

    $response = GraphApi::retry(fn () => Http::get('https://graph.facebook.com/v25.0/me'));

    expect($response->successful())->toBeTrue();
    Sleep::assertNeverSlept();
    Http::assertSentCount(1);
});

test('retries after an HTTP 429 and succeeds', function () {
    Http::fake([
        '*' => Http::sequence()
            ->push(['error' => ['message' => 'rate limited']], 429)
            ->push(['id' => '123'], 200),
    ]);

    $response = GraphApi::retry(fn () => Http::get('https://graph.facebook.com/v25.0/me'));

    expect($response->status())->toBe(200);
    Http::assertSentCount(2);
    Sleep::assertSleptTimes(1);
});

test('retries on a Meta rate-limit error code with a 200 envelope', function () {
    // Graph sometimes returns 200 HTTP with an error.code throttle payload.
    Http::fake([
        '*' => Http::sequence()
            ->push(['error' => ['code' => 4, 'message' => 'application request limit reached']], 200)
            ->push(['id' => '123'], 200),
    ]);

    $response = GraphApi::retry(fn () => Http::get('https://graph.facebook.com/v25.0/me'));

    expect($response->json('id'))->toBe('123');
    Http::assertSentCount(2);
});

test('gives up after max attempts and returns the last throttled response', function () {
    Http::fake(['*' => Http::response(['error' => ['message' => 'rate limited']], 429)]);

    $response = GraphApi::retry(fn () => Http::get('https://graph.facebook.com/v25.0/me'), maxAttempts: 3);

    expect($response->status())->toBe(429);
    Http::assertSentCount(3);
    Sleep::assertSleptTimes(2); // sleeps between the 3 attempts, not after the last
});

test('does not retry a non-throttle client error', function () {
    Http::fake(['*' => Http::response(['error' => ['message' => 'bad request']], 400)]);

    $response = GraphApi::retry(fn () => Http::get('https://graph.facebook.com/v25.0/me'));

    expect($response->status())->toBe(400);
    Http::assertSentCount(1);
    Sleep::assertNeverSlept();
});

test('honors a numeric Retry-After header for backoff', function () {
    Http::fake([
        '*' => Http::sequence()
            ->push(['error' => ['message' => 'rate limited']], 429, ['Retry-After' => '2'])
            ->push(['id' => '123'], 200),
    ]);

    $response = GraphApi::retry(fn () => Http::get('https://graph.facebook.com/v25.0/me'));

    expect($response->status())->toBe(200);
    Sleep::assertSlept(fn (\Carbon\CarbonInterval $duration) => $duration->totalMilliseconds === 2000.0, 1);
});

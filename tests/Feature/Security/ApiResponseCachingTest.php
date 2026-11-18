<?php

use Illuminate\Support\Facades\Route;

/**
 * API JSON is never written to the browser's disk cache.
 *
 * The default `no-cache, private` lets the browser store the response and only
 * revalidate it; on a shared computer that left conversations and contact
 * lists on disk after sign-out. See App\Http\Middleware\PreventApiResponseCaching.
 */
it('tells the browser not to store an API answer', function () {
    Route::middleware('api')->get('/api/__plain-probe', fn () => response()->json(['ok' => true]));

    $this->getJson('/api/__plain-probe')
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private');
});

it('covers refusals too, not only successful answers', function () {
    $this->getJson('/api/user')
        ->assertUnauthorized()
        ->assertHeader('Cache-Control', 'no-store, private');
});

it('leaves a response that chose to be cached alone', function () {
    Route::middleware('api')->get('/api/__cache-probe', fn () => response()
        ->json(['ok' => true])
        ->header('Cache-Control', 'public, max-age=60'));

    $response = $this->getJson('/api/__cache-probe')->assertOk();

    expect($response->headers->get('Cache-Control'))
        ->toContain('public')
        ->toContain('max-age=60')
        ->not->toContain('no-store');
});

it('does not touch what is not JSON', function () {
    Route::middleware('api')->get('/api/__csv-probe', fn () => response('a,b', 200, [
        'Content-Type' => 'text/csv',
    ]));

    $response = $this->get('/api/__csv-probe')->assertOk();

    expect($response->headers->get('Cache-Control'))->not->toContain('no-store');
});

it('leaves pages outside the API alone', function () {
    Route::get('/__web-probe', fn () => response()->json(['ok' => true]));

    $response = $this->getJson('/__web-probe')->assertOk();

    expect($response->headers->get('Cache-Control'))->not->toContain('no-store');
});

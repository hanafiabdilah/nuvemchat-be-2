<?php

use App\Http\Controllers\ConnectionController;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Laravel\Fortify\Features;

Route::get('/', function () {
    return Inertia::render('welcome', [
        'canRegister' => Features::enabled(Features::registration()),
    ]);
})->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', function () {
        return Inertia::render('dashboard');
    })->name('dashboard');
});

Route::get('/oauth/instagram/callback', [ConnectionController::class, 'instagramCallback'])
    ->name('oauth.instagram.callback');
Route::post('/oauth/instagram/deauthorize', [ConnectionController::class, 'instagramDeauthorize'])
    ->name('oauth.instagram.deauthorize');
Route::post('/oauth/instagram/data-deletion', [ConnectionController::class, 'instagramDataDeletion'])
    ->name('oauth.instagram.data-deletion');
Route::get('/instagram/deletion-status', [ConnectionController::class, 'instagramDeletionStatus'])
    ->name('instagram.deletion-status');

// Facebook OAuth (for WhatsApp & Messenger)
Route::get('/oauth/facebook/callback', [ConnectionController::class, 'facebookCallback'])
    ->middleware(HandleCors::class)
    ->name('oauth.facebook.callback');
Route::post('/oauth/facebook/deauthorize', [ConnectionController::class, 'facebookDeauthorize'])
    ->name('oauth.facebook.deauthorize');
Route::post('/oauth/facebook/data-deletion', [ConnectionController::class, 'facebookDataDeletion'])
    ->name('oauth.facebook.data-deletion');
Route::get('/oauth/facebook/deletion-status', [ConnectionController::class, 'facebookDeletionStatus'])
    ->name('oauth.facebook.deletion-status');

require __DIR__.'/settings.php';
require __DIR__.'/webhook.php';

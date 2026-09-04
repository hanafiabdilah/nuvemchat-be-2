<?php

use App\Http\Controllers\ConnectionController;
use App\Http\Controllers\GalleryFileController;
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

// TikTok OAuth (Business Messaging)
Route::get('/oauth/tiktok/callback', [ConnectionController::class, 'tiktokCallback'])
    ->name('oauth.tiktok.callback');

// Facebook OAuth (for WhatsApp & Messenger)
Route::match(['get', 'post'], '/oauth/facebook/callback', [ConnectionController::class, 'facebookCallback'])
    ->name('oauth.facebook.callback');
Route::post('/oauth/facebook/deauthorize', [ConnectionController::class, 'facebookDeauthorize'])
    ->name('oauth.facebook.deauthorize');
Route::post('/oauth/facebook/data-deletion', [ConnectionController::class, 'facebookDataDeletion'])
    ->name('oauth.facebook.data-deletion');
Route::get('/oauth/facebook/deletion-status', [ConnectionController::class, 'facebookDeletionStatus'])
    ->name('oauth.facebook.deletion-status');

/**
 * A tenant's gallery file, to whoever holds the signed link.
 *
 * Public on purpose: the readers that matter are Meta, Telegram and Discord
 * fetching the bytes to deliver them, and none of them carries a session. The
 * signature is the credential — the same arrangement message media runs on.
 *
 * The trailing `{filename}` is part of the signature and looks redundant, but
 * it is the whole reason the URL works as media: OutboundMedia reads the
 * extension off the last path segment to decide the MIME type, and WhatsApp
 * shows that segment as the document's name. `.*` on it so a dotted filename
 * survives — Laravel's default parameter pattern stops at one.
 */
Route::get('/gallery/{uuid}/{filename}', [GalleryFileController::class, 'show'])
    ->where('filename', '.*')
    ->middleware('signed')
    ->name('gallery.file');

require __DIR__.'/settings.php';
require __DIR__.'/webhook.php';

<?php

use App\Http\Controllers\ConnectionController;
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
Route::get('/oauth/instagram/deauthorize', [ConnectionController::class, 'instagramDeauthorize'])
    ->name('oauth.instagram.deauthorize');
Route::get('/oauth/instagram/data-deletion', [ConnectionController::class, 'instagramDataDeletion'])
    ->name('oauth.instagram.data-deletion');

require __DIR__.'/settings.php';
require __DIR__.'/webhook.php';

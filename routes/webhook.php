<?php

use App\Http\Controllers\Webhook\ApiwayNumbersController;
use App\Http\Controllers\Webhook\ChatController;
use App\Http\Controllers\Webhook\FacebookController;
use App\Http\Controllers\Webhook\InstagramController;
use App\Http\Controllers\Webhook\MercadoPagoWebhookController;
use App\Http\Controllers\Webhook\TikTokController;
use App\Http\Controllers\Webhook\WhatsAppController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

Route::post('/webhook/chat/{id}', [ChatController::class, 'handle'])->name('webhook.chat');

Route::get('/webhook/instagram', [InstagramController::class, 'verify'])->name('webhook.instagram.verify');
Route::post('/webhook/instagram', [InstagramController::class, 'handle'])->name('webhook.instagram.handle');

Route::get('/webhook/whatsapp', [WhatsAppController::class, 'verify'])->name('webhook.whatsapp.verify');
Route::post('/webhook/whatsapp', [WhatsAppController::class, 'handle'])->name('webhook.whatsapp.handle');

// Messenger (Facebook Page) events — object=page, connection resolved by page_id.
Route::get('/webhook/facebook', [FacebookController::class, 'verify'])->name('webhook.facebook.verify');
Route::post('/webhook/facebook', [FacebookController::class, 'handle'])->name('webhook.facebook.handle');

// TikTok Business Messaging: one app-level callback URL (registered via
// TikTokAuthClient::updateWebhookCallback), connection resolved by user_openid.
Route::post('/webhook/tiktok', [TikTokController::class, 'handle'])->name('webhook.tiktok');

// MercadoPago payment / preapproval notifications. CSRF-exempt via the
// `/webhook/*` glob in bootstrap/app.php.
Route::post('/webhook/mercadopago', [MercadoPagoWebhookController::class, 'handle'])->name('webhook.mercadopago');

// API Way pushes every SMS received on a rented virtual number here. One
// webhook per account, and the platform has one account, so this single route
// carries every tenant's codes; the payload's `number_id` is what routes it.
// Signed with HMAC-SHA256 over the raw body (X-ApiWay-Signature).
Route::post('/webhook/apiway-numbers', [ApiwayNumbersController::class, 'handle'])->name('webhook.apiway-numbers');

<?php

use App\Http\Controllers\Webhook\ApiwayNumbersController;
use App\Http\Controllers\Webhook\ChatController;
use App\Http\Controllers\Webhook\FacebookController;
use App\Http\Controllers\Webhook\InstagramController;
use App\Http\Controllers\Webhook\IntegrationWebhookController;
use App\Http\Controllers\Webhook\PaymentServiceWebhookController;
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

// The group's payment service: a payment moved, or a stored instrument stopped
// working. One route for every product's worth of gateways, because which
// gateway took the money is decided over there and never reaches us. Signed
// with HMAC-SHA256 over "{timestamp}.{raw body}" (X-Payment-Signature).
// CSRF-exempt via the `/webhook/*` glob in bootstrap/app.php.
Route::post('/webhook/payments', [PaymentServiceWebhookController::class, 'handle'])->name('webhook.payments');

// API Way pushes every SMS received on a rented virtual number here. One
// webhook per account, and the platform has one account, so this single route
// carries every tenant's codes; the payload's `number_id` is what routes it.
// Signed with HMAC-SHA256 over the raw body (X-ApiWay-Signature).
Route::post('/webhook/apiway-numbers', [ApiwayNumbersController::class, 'handle'])->name('webhook.apiway-numbers');

// A workspace's own payment gateway (OpenPix, Mercado Pago) telling us a charge
// a flow issued moved. `{token}` is random per integration and is the only
// thing identifying the account; the body is a pointer we never trust — the
// charge is read back from the gateway before anything changes.
Route::post('/webhook/integrations/{provider}/{token}', [IntegrationWebhookController::class, 'handle'])
    ->where('token', '[A-Za-z0-9]+')
    ->name('webhook.integrations');

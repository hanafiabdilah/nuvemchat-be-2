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

/**
 * Inbound messages for Telegram and WhatsApp API Way.
 *
 * `{token}` is optional because Telegram carries the same secret in a header
 * instead (see App\Services\Webhook\ChatWebhookSecret), and because the URLs of
 * connections registered before secrets existed have no segment there — they
 * keep working until `webhooks:secure-chat` re-registers them.
 *
 * Throttled per connection, not per IP: Telegram and the API Way core each
 * deliver every workspace's traffic from a handful of addresses, so an IP
 * budget would start dropping real customer messages exactly when the platform
 * got busy. Per connection it bounds what one inbox can be made to absorb and
 * still leaves ten messages a second for a real one.
 */
Route::post('/webhook/chat/{id}/{token?}', [ChatController::class, 'handle'])
    ->where('id', '[0-9]+')
    ->where('token', '[A-Za-z0-9]+')
    ->middleware('throttle:webhook-chat')
    ->name('webhook.chat');

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

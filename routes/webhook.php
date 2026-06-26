<?php

use App\Http\Controllers\Webhook\ChatController;
use App\Http\Controllers\Webhook\InstagramController;
use App\Http\Controllers\Webhook\MercadoPagoWebhookController;
use App\Http\Controllers\Webhook\WhatsAppController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

Route::post('/webhook/chat/{id}', [ChatController::class, 'handle'])->name('webhook.chat');

Route::get('/webhook/instagram', [InstagramController::class, 'verify'])->name('webhook.instagram.verify');
Route::post('/webhook/instagram', [InstagramController::class, 'handle'])->name('webhook.instagram.handle');

Route::get('/webhook/whatsapp', [WhatsAppController::class, 'verify'])->name('webhook.whatsapp.verify');
Route::post('/webhook/whatsapp', [WhatsAppController::class, 'handle'])->name('webhook.whatsapp.handle');

// MercadoPago payment / preapproval notifications. CSRF-exempt via the
// `/webhook/*` glob in bootstrap/app.php.
Route::post('/webhook/mercadopago', [MercadoPagoWebhookController::class, 'handle'])->name('webhook.mercadopago');

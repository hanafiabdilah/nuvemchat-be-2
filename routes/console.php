<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Schedule Instagram token refresh daily
Schedule::command('instagram:refresh-tokens --days-before=7')
    ->daily()
    ->at('02:00')
    ->timezone('America/Sao_Paulo')
    ->onSuccess(function () {
        info('Instagram token refresh completed successfully');
    })
    ->onFailure(function () {
        logger()->error('Instagram token refresh failed');
    });

// Reactively detect WhatsApp connections whose access_token has been revoked
// (e.g. user removed app from Facebook Settings). Catches revocations missed
// by the deauth webhook or where signed_request user_id could not be matched.
Schedule::command('whatsapp:validate-tokens')
    ->hourly()
    ->onFailure(function () {
        logger()->error('WhatsApp token validation failed');
    });

// --- Billing -------------------------------------------------------------

// Generate fresh Pix charges a few days before period end (pix isn't auto-debited).
Schedule::command('billing:pix-generate --days-before=3')
    ->dailyAt('08:00')
    ->timezone('America/Sao_Paulo')
    ->onFailure(fn () => logger()->error('Pix renewal charge generation failed'));

// Advance overdue subscriptions: past_due → grace → suspended; expire stale pix.
Schedule::command('billing:process-overdue')
    ->hourly()
    ->onFailure(fn () => logger()->error('Overdue subscription processing failed'));

// Safety net for payments whose webhook was missed.
Schedule::command('billing:reconcile')
    ->dailyAt('03:00')
    ->timezone('America/Sao_Paulo')
    ->onFailure(fn () => logger()->error('Billing reconciliation failed'));

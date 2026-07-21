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

// Queue an inbox pull for each active email connection. Each connection keeps
// its own cursor in connections.last_seen_uid, so this does not rescan the
// mailbox, and SyncEmailInbox is unique-per-connection.
// The overlap lock is capped at 5 minutes: withoutOverlapping() defaults to 24h,
// so a run killed mid-flight (OOM on a large first sync) would otherwise block
// every later tick for a full day and silently stop all email sync.
Schedule::command('email:fetch')
    ->everyMinute()
    ->withoutOverlapping(5)
    ->onFailure(function () {
        logger()->error('Email inbox fetch failed');
    });

// --- Billing -------------------------------------------------------------

// Generate fresh Pix charges a few days before period end (pix isn't auto-debited).
Schedule::command('billing:pix-generate --days-before=3')
    ->dailyAt('08:00')
    ->timezone('America/Sao_Paulo')
    ->onFailure(fn () => logger()->error('Pix renewal charge generation failed'));

// Card auto-renewal via pull (no webhook needed): poll MercadoPago for renewal charges
// on subscriptions near/at their boundary and advance the paid period. Runs every 15 min
// so a renewal is picked up quickly — and before process-overdue could suspend a payer.
Schedule::command('billing:pull-cards')
    ->everyFifteenMinutes()
    ->onFailure(fn () => logger()->error('Card renewal pull failed'));

// Remind owners the day before their subscription falls due. Runs after pix-generate
// so a fresh pix charge already exists when the reminder goes out.
Schedule::command('billing:send-due-reminders --days-before=1')
    ->dailyAt('09:00')
    ->timezone('America/Sao_Paulo')
    ->onFailure(fn () => logger()->error('Due reminder dispatch failed'));

// Advance overdue subscriptions: past_due → grace → suspended; expire stale pix.
// Runs at :05 so the card pull above (:00/:15/:30/:45) has already extended payers.
Schedule::command('billing:process-overdue')
    ->hourlyAt(5)
    ->onFailure(fn () => logger()->error('Overdue subscription processing failed'));

// Broad safety net: reconcile in-flight pix + all card subscriptions once a day.
Schedule::command('billing:reconcile')
    ->dailyAt('03:00')
    ->timezone('America/Sao_Paulo')
    ->onFailure(fn () => logger()->error('Billing reconciliation failed'));

<?php

use App\Support\Heartbeat;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// The scheduler proving it is running at all. Everything else on this page is
// downstream of it, so when this one goes quiet the Back Office health page can
// say "the scheduler is down" instead of reporting nine separate failures.
Schedule::call(fn () => Heartbeat::ping('scheduler'))
    ->everyMinute()
    ->name('heartbeat')
    ->withoutOverlapping();

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

// TikTok access tokens only live ~24h (refresh tokens ~30 days): refresh hourly,
// well ahead of expiry, so sends never hit a dead token mid-conversation.
Schedule::command('tiktok:refresh-tokens --minutes-before=120')
    ->hourly()
    ->onFailure(function () {
        logger()->error('TikTok token refresh failed');
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

// Close conversations whose channel reply window has run out (WhatsApp Official
// 24h, TikTok 48h): they leave an info note in the thread and move to Resolved,
// so the Active column only holds work an agent can actually answer. Capped per
// pass — the first run on an old inbox has a lot to catch up on.
Schedule::command('conversations:close-expired-windows')
    ->hourly()
    ->withoutOverlapping(10)
    ->onFailure(function () {
        logger()->error('Expired messaging window sweep failed');
    });

// --- Media retention -----------------------------------------------------

// Delete message media past its retention window (group 30d / private 90d by
// default — config/media.php). Hourly rather than daily on purpose: each pass
// is capped, so a large backlog drains steadily in the background instead of
// one nightly run holding a worker for an hour. Once drained, a pass that
// finds nothing costs two indexed queries.
Schedule::command('media:purge')
    ->hourlyAt(50)
    ->withoutOverlapping(30)
    ->onFailure(fn () => logger()->error('Media purge failed'));

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

// --- API Way (ProxyBR partner) -------------------------------------------

// ProxyBR has NO grace period: renew included subscriptions free and invoice
// unit ones a few days ahead. Runs after billing:pix-generate.
Schedule::command('apiway:renew --days-before=3')
    ->dailyAt('08:30')
    ->timezone('America/Sao_Paulo')
    ->onFailure(fn () => logger()->error('API Way renewal pass failed'));

// Mirror ProxyBR's hourly revoke cron (runs at :20, after theirs likely fired):
// expire overdue rows, release connections, reconcile partner state.
Schedule::command('apiway:sync')
    ->hourlyAt(20)
    ->onFailure(fn () => logger()->error('API Way sync failed'));

// --- Virtual numbers (API Way) -------------------------------------------

// Charge the coming month of every rented number to the tenant's balance —
// and delete the ones nobody can pay for. There is no upstream renew call: an
// API Way number renews itself and bills the platform on renews_at, so missing
// this window does not lose the number, it buys another month of it with the
// platform's money.
Schedule::command('numbers:renew --days-before=3')
    ->dailyAt('08:45')
    ->timezone('America/Sao_Paulo')
    ->onFailure(fn () => logger()->error('Virtual number renewal pass failed'));

// Reconcile with the account: renewal dates, numbers cancelled upstream,
// purchases that timed out (adopted or refunded), and numbers we are billed for
// that no workspace owns.
Schedule::command('numbers:sync')
    ->hourlyAt(35)
    ->onFailure(fn () => logger()->error('Virtual number sync failed'));

// --- Broadcasts ----------------------------------------------------------

// Start campaigns whose scheduled time has come, and revive any whose pump job
// died with its worker (the pump does not retry — a retried batch would re-send
// messages people already got, so recovery has to be deliberate).
// withoutOverlapping is capped at 5 minutes for the same reason email:fetch is:
// a run killed mid-flight must not hold the lock for the default 24 hours and
// silently freeze every campaign on the platform.
Schedule::command('broadcasts:tick')
    ->everyMinute()
    ->withoutOverlapping(5)
    ->onFailure(fn () => logger()->error('Broadcast tick failed'));

// Fire Instagram posts whose scheduled time has come, and re-dispatch any whose
// publish chain died mid-flight. Both halves are idempotent: the job claims the
// post before doing anything, and the publisher reuses containers it already
// created rather than building new ones.
Schedule::command('instagram:publish-scheduled')
    ->everyMinute()
    ->withoutOverlapping(5)
    ->onFailure(fn () => logger()->error('Instagram scheduled publish failed'));

// --- Leads ---------------------------------------------------------------

// Recompute every open lead's temperature.
//
// This is the half of the axis only a schedule can do: an inbound message can
// warm a lead on the spot, but going quiet emits no event at all. Without this
// pass a score would fall only when something else happened to touch the lead —
// exactly backwards, since the cards worth surfacing are the untouched ones.
// Only a band change (frio↔morno↔quente) is broadcast, so this does not set
// every open board flickering once an hour.
Schedule::command('leads:score')
    ->hourly()
    ->withoutOverlapping(10)
    ->onFailure(fn () => logger()->error('Lead scoring pass failed'));

// Retire leads nothing has happened to inside each tenant's own window.
//
// Auto-creation means every stranger who asked a price becomes a card, and a
// board full of people who wrote once and vanished stops being read at all.
// Daily rather than hourly: the windows are measured in weeks, so a pass an
// hour would be almost entirely wasted work. Every tenant sets its own number
// of days, can turn the sweep off, and decides whether it may touch cards an
// agent has actually worked — see LeadSettings.
Schedule::command('leads:close-stale')
    ->dailyAt('03:30')
    ->timezone('America/Sao_Paulo')
    ->withoutOverlapping(30)
    ->onFailure(fn () => logger()->error('Stale lead sweep failed'));

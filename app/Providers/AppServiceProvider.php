<?php

namespace App\Providers;

use App\Models\Conversation;
use App\Models\Message;
use App\Observers\ConversationObserver;
use App\Observers\MessageAttachmentObserver;
use App\Observers\MessageLeadObserver;
use App\Services\Email\EmailInboxClientFactory;
use App\Services\Email\WebklexEmailInboxClientFactory;
use App\Support\Heartbeat;
use App\Support\PlatformUrl;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(EmailInboxClientFactory::class, WebklexEmailInboxClientFactory::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Webhooks, OAuth callbacks and signed links always carry the platform
        // domain, never the country domain a request happened to arrive on.
        PlatformUrl::apply($this->app['url']);

        // Register observers
        Conversation::observe(ConversationObserver::class);
        Message::observe(MessageAttachmentObserver::class);
        Message::observe(MessageLeadObserver::class);

        // lead.assigned / lead.stage_changed / lead.won / lead.lost webhooks.
        \App\Services\Webhooks\LeadWebhooks::register();

        $this->registerQueueHeartbeat();

        // Public API. Counted per key, not per IP: the callers are servers, and
        // two integrations behind one NAT must not share a budget. Runs after
        // V1\ApiKeyAuth has put the key on the request.
        RateLimiter::for('public-api', function (Request $request) {
            $key = $request->attributes->get('api_key');

            return Limit::perMinute(120)->by($key ? 'api-key:'.$key->id : 'ip:'.$request->ip());
        });

        // Inbound chat webhooks (Telegram, WhatsApp API Way). Counted per
        // connection rather than per IP for the same reason public-api is
        // counted per key: both senders deliver every workspace's traffic from
        // a handful of addresses, so an IP budget would throttle real customer
        // messages the moment the platform got busy. Ten a second is far above
        // any single inbox's real rate and still bounds what one connection can
        // be made to absorb before the secret check even runs.
        RateLimiter::for('webhook-chat', function (Request $request) {
            return Limit::perMinute(600)->by('webhook-chat:'.$request->route('id'));
        });

        // Sign-in, both surfaces. A blunt per-address flood stop that runs
        // before the controller does any work at all — the guessing budget
        // itself lives in App\Services\Auth\LoginThrottle, which counts
        // failures rather than requests so a busy office cannot lock itself out
        // by signing in successfully.
        //
        // ⚠️ Not named `login`: FortifyServiceProvider already registers a
        // limiter under that name for the Inertia starter-kit routes, and the
        // second registration would quietly replace the first.
        RateLimiter::for('sign-in', function (Request $request) {
            return Limit::perMinute(20)->by('sign-in:'.$request->ip());
        });

        $this->registerWidgetLimiters();
    }

    /**
     * The Live Chat Widget's public API.
     *
     * Reachable by anyone who reads a customer's page source for its `app_id`,
     * and until now it had no limit of any kind — while opening a session
     * writes three permanent rows, an upload takes a 50 MB file, and a message
     * runs the flow engine and can spend the workspace's prepaid AI balance.
     *
     * ⚠️ Two keys on every limiter, not one. Per address alone, one office
     * behind a single NAT would throttle its own visitors; per app id alone, a
     * single attacker could exhaust a workspace's budget for everybody. Both
     * together means a flood is bounded from whichever side it comes.
     *
     * ⚠️ Limits are per route group, not global, because the work differs by
     * two orders of magnitude between polling `status` and starting an AI turn.
     */
    private function registerWidgetLimiters(): void
    {
        $perVisitorAndApp = static function (Request $request, int $perAddress, int $perApp): array {
            // `appId` on the bootstrap routes, `sessionToken` on the rest —
            // either identifies the workspace footing the bill.
            $scope = (string) ($request->route('appId') ?? $request->route('sessionToken') ?? 'unknown');

            return [
                Limit::perMinute($perAddress)->by('widget:'.$request->ip().'|'.$scope),
                Limit::perMinute($perApp)->by('widget-app:'.$scope),
            ];
        };

        // Polling and history. A widget left open asks for `status` on a timer,
        // so this has to be comfortable for a person doing nothing at all.
        RateLimiter::for('widget-read', fn (Request $request) => $perVisitorAndApp($request, 60, 6000));

        // Rows that are written once and never cleaned up on their own.
        RateLimiter::for('widget-session', fn (Request $request) => $perVisitorAndApp($request, 5, 600));

        // A person typing. Well above conversational pace, far below a loop.
        RateLimiter::for('widget-send', fn (Request $request) => $perVisitorAndApp($request, 20, 2000));

        // Bytes on our disk and a bill from the object store.
        RateLimiter::for('widget-upload', fn (Request $request) => $perVisitorAndApp($request, 5, 300));
    }

    /**
     * Let each queue worker report that it is alive.
     *
     * A worker is only observable through the work it does, so the signal is
     * taken from job completion — throttled, because a worker draining a
     * backlog would otherwise turn one status row into thousands of writes a
     * minute. Keyed per queue: `default` and `broadcasts` run as separate
     * services and die separately, and "broadcasts stopped" is a different
     * incident from "media downloads stopped".
     */
    private function registerQueueHeartbeat(): void
    {
        Queue::after(function (JobProcessed $event) {
            $queue = $event->job->getQueue() ?: 'default';

            // Queue names arrive fully qualified on some drivers (SQS URLs,
            // Redis prefixes); the last segment is the name we schedule by.
            $queue = (string) Str::afterLast($queue, '/');

            Heartbeat::throttledPing("queue:{$queue}", 60);
        });
    }
}

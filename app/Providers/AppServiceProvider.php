<?php

namespace App\Providers;

use App\Models\Conversation;
use App\Models\Message;
use App\Observers\ConversationObserver;
use App\Observers\MessageAttachmentObserver;
use App\Services\Email\EmailInboxClientFactory;
use App\Services\Email\WebklexEmailInboxClientFactory;
use App\Support\Heartbeat;
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
        // Register observers
        Conversation::observe(ConversationObserver::class);
        Message::observe(MessageAttachmentObserver::class);

        $this->registerQueueHeartbeat();

        // Workspace-level public API. Counted per key, not per IP: the callers
        // are servers, and two integrations behind one NAT must not share a
        // budget. Runs after V1\TenantApiAuth has put the key on the request.
        RateLimiter::for('public-api', function (Request $request) {
            $key = $request->attributes->get('tenant_api_key');

            return Limit::perMinute(120)->by($key ? 'tenant-api-key:'.$key->id : 'ip:'.$request->ip());
        });
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

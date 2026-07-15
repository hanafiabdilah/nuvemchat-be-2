<?php

namespace App\Providers;

use App\Models\Conversation;
use App\Observers\ConversationObserver;
use App\Services\Email\EmailInboxClientFactory;
use App\Services\Email\WebklexEmailInboxClientFactory;
use Illuminate\Support\ServiceProvider;

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
    }
}

<?php

namespace App\Providers;

use App\Models\Conversation;
use App\Observers\ConversationObserver;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
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

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

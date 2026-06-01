<?php

use App\Http\Controllers\Widget\WidgetController;
use Illuminate\Support\Facades\Route;

Route::prefix('widget-api')->group(function () {
    Route::get('/config/{appId}', [WidgetController::class, 'config'])
        ->name('widget.config');

    Route::post('/session/{appId}', [WidgetController::class, 'initSession'])
        ->name('widget.session.init');

    Route::get('/session/{sessionToken}', [WidgetController::class, 'status'])
        ->name('widget.session.status');

    Route::post('/session/{sessionToken}/seen', [WidgetController::class, 'markSeen'])
        ->name('widget.session.seen');

    Route::post('/session/{sessionToken}/messages', [WidgetController::class, 'sendMessage'])
        ->name('widget.session.send-message');

    Route::get('/session/{sessionToken}/messages', [WidgetController::class, 'history'])
        ->name('widget.session.history');
});

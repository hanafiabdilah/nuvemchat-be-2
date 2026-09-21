<?php

use App\Http\Controllers\Widget\WidgetController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Live Chat Widget
|--------------------------------------------------------------------------
|
| Called from third-party sites with no session and no token but the one this
| endpoint issues, so everything here is reachable by anyone who reads a
| customer's page source for its `app_id`.
|
| ⚠️ Every route carries a throttle, and the limits are not uniform because the
| work behind them is not. `session` writes three permanent rows, `uploads`
| takes a file, and `messages` runs the flow engine and can spend the
| workspace's prepaid AI balance — while `status` is polled on a timer by a
| widget that is merely open. Reading is generous; anything that writes,
| stores or spends is not.
|
| Limits are keyed per address AND per app id (see the `widget-*` limiters in
| AppServiceProvider), so one busy customer cannot spend another's budget and
| one visitor cannot spend their own workspace's.
|
*/

Route::prefix('widget-api')->group(function () {
    // Read-only, and fetched once per page load. Generous, because a site with
    // the widget on every page is normal.
    Route::get('/config/{appId}', [WidgetController::class, 'config'])
        ->middleware('throttle:widget-read')
        ->name('widget.config');

    // ⚠️ The expensive one. Each call writes a contact, a conversation and a
    // session — permanently. One production workspace already carries 4,591
    // conversations opened by page views that never said anything; a script
    // would produce the same in seconds, and stopping it does not undo it.
    Route::post('/session/{appId}', [WidgetController::class, 'initSession'])
        ->middleware('throttle:widget-session')
        ->name('widget.session.init');

    Route::get('/session/{sessionToken}', [WidgetController::class, 'status'])
        ->middleware('throttle:widget-read')
        ->name('widget.session.status');

    Route::post('/session/{sessionToken}/seen', [WidgetController::class, 'markSeen'])
        ->middleware('throttle:widget-read')
        ->name('widget.session.seen');

    // Bytes on our disk, and a bill from the object store.
    Route::post('/session/{sessionToken}/uploads', [WidgetController::class, 'upload'])
        ->middleware('throttle:widget-upload')
        ->name('widget.session.upload');

    // ⚠️ Runs the flow engine synchronously and may start an AI turn charged to
    // the workspace's prepaid balance. Rated for a person typing, not for a
    // loop.
    Route::post('/session/{sessionToken}/messages', [WidgetController::class, 'sendMessage'])
        ->middleware('throttle:widget-send')
        ->name('widget.session.send-message');

    Route::get('/session/{sessionToken}/messages', [WidgetController::class, 'history'])
        ->middleware('throttle:widget-read')
        ->name('widget.session.history');
});

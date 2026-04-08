<?php

use App\Http\Controllers\Webhook\ChatController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

Route::get('/webhook/chat/{id}', function($id){
    // Note: PHP converts periods (.) to underscores (_) in parameter names
    $challenge = request()->hub_challenge;

    Log::info("Webhook verification for connection ID: {$id}", [
        'challenge' => $challenge,
        'all_params' => request()->all(),
    ]);

    return response($challenge, 200);
})->name('webhook.chat');

Route::post('/webhook/chat/{id}', [ChatController::class, 'handle'])->name('webhook.chat');

Route::get('/webhook/instagram', function(Request $request){
    $challenge = $request->query('hub_challenge');
    $verifyToken = $request->query('hub_verify_token');
    $mode = $request->query('hub_mode');

    Log::info('Instagram webhook verification request', [
        'mode' => $mode,
        'verify_token' => $verifyToken,
        'challenge' => $challenge,
    ]);

    if($verifyToken !== config('services.instagram.webhook_verify_token')) {
        Log::warning('Invalid Instagram webhook verification token', [
            'received_token' => $verifyToken,
            'expected_token' => config('services.instagram.webhook_verify_token'),
        ]);

        return response('Invalid verification token', 403);
    }

    return response($challenge, 200);
})->name('webhook.instagram');

Route::post('/webhook/instagram', function(Request $request){
    Log::info('Instagram webhook received', $request->all());

    return response()->json([
        'message' => 'Webhook received successfully',
    ], 200);
})->name('webhook.instagram');

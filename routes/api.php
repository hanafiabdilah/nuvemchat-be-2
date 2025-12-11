<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ConnectionController;
use App\Http\Controllers\Api\ConversationController;
use App\Http\Controllers\Api\V1\SendMessageController;
use App\Http\Middleware\V1\Auth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/auth/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function(){
    Route::get('/user', function (Request $request) {
        return $request->user();
    });

    Route::get('/conversations', [ConversationController::class, 'index']);
    Route::get('/conversations/{id}', [ConversationController::class, 'show']);
    Route::get('/conversations/{id}/messages', [ConversationController::class, 'messages']);
    Route::post('/conversations/{id}/messages', [ConversationController::class, 'sendMessage']);

    Route::get('/connections', [ConnectionController::class, 'index']);
    Route::post('/connections', [ConnectionController::class, 'store']);
    Route::post('/connections/{id}/connect', [ConnectionController::class, 'connect']);
    Route::post('/connections/{id}/generate-api-key', [ConnectionController::class, 'generateApiKey']);
});

Route::prefix('/v1')->middleware(Auth::class)->group(function(){
    Route::post('send-message', [SendMessageController::class, 'handle']);
});

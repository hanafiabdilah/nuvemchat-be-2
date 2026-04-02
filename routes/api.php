<?php

use App\Http\Controllers\AgentController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ConnectionController;
use App\Http\Controllers\Api\ContactController;
use App\Http\Controllers\Api\ConversationController;
use App\Http\Controllers\Api\MessageController;
use App\Http\Controllers\Api\TagController;
use App\Http\Controllers\Api\V1\SendMessageController;
use App\Http\Middleware\EnsureOwner;
use App\Http\Middleware\V1\Auth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/auth/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function(){
    Route::get('/user', function (Request $request) {
        return $request->user();
    });

    Route::get('/messages', [MessageController::class, 'index']);

    Route::get('/conversations', [ConversationController::class, 'index']);
    Route::get('/conversations/{id}', [ConversationController::class, 'show']);
    // Route::get('/conversations/{id}/messages', [ConversationController::class, 'messages']);
    Route::post('/conversations/{id}/messages', [ConversationController::class, 'sendMessage']);
    Route::get('/conversations/{id}/read', [ConversationController::class, 'read']);
    Route::post('/conversations/{id}/accept', [ConversationController::class, 'accept']);
    Route::post('/conversations/{id}/resolve', [ConversationController::class, 'resolve']);
    Route::post('/conversations/{id}/tags', [ConversationController::class, 'syncTags']);

    Route::get('/connections', [ConnectionController::class, 'index']);
    Route::get('/tags', [TagController::class, 'index']);

    Route::middleware(EnsureOwner::class)->group(function(){
        Route::post('/connections', [ConnectionController::class, 'store']);
        Route::post('/connections/{id}/connect', [ConnectionController::class, 'connect']);
        Route::post('/connections/{id}/check-status', [ConnectionController::class, 'checkStatus']);
        Route::post('/connections/{id}/generate-api-key', [ConnectionController::class, 'generateApiKey']);

        Route::post('/tags', [TagController::class, 'store']);
        Route::put('/tags/{id}', [TagController::class, 'update']);
        Route::delete('/tags/{id}', [TagController::class, 'destroy']);

        Route::get('/agents', [AgentController::class, 'index']);
        Route::post('/agents', [AgentController::class, 'store']);
        Route::put('/agents/{id}', [AgentController::class, 'update']);
        Route::delete('/agents/{id}', [AgentController::class, 'destroy']);
        Route::post('/agents/{id}/connections', [AgentController::class, 'syncConnections']);

        Route::get('/contacts', [ContactController::class, 'index']);
    });
});

Route::prefix('/v1')->middleware(Auth::class)->group(function(){
    Route::post('send-message', [SendMessageController::class, 'handle']);
});

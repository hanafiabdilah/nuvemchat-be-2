<?php

use App\Http\Controllers\Api\AgentController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ConnectionController;
use App\Http\Controllers\Api\ContactController;
use App\Http\Controllers\Api\ConversationController;
use App\Http\Controllers\Api\FlowController;
use App\Http\Controllers\Api\MessageController;
use App\Http\Controllers\Api\PermissionController;
use App\Http\Controllers\Api\QuickMessageController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\TagController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\V1\SendMessageController;
use App\Http\Middleware\V1\Auth;
use Illuminate\Support\Facades\Route;

Route::post('/auth/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function(){
    Route::get('/user', [UserController::class, 'index']);
    Route::put('/user', [UserController::class, 'update']);

    Route::get('/messages', [MessageController::class, 'index']);

    Route::get('/conversations', [ConversationController::class, 'index']);
    Route::post('/conversations', [ConversationController::class, 'store']);
    Route::get('/conversations/{id}', [ConversationController::class, 'show']);
    // Route::get('/conversations/{id}/messages', [ConversationController::class, 'messages']);
    Route::post('/conversations/{id}/send-message', [ConversationController::class, 'sendMessage']);
    Route::post('/conversations/{id}/send-image', [ConversationController::class, 'sendImage']);
    Route::post('/conversations/{id}/send-audio', [ConversationController::class, 'sendAudio']);
    Route::post('/conversations/{id}/send-video', [ConversationController::class, 'sendVideo']);
    Route::post('/conversations/{id}/send-document', [ConversationController::class, 'sendDocument']);
    Route::get('/conversations/{id}/read', [ConversationController::class, 'read']);
    Route::post('/conversations/{id}/accept', [ConversationController::class, 'accept']);
    Route::post('/conversations/{id}/resolve', [ConversationController::class, 'resolve']);
    Route::post('/conversations/{id}/tags', [ConversationController::class, 'syncTags']);
    Route::put('/conversations/{id}/messages/{message_id}', [ConversationController::class, 'editMessage']);
    Route::delete('/conversations/{id}/messages/{message_id}', [ConversationController::class, 'deleteMessage']);

    Route::get('/connections', [ConnectionController::class, 'index']);
    Route::get('/tags', [TagController::class, 'index']);

    Route::get('/quick-messages', [QuickMessageController::class, 'index']);
    Route::post('/quick-messages', [QuickMessageController::class, 'store']);
    Route::put('/quick-messages/{quick_message}', [QuickMessageController::class, 'update']);
    Route::delete('/quick-messages/{quick_message}', [QuickMessageController::class, 'destroy']);

    Route::get('/contacts', [ContactController::class, 'index']);
    Route::post('/contacts', [ContactController::class, 'store']);
    Route::put('/contacts/{id}', [ContactController::class, 'update'])->middleware('permission:contacts.update');

    // Connection routes - protected by permissions
    Route::post('/connections', [ConnectionController::class, 'store'])->middleware('permission:connections.create');
    Route::post('/connections/{id}/connect', [ConnectionController::class, 'connect'])->middleware('permission:connections.connect');
    Route::get('/connections/{id}/oauth', [ConnectionController::class, 'oauth'])->middleware('permission:connections.oauth');
    Route::put('/connections/{id}', [ConnectionController::class, 'update'])->middleware('permission:connections.update');
    Route::post('/connections/{id}/check-status', [ConnectionController::class, 'checkStatus'])->middleware('permission:connections.check-status');
    Route::post('/connections/{id}/generate-api-key', [ConnectionController::class, 'generateApiKey'])->middleware('permission:connections.generate-api-key');
    Route::post('/connections/{id}/disconnect', [ConnectionController::class, 'disconnect'])->middleware('permission:connections.disconnect');
    Route::delete('/connections/{id}', [ConnectionController::class, 'destroy'])->middleware('permission:connections.delete');
    Route::put('/connections/{id}/automated-messages', [ConnectionController::class, 'updateAutomatedMessages'])->middleware('permission:connections.update-automated-messages');

    // Tag routes - protected by permissions
    Route::post('/tags', [TagController::class, 'store'])->middleware('permission:tags.create');
    Route::put('/tags/{id}', [TagController::class, 'update'])->middleware('permission:tags.update');
    Route::delete('/tags/{id}', [TagController::class, 'destroy'])->middleware('permission:tags.delete');

    // Agent routes - protected by permissions
    Route::get('/agents', [AgentController::class, 'index'])->middleware('permission:agents.view');
    Route::post('/agents', [AgentController::class, 'store'])->middleware('permission:agents.create');
    Route::put('/agents/{id}', [AgentController::class, 'update'])->middleware('permission:agents.update');
    Route::delete('/agents/{id}', [AgentController::class, 'destroy'])->middleware('permission:agents.delete');
    Route::post('/agents/{id}/connections', [AgentController::class, 'syncConnections'])->middleware('permission:agents.sync-connections');
    Route::post('/agents/{id}/assign-roles', [AgentController::class, 'assignRoles'])->middleware('permission:agents.assign-roles');
    Route::post('/agents/{id}/assign-permissions', [AgentController::class, 'assignPermissions'])->middleware('permission:agents.assign-permissions');

    // Role management - protected by permissions
    Route::get('/roles', [RoleController::class, 'index'])->middleware('permission:roles.view');
    Route::post('/roles', [RoleController::class, 'store'])->middleware('permission:roles.create');
    Route::put('/roles/{id}', [RoleController::class, 'update'])->middleware('permission:roles.update');
    Route::delete('/roles/{id}', [RoleController::class, 'destroy'])->middleware('permission:roles.delete');

    // Flow routes - protected by permissions
    Route::get('/flows', [FlowController::class, 'index'])->middleware('permission:flows.view');
    Route::post('/flows', [FlowController::class, 'store'])->middleware('permission:flows.create');
    Route::put('/flows/{id}', [FlowController::class, 'update'])->middleware('permission:flows.update');
    Route::delete('/flows/{id}', [FlowController::class, 'destroy'])->middleware('permission:flows.delete');

    // Permission list (read-only) - permissions are managed via seeders/migrations only
    Route::get('/permissions', [PermissionController::class, 'index']);
});

Route::prefix('/v1')->middleware(Auth::class)->group(function(){
    Route::post('send-message', [SendMessageController::class, 'handle']);
});

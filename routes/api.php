<?php

use App\Http\Controllers\Api\AgentController;
use App\Http\Controllers\Api\AiHub\AgentController as AiHubAgentController;
use App\Http\Controllers\Api\AiHub\AgentKnowledgeController as AiHubAgentKnowledgeController;
use App\Http\Controllers\Api\AiHub\AgentProfileController as AiHubAgentProfileController;
use App\Http\Controllers\Api\AiHub\AgentSkillController as AiHubAgentSkillController;
use App\Http\Controllers\Api\AiHub\AgentTrainingExampleController as AiHubAgentTrainingExampleController;
use App\Http\Controllers\Api\AiHub\ModelController as AiHubModelController;
use App\Http\Controllers\Api\AiHub\ProviderCredentialController as AiHubProviderCredentialController;
use App\Http\Controllers\Api\AiHub\ProvisionController as AiHubProvisionController;
use App\Http\Controllers\Api\Admin\AuthController as AdminAuthController;
use App\Http\Controllers\Api\Admin\CustomerController as AdminCustomerController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ConnectionController;
use App\Http\Controllers\Api\ContactController;
use App\Http\Controllers\Api\ConversationController;
use App\Http\Controllers\Api\FlowController;
use App\Http\Controllers\Api\MessageController;
use App\Http\Controllers\Api\PermissionController;
use App\Http\Controllers\Api\QuickMessageController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\StatisticsController;
use App\Http\Controllers\Api\TagController;
use App\Http\Controllers\Api\UploadController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\V1\SendMessageController;
use App\Http\Middleware\V1\Auth;
use Illuminate\Support\Facades\Route;

Route::post('/auth/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function(){
    Route::post('/uploads', [UploadController::class, 'store']);

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
    Route::post('/connections/{id}/migrate', [ConnectionController::class, 'migrate'])->middleware('permission:connections.connect');
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
    Route::get('/flows/{id}', [FlowController::class, 'show'])->middleware('permission:flows.view');
    Route::put('/flows/{id}', [FlowController::class, 'update'])->middleware('permission:flows.update');
    Route::delete('/flows/{id}', [FlowController::class, 'destroy'])->middleware('permission:flows.delete');
    Route::post('/flows/{id}/save', [FlowController::class, 'saveNodesAndEdges'])->middleware('permission:flows.update');

    // Permission list (read-only) - permissions are managed via seeders/migrations only
    Route::get('/permissions', [PermissionController::class, 'index']);

    // Statistics
    Route::get('/statistics/tenant', [StatisticsController::class, 'tenant'])->middleware('permission:statistics.tenant.view');
    Route::get('/statistics/agents', [StatisticsController::class, 'agents'])->middleware('permission:statistics.agents.view');

    // AI Agent Hub routes - protected by permissions
    Route::prefix('ai-hub')->group(function () {
        Route::post('/provision', [AiHubProvisionController::class, 'store'])->middleware('permission:ai-agents.create');
        Route::get('/models', [AiHubModelController::class, 'index'])->middleware('permission:ai-agents.view');

        Route::get('/provider-credentials', [AiHubProviderCredentialController::class, 'index'])->middleware('permission:ai-agents.view');
        Route::post('/provider-credentials', [AiHubProviderCredentialController::class, 'store'])->middleware('permission:ai-agents.create');
        Route::patch('/provider-credentials/{id}', [AiHubProviderCredentialController::class, 'update'])->middleware('permission:ai-agents.update');
        Route::delete('/provider-credentials/{id}', [AiHubProviderCredentialController::class, 'destroy'])->middleware('permission:ai-agents.delete');

        Route::get('/agents', [AiHubAgentController::class, 'index'])->middleware('permission:ai-agents.view');
        Route::post('/agents', [AiHubAgentController::class, 'store'])->middleware('permission:ai-agents.create');
        Route::patch('/agents/{id}', [AiHubAgentController::class, 'update'])->middleware('permission:ai-agents.update');
        Route::delete('/agents/{id}', [AiHubAgentController::class, 'destroy'])->middleware('permission:ai-agents.delete');

        // Agent training — Profile (1-to-1 with agent, upsert via PUT)
        Route::get('/agents/{agentId}/profile', [AiHubAgentProfileController::class, 'show'])->middleware('permission:ai-agents.view');
        Route::put('/agents/{agentId}/profile', [AiHubAgentProfileController::class, 'update'])->middleware('permission:ai-agents.update');

        // Agent training — Knowledge (1-to-many, CRUD)
        Route::get('/agents/{agentId}/knowledge', [AiHubAgentKnowledgeController::class, 'index'])->middleware('permission:ai-agents.view');
        Route::post('/agents/{agentId}/knowledge', [AiHubAgentKnowledgeController::class, 'store'])->middleware('permission:ai-agents.create');
        Route::patch('/agents/{agentId}/knowledge/{knowledgeId}', [AiHubAgentKnowledgeController::class, 'update'])->middleware('permission:ai-agents.update');
        Route::delete('/agents/{agentId}/knowledge/{knowledgeId}', [AiHubAgentKnowledgeController::class, 'destroy'])->middleware('permission:ai-agents.delete');

        // Agent training — Skills (1-to-many, CRUD)
        Route::get('/agents/{agentId}/skills', [AiHubAgentSkillController::class, 'index'])->middleware('permission:ai-agents.view');
        Route::post('/agents/{agentId}/skills', [AiHubAgentSkillController::class, 'store'])->middleware('permission:ai-agents.create');
        Route::patch('/agents/{agentId}/skills/{skillId}', [AiHubAgentSkillController::class, 'update'])->middleware('permission:ai-agents.update');
        Route::delete('/agents/{agentId}/skills/{skillId}', [AiHubAgentSkillController::class, 'destroy'])->middleware('permission:ai-agents.delete');

        // Agent training — Training Examples (1-to-many, CRUD)
        Route::get('/agents/{agentId}/training-examples', [AiHubAgentTrainingExampleController::class, 'index'])->middleware('permission:ai-agents.view');
        Route::post('/agents/{agentId}/training-examples', [AiHubAgentTrainingExampleController::class, 'store'])->middleware('permission:ai-agents.create');
        Route::patch('/agents/{agentId}/training-examples/{exampleId}', [AiHubAgentTrainingExampleController::class, 'update'])->middleware('permission:ai-agents.update');
        Route::delete('/agents/{agentId}/training-examples/{exampleId}', [AiHubAgentTrainingExampleController::class, 'destroy'])->middleware('permission:ai-agents.delete');
    });
});

Route::prefix('/v1')->middleware(Auth::class)->group(function(){
    Route::post('send-message', [SendMessageController::class, 'handle']);
});

/*
|--------------------------------------------------------------------------
| Back Office (Platform Admin) API
|--------------------------------------------------------------------------
| Separate, isolated admin surface for managing every tenant/customer.
| Login is public; everything else requires a Sanctum token belonging to a
| `super-admin` user with no tenant scope (see EnsureUserIsSuperAdmin).
*/
Route::prefix('admin')->group(function () {
    Route::post('/auth/login', [AdminAuthController::class, 'login']);

    Route::middleware(['auth:sanctum', 'super-admin'])->group(function () {
        Route::get('/auth/me', [AdminAuthController::class, 'me']);
        Route::post('/auth/logout', [AdminAuthController::class, 'logout']);

        // Customers (tenants) — platform-wide, not tenant-scoped
        Route::get('/customers', [AdminCustomerController::class, 'index']);
        Route::get('/customers/{tenant}', [AdminCustomerController::class, 'show']);
    });
});

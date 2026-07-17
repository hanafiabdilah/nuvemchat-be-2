<?php

use App\Enums\Billing\Feature;
use App\Http\Controllers\Api\AgentController;
use App\Http\Controllers\Api\AiHub\AgentController as AiHubAgentController;
use App\Http\Controllers\Api\AiHub\AgentKnowledgeController as AiHubAgentKnowledgeController;
use App\Http\Controllers\Api\AiHub\AgentProfileController as AiHubAgentProfileController;
use App\Http\Controllers\Api\AiHub\AgentSkillController as AiHubAgentSkillController;
use App\Http\Controllers\Api\AiHub\AgentTrainingExampleController as AiHubAgentTrainingExampleController;
use App\Http\Controllers\Api\AiHub\ModelController as AiHubModelController;
use App\Http\Controllers\Api\AiHub\ProviderCredentialController as AiHubProviderCredentialController;
use App\Http\Controllers\Api\AiHub\ProvisionController as AiHubProvisionController;
use App\Http\Controllers\Api\Admin\AccountController as AdminAccountController;
use App\Http\Controllers\Api\Admin\AdminController as AdminAdminController;
use App\Http\Controllers\Api\Admin\AuditLogController as AdminAuditLogController;
use App\Http\Controllers\Api\Admin\LogViewerController as AdminLogViewerController;
use App\Http\Controllers\Api\Admin\AdminInvoiceController;
use App\Http\Controllers\Api\Admin\AdminPlanController;
use App\Http\Controllers\Api\Admin\AdminSubscriptionController;
use App\Http\Controllers\Api\Admin\AdminSettingsController;
use App\Http\Controllers\Api\Admin\AuthController as AdminAuthController;
use App\Http\Controllers\Api\Admin\ConnectionController as AdminConnectionController;
use App\Http\Controllers\Api\Admin\CustomerController as AdminCustomerController;
use App\Http\Controllers\Api\Admin\PermissionController as AdminPermissionController;
use App\Http\Controllers\Api\Admin\RoleController as AdminRoleController;
use App\Http\Controllers\Api\Admin\StatisticsController as AdminStatisticsController;
use App\Http\Controllers\Api\Admin\StatsController as AdminStatsController;
use App\Http\Controllers\Api\Admin\UserController as AdminUserController;
use App\Http\Controllers\Api\Admin\WhatsappLogController as AdminWhatsappLogController;
use App\Http\Controllers\Api\Admin\OtpController as AdminOtpController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\OtpController;
use App\Http\Controllers\Api\Billing\BillingController;
use App\Http\Controllers\Api\ConnectionController;
use App\Http\Controllers\Api\ImpersonationController;
use App\Http\Controllers\Api\ContactController;
use App\Http\Controllers\Api\ConversationController;
use App\Http\Controllers\Api\FlowController;
use App\Http\Controllers\Api\MessageController;
use App\Http\Controllers\Api\MessageTemplateController;
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
Route::post('/auth/register', [AuthController::class, 'register']);

// Public: tenant app exchanges a one-time Back Office code for a session.
Route::post('/impersonate/redeem', [ImpersonationController::class, 'redeem']);

// WhatsApp number verification (post-registration). Authenticated but intentionally
// outside the subscription.active gate so a brand-new tenant can verify before paying.
Route::middleware('auth:sanctum')->prefix('auth/otp')->group(function () {
    Route::get('/status', [OtpController::class, 'status']);
    Route::post('/send', [OtpController::class, 'send']);
    Route::post('/verify', [OtpController::class, 'verify']);
});

Route::middleware(['auth:sanctum', 'whatsapp.verified', 'subscription.active'])->group(function(){
    Route::post('/uploads', [UploadController::class, 'store']);

    Route::get('/user', [UserController::class, 'index']);
    Route::put('/user', [UserController::class, 'update']);

    // Billing (tenant-side). Exempt from the subscription.active gate so a
    // suspended tenant can still load the page and pay (see EnsureSubscriptionActive).
    Route::prefix('billing')->name('billing.')->group(function () {
        Route::get('/config', [BillingController::class, 'config'])->name('config');
        Route::get('/subscription', [BillingController::class, 'subscription'])->middleware('permission:billing.view')->name('subscription');
        Route::get('/invoices', [BillingController::class, 'invoices'])->middleware('permission:billing.view')->name('invoices');
        Route::get('/invoices/{invoice}/status', [BillingController::class, 'invoiceStatus'])->middleware('permission:billing.view')->name('invoice-status');
        Route::post('/subscribe', [BillingController::class, 'subscribe'])->middleware('permission:billing.manage')->name('subscribe');
        Route::post('/quantity', [BillingController::class, 'changeQuantity'])->middleware('permission:billing.manage')->name('quantity');
        Route::post('/pix/refresh', [BillingController::class, 'refreshPix'])->middleware('permission:billing.manage')->name('pix-refresh');
        Route::post('/cancel', [BillingController::class, 'cancel'])->middleware('permission:billing.manage')->name('cancel');
    });
    Route::get('/plans', [BillingController::class, 'plans'])->middleware('permission:billing.view')->name('plans.index');

    Route::middleware('feature:' . Feature::Chat->value)->group(function () {
        Route::get('/messages', [MessageController::class, 'index']);

        Route::get('/conversations', [ConversationController::class, 'index']);
        Route::post('/conversations', [ConversationController::class, 'store']);
        Route::post('/conversations/compose-email', [ConversationController::class, 'composeEmail']);
        Route::get('/conversations/{id}', [ConversationController::class, 'show']);
        Route::get('/conversations/{id}/variables', [ConversationController::class, 'variables']);
        // Route::get('/conversations/{id}/messages', [ConversationController::class, 'messages']);
        Route::post('/conversations/{id}/send-message', [ConversationController::class, 'sendMessage']);
        Route::post('/conversations/{id}/send-image', [ConversationController::class, 'sendImage']);
        Route::post('/conversations/{id}/send-audio', [ConversationController::class, 'sendAudio']);
        Route::post('/conversations/{id}/send-video', [ConversationController::class, 'sendVideo']);
        Route::post('/conversations/{id}/send-document', [ConversationController::class, 'sendDocument']);
        Route::post('/conversations/{id}/send-interactive', [ConversationController::class, 'sendInteractive']);
        Route::get('/conversations/{id}/read', [ConversationController::class, 'read']);
        Route::post('/conversations/{id}/typing', [ConversationController::class, 'typing']);
        Route::post('/conversations/bulk-status', [ConversationController::class, 'bulkUpdateStatus']);
        Route::post('/conversations/{id}/accept', [ConversationController::class, 'accept']);
        Route::post('/conversations/{id}/resolve', [ConversationController::class, 'resolve']);
        Route::post('/conversations/{id}/tags', [ConversationController::class, 'syncTags']);
        Route::put('/conversations/{id}/messages/{message_id}', [ConversationController::class, 'editMessage']);
        Route::delete('/conversations/{id}/messages/{message_id}', [ConversationController::class, 'deleteMessage']);

        Route::get('/tags', [TagController::class, 'index']);

        Route::get('/quick-messages', [QuickMessageController::class, 'index']);
        Route::post('/quick-messages', [QuickMessageController::class, 'store']);
        Route::put('/quick-messages/{quick_message}', [QuickMessageController::class, 'update']);
        Route::delete('/quick-messages/{quick_message}', [QuickMessageController::class, 'destroy']);

        Route::get('/contacts', [ContactController::class, 'index']);
        Route::post('/contacts', [ContactController::class, 'store']);
        Route::put('/contacts/{id}', [ContactController::class, 'update'])->middleware('permission:contacts.update');

        // Tag routes - protected by permissions
        Route::post('/tags', [TagController::class, 'store'])->middleware('permission:tags.create');
        Route::put('/tags/{id}', [TagController::class, 'update'])->middleware('permission:tags.update');
        Route::delete('/tags/{id}', [TagController::class, 'destroy'])->middleware('permission:tags.delete');

        // WhatsApp message templates (Cloud API). CRUD is proxied to Meta; send
        // supports re-engaging outside the 24h window (existing conv or new number).
        Route::get('/templates', [MessageTemplateController::class, 'index'])->middleware('permission:templates.view');
        Route::post('/templates', [MessageTemplateController::class, 'store'])->middleware('permission:templates.create');
        Route::delete('/templates/{name}', [MessageTemplateController::class, 'destroy'])->middleware('permission:templates.delete');
        Route::post('/templates/send', [MessageTemplateController::class, 'send'])->middleware('permission:templates.send');
    });

    Route::get('/connections', [ConnectionController::class, 'index']);
    Route::get('/connections/metrics', [ConnectionController::class, 'metrics']);

    // Connection routes - protected by permissions
    Route::post('/connections', [ConnectionController::class, 'store'])->middleware('permission:connections.create');
    Route::post('/connections/{id}/connect', [ConnectionController::class, 'connect'])->middleware('permission:connections.connect');
    Route::post('/connections/{id}/migrate', [ConnectionController::class, 'migrate'])->middleware('permission:connections.connect');
    Route::get('/connections/{id}/oauth', [ConnectionController::class, 'oauth'])->middleware('permission:connections.oauth');
    Route::get('/connections/{id}/business-profile', [ConnectionController::class, 'businessProfile']);
    Route::put('/connections/{id}/business-profile', [ConnectionController::class, 'updateBusinessProfile'])->middleware('permission:connections.update');
    Route::put('/connections/{id}', [ConnectionController::class, 'update'])->middleware('permission:connections.update');
    Route::post('/connections/{id}/check-status', [ConnectionController::class, 'checkStatus'])->middleware('permission:connections.check-status');
    Route::post('/connections/{id}/generate-api-key', [ConnectionController::class, 'generateApiKey'])->middleware('permission:connections.generate-api-key');
    Route::post('/connections/{id}/disconnect', [ConnectionController::class, 'disconnect'])->middleware('permission:connections.disconnect');
    Route::delete('/connections/{id}', [ConnectionController::class, 'destroy'])->middleware('permission:connections.delete');
    Route::put('/connections/{id}/automated-messages', [ConnectionController::class, 'updateAutomatedMessages'])->middleware('permission:connections.update-automated-messages');

    // Service hours (business hours that gate AI → human handoff), per connection
    Route::get('/connections/{id}/service-hours', [ConnectionController::class, 'serviceHours'])->middleware('permission:service-hours.view');
    Route::put('/connections/{id}/service-hours', [ConnectionController::class, 'updateServiceHours'])->middleware('permission:service-hours.update');

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

    // Flow routes - protected by permissions + the `flow` plan feature
    Route::middleware('feature:flow')->group(function () {
        Route::get('/flows', [FlowController::class, 'index'])->middleware('permission:flows.view');
        Route::post('/flows', [FlowController::class, 'store'])->middleware('permission:flows.create');
        Route::post('/flows/import', [FlowController::class, 'import'])->middleware('permission:flows.create');
        Route::get('/flows/{id}/export', [FlowController::class, 'export'])->middleware('permission:flows.view');
        Route::get('/flows/{id}', [FlowController::class, 'show'])->middleware('permission:flows.view');
        Route::put('/flows/{id}', [FlowController::class, 'update'])->middleware('permission:flows.update');
        Route::delete('/flows/{id}', [FlowController::class, 'destroy'])->middleware('permission:flows.delete');
        Route::post('/flows/{id}/save', [FlowController::class, 'saveNodesAndEdges'])->middleware('permission:flows.update');
    });

    // Permission list (read-only) - permissions are managed via seeders/migrations only
    Route::get('/permissions', [PermissionController::class, 'index']);

    // Statistics - gated by the `statistics` plan feature
    Route::middleware('feature:statistics')->group(function () {
        Route::get('/statistics/tenant', [StatisticsController::class, 'tenant'])->middleware('permission:statistics.tenant.view');
        Route::get('/statistics/agents', [StatisticsController::class, 'agents'])->middleware('permission:statistics.agents.view');
    });

    // AI Agent Hub routes - protected by permissions + the `ai_agent_hub` feature
    Route::prefix('ai-hub')->middleware('feature:ai_agent_hub')->group(function () {
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
        // Available to any Back Office admin
        Route::get('/auth/me', [AdminAuthController::class, 'me']);
        Route::post('/auth/logout', [AdminAuthController::class, 'logout']);
        Route::get('/stats', [AdminStatsController::class, 'index']);
        Route::get('/statistics', [AdminStatisticsController::class, 'index'])
            ->middleware('permission:bo.statistics.view');
        Route::put('/account', [AdminAccountController::class, 'updateProfile']);
        Route::put('/account/password', [AdminAccountController::class, 'updatePassword']);

        // Impersonation
        Route::post('/impersonate', [ImpersonationController::class, 'start'])
            ->middleware('permission:bo.impersonate');

        // Customers (tenants) — platform-wide, not tenant-scoped
        Route::middleware('permission:bo.customers.view')->group(function () {
            Route::get('/customers', [AdminCustomerController::class, 'index']);
            Route::get('/customers/{tenant}', [AdminCustomerController::class, 'show']);
        });

        // Users (tenant users) — platform-wide
        Route::get('/users', [AdminUserController::class, 'index'])
            ->middleware('permission:bo.users.view');

        // Connections — platform-wide channel health
        Route::get('/connections', [AdminConnectionController::class, 'index'])
            ->middleware('permission:bo.connections.view');

        // Audit log
        Route::get('/audit-logs', [AdminAuditLogController::class, 'index'])
            ->middleware('permission:bo.audit.view');

        // Backend server logs (storage/logs)
        Route::middleware('permission:bo.logs.view')->group(function () {
            Route::get('/logs', [AdminLogViewerController::class, 'index']);
            Route::get('/logs/download', [AdminLogViewerController::class, 'download']);
        });

        // Admins management
        Route::middleware('permission:bo.admins.manage')->group(function () {
            Route::get('/admins', [AdminAdminController::class, 'index']);
            Route::post('/admins', [AdminAdminController::class, 'store']);
            Route::put('/admins/{admin}/role', [AdminAdminController::class, 'updateRole']);
            Route::delete('/admins/{admin}', [AdminAdminController::class, 'destroy']);
        });

        // Platform settings (ProxyHub credentials, etc.) + WhatsApp delivery audit.
        Route::middleware('permission:bo.settings.manage')->group(function () {
            Route::get('/settings', [AdminSettingsController::class, 'show']);
            Route::put('/settings', [AdminSettingsController::class, 'update']);

            // WhatsApp message logs + issued OTPs (monitoring).
            Route::get('/whatsapp-logs', [AdminWhatsappLogController::class, 'index']);
            Route::get('/otps', [AdminOtpController::class, 'index']);
        });

        // Billing — plan catalogue management
        Route::middleware('permission:bo.plans.manage')->group(function () {
            Route::get('/plans', [AdminPlanController::class, 'index']);
            Route::post('/plans', [AdminPlanController::class, 'store']);
            Route::put('/plans/{plan}', [AdminPlanController::class, 'update']);
            Route::delete('/plans/{plan}', [AdminPlanController::class, 'destroy']);
        });

        // Billing — subscriptions + manual (comp) assignment
        Route::middleware('permission:bo.subscriptions.manage')->group(function () {
            Route::get('/subscriptions', [AdminSubscriptionController::class, 'index']);
            Route::get('/customers/{tenant}/subscription', [AdminSubscriptionController::class, 'show']);
            Route::post('/customers/{tenant}/subscription', [AdminSubscriptionController::class, 'assign']);
            Route::delete('/customers/{tenant}/subscription', [AdminSubscriptionController::class, 'cancel']);
        });

        // Billing — invoices. Backs both the Invoices and the Payments page:
        // there is no separate payments table, an invoice is the charge record.
        Route::get('/invoices', [AdminInvoiceController::class, 'index'])
            ->middleware('permission:bo.invoices.view');

        // Billing — money received, aggregated.
        Route::get('/statistics/revenue', [AdminStatisticsController::class, 'revenue'])
            ->middleware('permission:bo.revenue.view');

        // Roles & permissions management
        Route::middleware('permission:bo.roles.manage')->group(function () {
            Route::get('/permissions', [AdminPermissionController::class, 'index']);
            Route::get('/roles', [AdminRoleController::class, 'index']);
            Route::post('/roles', [AdminRoleController::class, 'store']);
            Route::put('/roles/{role}', [AdminRoleController::class, 'update']);
            Route::delete('/roles/{role}', [AdminRoleController::class, 'destroy']);
        });
    });
});

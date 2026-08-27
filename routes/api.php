<?php

use App\Enums\Billing\Feature;
use App\Http\Controllers\Api\AgentController;
use App\Http\Controllers\Api\AiSuggestController;
use App\Http\Controllers\Api\AiHub\AgentController as AiHubAgentController;
use App\Http\Controllers\Api\AiHub\AgentKnowledgeController as AiHubAgentKnowledgeController;
use App\Http\Controllers\Api\AiHub\AgentProfileController as AiHubAgentProfileController;
use App\Http\Controllers\Api\AiHub\AgentSkillController as AiHubAgentSkillController;
use App\Http\Controllers\Api\AiHub\AgentTrainingExampleController as AiHubAgentTrainingExampleController;
use App\Http\Controllers\Api\AiHub\ModelController as AiHubModelController;
use App\Http\Controllers\Api\AiHub\ProviderCredentialController as AiHubProviderCredentialController;
use App\Http\Controllers\Api\AiHub\ProvisionController as AiHubProvisionController;
use App\Http\Controllers\Api\Apiway\ApiwayCatalogController;
use App\Http\Controllers\Api\Apiway\ApiwayInstanceController;
use App\Http\Controllers\Api\Apiway\ApiwaySubscriptionController;
use App\Http\Controllers\Api\Admin\AccountController as AdminAccountController;
use App\Http\Controllers\Api\Admin\AdminController as AdminAdminController;
use App\Http\Controllers\Api\Admin\AuditLogController as AdminAuditLogController;
use App\Http\Controllers\Api\Admin\LogViewerController as AdminLogViewerController;
use App\Http\Controllers\Api\Admin\AdminAiUsageController;
use App\Http\Controllers\Api\Admin\AdminApiwayController;
use App\Http\Controllers\Api\Admin\AdminBroadcastController;
use App\Http\Controllers\Api\Admin\AdminConversationOverviewController;
use App\Http\Controllers\Api\Admin\AdminEntitlementController;
use App\Http\Controllers\Api\Admin\AdminHealthController;
use App\Http\Controllers\Api\Admin\AdminInvoiceController;
use App\Http\Controllers\Api\Admin\AdminLiveController;
use App\Http\Controllers\Api\Admin\AdminReportController;
use App\Http\Controllers\Api\Admin\AdminStorageController;
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
use App\Http\Controllers\Api\BroadcastController;
use App\Http\Controllers\Api\OtpController;
use App\Http\Controllers\Api\PasswordResetController;
use App\Http\Controllers\Api\Billing\BillingController;
use App\Http\Controllers\Api\ConnectionController;
use App\Http\Controllers\Api\ImpersonationController;
use App\Http\Controllers\Api\Instagram\InstagramAccountController;
use App\Http\Controllers\Api\Instagram\InstagramCommentController;
use App\Http\Controllers\Api\Instagram\InstagramPostController;
use App\Http\Controllers\Api\ContactController;
use App\Http\Controllers\Api\ConversationController;
use App\Http\Controllers\Api\ConversationNoteController;
use App\Http\Controllers\Api\FlowController;
use App\Http\Controllers\Api\GroupController;
use App\Http\Controllers\Api\LeadController;
use App\Http\Controllers\Api\LiveController;
use App\Http\Controllers\Api\LeadPipelineController;
use App\Http\Controllers\Api\LeadSettingsController;
use App\Http\Controllers\Api\MessageController;
use App\Http\Controllers\Api\MessageTemplateController;
use App\Http\Controllers\Api\PermissionController;
use App\Http\Controllers\Api\QuickMessageController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\StarredMessageController;
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

// Forgotten-password recovery via WhatsApp OTP. Public by necessity (the user cannot
// log in), so throttled per IP on top of the service's own resend cooldown and
// per-code attempt cap.
Route::prefix('auth/password')->middleware('throttle:10,1')->group(function () {
    Route::post('/forgot', [PasswordResetController::class, 'forgot']);
    Route::post('/verify', [PasswordResetController::class, 'verify']);
    Route::post('/reset', [PasswordResetController::class, 'reset']);
});

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
    // Cosmetic per-user UI state (theme preset + appearance). Separate from the
    // profile update so the frontend can persist a click without resending
    // name/email/password.
    Route::put('/user/preferences', [UserController::class, 'updatePreferences']);
    // Who is allowed to interrupt this user, and from which connections. Stored
    // on the account so the choice follows them to another browser; applied by
    // the dashboard, which is the only place that knows who is looking at what.
    Route::put('/user/notification-preferences', [UserController::class, 'updateNotificationPreferences']);
    // Presence ping from the open dashboard: once a minute, per tab. The limit
    // is well above that because agents keep several tabs open and the throttle
    // counts per user — it is here to bound abuse, not to pace the SPA.
    Route::post('/user/heartbeat', [UserController::class, 'heartbeat'])->middleware('throttle:30,1');

    // Billing (tenant-side). Exempt from the subscription.active gate so a
    // suspended tenant can still load the page and pay (see EnsureSubscriptionActive).
    Route::prefix('billing')->name('billing.')->group(function () {
        Route::get('/config', [BillingController::class, 'config'])->name('config');
        Route::get('/subscription', [BillingController::class, 'subscription'])->middleware('permission:billing.view')->name('subscription');
        Route::get('/invoices', [BillingController::class, 'invoices'])->middleware('permission:billing.view')->name('invoices');
        Route::get('/invoices/{invoice}/status', [BillingController::class, 'invoiceStatus'])->middleware('permission:billing.view')->name('invoice-status');
        Route::post('/subscribe', [BillingController::class, 'subscribe'])->middleware('permission:billing.manage')->name('subscribe');
        Route::post('/pix/refresh', [BillingController::class, 'refreshPix'])->middleware('permission:billing.manage')->name('pix-refresh');
        Route::post('/cancel', [BillingController::class, 'cancel'])->middleware('permission:billing.manage')->name('cancel');
        // Abandon an unpaid checkout (frees the tenant to pick another plan).
        Route::post('/pending/cancel', [BillingController::class, 'cancelPending'])->middleware('permission:billing.manage')->name('pending-cancel');
        Route::post('/invoices/{invoice}/cancel', [BillingController::class, 'cancelInvoice'])->middleware('permission:billing.manage')->name('invoice-cancel');
    });
    Route::get('/plans', [BillingController::class, 'plans'])->middleware('permission:billing.view')->name('plans.index');

    // API Way instances (purchased assets via ProxyBR partner API). The
    // `apiway.` name prefix is exempt from the subscription.active gate — a
    // tenant with no plan can own and manage unit-purchased instances.
    Route::prefix('apiway')->name('apiway.')->group(function () {
        Route::get('/catalog', [ApiwayCatalogController::class, 'catalog'])->name('catalog');
        Route::post('/quote', [ApiwayCatalogController::class, 'quote'])->name('quote');
        Route::get('/instances', [ApiwayInstanceController::class, 'index'])->name('instances.index');
        Route::post('/instances', [ApiwayInstanceController::class, 'store'])->middleware('permission:billing.manage')->name('instances.store');
        Route::post('/instances/{instance}/token/reveal', [ApiwayInstanceController::class, 'revealToken'])->middleware('permission:connections.connect')->name('instances.token');
        Route::post('/subscriptions/{subscription}/renew-invoice', [ApiwaySubscriptionController::class, 'renewInvoice'])->middleware('permission:billing.manage')->name('subscriptions.renew-invoice');
        Route::post('/subscriptions/{subscription}/abandon', [ApiwaySubscriptionController::class, 'abandon'])->middleware('permission:billing.manage')->name('subscriptions.abandon');
        Route::post('/subscriptions/{subscription}/cancel', [ApiwaySubscriptionController::class, 'cancel'])->middleware('permission:billing.manage')->name('subscriptions.cancel');
    });

    Route::middleware('feature:' . Feature::Chat->value)->group(function () {
        Route::get('/messages', [MessageController::class, 'index']);

        Route::get('/conversations', [ConversationController::class, 'index']);
        Route::post('/conversations', [ConversationController::class, 'store']);
        Route::post('/conversations/compose-email', [ConversationController::class, 'composeEmail']);
        Route::get('/conversations/{id}', [ConversationController::class, 'show']);
        Route::get('/conversations/{id}/variables', [ConversationController::class, 'variables']);
        Route::get('/conversations/{id}/participants', [ConversationController::class, 'participants']);
        // Route::get('/conversations/{id}/messages', [ConversationController::class, 'messages']);
        // Every outbound path is gated on the channel's session window: on
        // WhatsApp Official / TikTok a late message is refused here instead of
        // being stored and silently dropped by the platform.
        Route::middleware('messaging.window')->group(function () {
            Route::post('/conversations/{id}/send-message', [ConversationController::class, 'sendMessage']);
            Route::post('/conversations/{id}/send-image', [ConversationController::class, 'sendImage']);
            Route::post('/conversations/{id}/send-audio', [ConversationController::class, 'sendAudio']);
            Route::post('/conversations/{id}/send-video', [ConversationController::class, 'sendVideo']);
            Route::post('/conversations/{id}/send-document', [ConversationController::class, 'sendDocument']);
            Route::post('/conversations/{id}/send-interactive', [ConversationController::class, 'sendInteractive']);
        });
        Route::get('/conversations/{id}/read', [ConversationController::class, 'read']);
        // Called on a timer by every open composer, and each call is an
        // outbound request to the channel — throttled so a stuck client cannot
        // turn one agent's keyboard into a rate-limit problem on the account.
        // The ceiling is well above the fastest channel's pace (Telegram, every
        // 4s ≈ 15/min) across a handful of threads at once.
        Route::post('/conversations/{id}/typing', [ConversationController::class, 'typing'])
            ->middleware('throttle:90,1');
        Route::post('/conversations/bulk-status', [ConversationController::class, 'bulkUpdateStatus']);
        Route::post('/conversations/{id}/accept', [ConversationController::class, 'accept']);
        Route::post('/conversations/{id}/resolve', [ConversationController::class, 'resolve']);
        // Mute silences notifications for a thread; any tenant member may
        // toggle it, including on an unassigned group nobody owns yet.
        Route::post('/conversations/{id}/mute', [ConversationController::class, 'mute']);
        Route::delete('/conversations/{id}/mute', [ConversationController::class, 'unmute']);

        // Removing a group drops its inbound messages at ingest; the group's
        // contact (name, photo) carries on being maintained either way.
        Route::get('/groups/removed', [GroupController::class, 'removed']);
        Route::post('/groups/{id}/remove', [GroupController::class, 'remove']);
        Route::delete('/groups/{id}/remove', [GroupController::class, 'restore']);
        Route::get('/conversations/{id}/transfer-targets', [ConversationController::class, 'transferTargets']);
        Route::post('/conversations/{id}/transfer', [ConversationController::class, 'transfer']);
        // Transfer's mirror image: claim an active thread that belongs to
        // someone else. Gated on connection access only — the caller is not the
        // assignee, which is the whole point of the action.
        Route::post('/conversations/{id}/take-over', [ConversationController::class, 'takeOver']);
        // Suggestions run on the tenant's AI Hub agents, so the plan must
        // include the hub feature.
        Route::post('/conversations/{id}/ai-suggest', [AiSuggestController::class, 'suggest'])->middleware(['feature:ai_agent_hub', 'throttle:15,1']);
        Route::post('/conversations/{id}/tags', [ConversationController::class, 'syncTags']);
        Route::put('/conversations/{id}/messages/{message_id}', [ConversationController::class, 'editMessage']);
        Route::delete('/conversations/{id}/messages/{message_id}', [ConversationController::class, 'deleteMessage']);
        Route::get('/conversations/{id}/messages/{message_id}/email-html', [ConversationController::class, 'emailHtml']);

        // Internal notes about a thread. Never leave the dashboard; any member
        // holding the connection may write one, only the author (or an owner)
        // may change it.
        Route::get('/conversations/{id}/notes', [ConversationNoteController::class, 'index']);
        Route::post('/conversations/{id}/notes', [ConversationNoteController::class, 'store']);
        Route::put('/conversations/{id}/notes/{note_id}', [ConversationNoteController::class, 'update']);
        Route::delete('/conversations/{id}/notes/{note_id}', [ConversationNoteController::class, 'destroy']);

        // Starring bookmarks a message for the whole workspace. Like mute, any
        // member may toggle it — including on a thread nobody has accepted.
        Route::get('/starred-messages', [StarredMessageController::class, 'index']);
        Route::post('/conversations/{id}/messages/{message_id}/star', [StarredMessageController::class, 'store']);
        Route::delete('/conversations/{id}/messages/{message_id}/star', [StarredMessageController::class, 'destroy']);

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
        // Media headers (incl. carousel cards) are created from an uploaded
        // sample, not a URL — this returns the handle `store` then references.
        Route::post('/templates/media', [MessageTemplateController::class, 'media'])->middleware('permission:templates.create');
        Route::delete('/templates/{name}', [MessageTemplateController::class, 'destroy'])->middleware('permission:templates.delete');
        Route::post('/templates/send', [MessageTemplateController::class, 'send'])->middleware('permission:templates.send');

        // Broadcast campaigns. Sending is a separate permission from creating:
        // drafting a blast and actually firing it at thousands of customers are
        // very different levels of trust.
        Route::get('/broadcasts', [BroadcastController::class, 'index'])->middleware('permission:broadcasts.view');
        Route::post('/broadcasts', [BroadcastController::class, 'store'])->middleware('permission:broadcasts.create');
        Route::get('/broadcasts/{id}', [BroadcastController::class, 'show'])->middleware('permission:broadcasts.view');
        Route::get('/broadcasts/{id}/recipients', [BroadcastController::class, 'recipients'])->middleware('permission:broadcasts.view');
        Route::put('/broadcasts/{id}', [BroadcastController::class, 'update'])->middleware('permission:broadcasts.create');
        Route::delete('/broadcasts/{id}', [BroadcastController::class, 'destroy'])->middleware('permission:broadcasts.delete');
        Route::post('/broadcasts/{id}/start', [BroadcastController::class, 'start'])->middleware('permission:broadcasts.send');
        Route::post('/broadcasts/{id}/pause', [BroadcastController::class, 'pause'])->middleware('permission:broadcasts.send');
        Route::post('/broadcasts/{id}/resume', [BroadcastController::class, 'resume'])->middleware('permission:broadcasts.send');
        Route::post('/broadcasts/{id}/cancel', [BroadcastController::class, 'cancel'])->middleware('permission:broadcasts.send');
        Route::post('/broadcasts/{id}/retry-failed', [BroadcastController::class, 'retryFailed'])->middleware('permission:broadcasts.send');

        // Sales funnel — gated by the `crm` plan feature. A workspace can run a
        // busy support inbox and never sell anything from it, so this is its own
        // feature rather than part of `chat`.
        Route::middleware('feature:crm')->group(function () {
            // The board is deliberately its own endpoint rather than index()
            // with a large page size: six columns that fetch every lead to
            // render are fine at forty cards and unusable at five thousand.
            Route::get('/leads/board', [LeadController::class, 'board'])->middleware('permission:leads.view');
            // Before /leads/{id}, or "owners" is read as an id.
            Route::get('/leads/owners', [LeadController::class, 'owners'])->middleware('permission:leads.view');
            Route::get('/leads', [LeadController::class, 'index'])->middleware('permission:leads.view');
            Route::post('/leads', [LeadController::class, 'store'])->middleware('permission:leads.create');
            Route::get('/leads/{id}', [LeadController::class, 'show'])->middleware('permission:leads.view');
            Route::patch('/leads/{id}', [LeadController::class, 'update'])->middleware('permission:leads.update');
            // The drag. Its own verb because it writes the audit row the
            // conversion report is built from — see LeadController::move().
            Route::patch('/leads/{id}/stage', [LeadController::class, 'move'])->middleware('permission:leads.update');
            Route::delete('/leads/{id}', [LeadController::class, 'destroy'])->middleware('permission:leads.delete');

            Route::get('/contacts/{contact}/leads', [LeadController::class, 'forContact'])->middleware('permission:leads.view');

            // Changing the shape of the funnel is a different level of trust
            // from working a card.
            // Auto-close window, and whether it runs at all. Every workspace
            // sells on a different clock, so none of it is hard-coded.
            Route::get('/lead-settings', [LeadSettingsController::class, 'show'])->middleware('permission:leads.view');
            Route::put('/lead-settings', [LeadSettingsController::class, 'update'])->middleware('permission:lead-pipelines.manage');

            Route::get('/lead-pipelines', [LeadPipelineController::class, 'index'])->middleware('permission:leads.view');
            Route::post('/lead-pipelines/{pipeline}/stages', [LeadPipelineController::class, 'storeStage'])->middleware('permission:lead-pipelines.manage');
            Route::patch('/lead-pipelines/{pipeline}/stages/{stage}', [LeadPipelineController::class, 'updateStage'])->middleware('permission:lead-pipelines.manage');
            Route::delete('/lead-pipelines/{pipeline}/stages/{stage}', [LeadPipelineController::class, 'destroyStage'])->middleware('permission:lead-pipelines.manage');
        });

        // Instagram publishing. Two id spaces meet here and are kept apart by
        // the path: `/instagram/posts/{post}` is one of our rows (a draft or a
        // schedule), `/instagram/accounts/{connection}/media/{mediaId}` is a
        // post that already lives on Instagram. Everything about a live post
        // needs the connection, because the account's token is what authorises
        // the call.
        Route::prefix('instagram')->group(function () {
            Route::get('/accounts', [InstagramAccountController::class, 'index'])->middleware('permission:instagram-posts.view');
            Route::get('/accounts/{connection}', [InstagramAccountController::class, 'show'])->middleware('permission:instagram-posts.view');

            Route::get('/accounts/{connection}/posts', [InstagramPostController::class, 'index'])->middleware('permission:instagram-posts.view');
            Route::post('/accounts/{connection}/posts', [InstagramPostController::class, 'store'])->middleware('permission:instagram-posts.create');
            // Media has to exist at a public URL before a post can reference it
            // — Meta fetches images, it does not accept them.
            Route::post('/accounts/{connection}/uploads', [InstagramPostController::class, 'upload'])->middleware('permission:instagram-posts.create');

            Route::get('/posts/{post}', [InstagramPostController::class, 'show'])->middleware('permission:instagram-posts.view');
            Route::put('/posts/{post}', [InstagramPostController::class, 'update'])->middleware('permission:instagram-posts.create');
            Route::delete('/posts/{post}', [InstagramPostController::class, 'destroy'])->middleware('permission:instagram-posts.delete');
            Route::post('/posts/{post}/publish', [InstagramPostController::class, 'publish'])->middleware('permission:instagram-posts.publish');

            // Comment moderation on a live post. Read is bundled with viewing
            // posts; every write needs the moderation permission.
            Route::get('/accounts/{connection}/media/{media}/comments', [InstagramCommentController::class, 'index'])->middleware('permission:instagram-posts.view');
            Route::middleware('permission:instagram-comments.manage')->group(function () {
                Route::post('/accounts/{connection}/comments/{comment}/replies', [InstagramCommentController::class, 'reply']);
                Route::patch('/accounts/{connection}/comments/{comment}', [InstagramCommentController::class, 'update']);
                Route::delete('/accounts/{connection}/comments/{comment}', [InstagramCommentController::class, 'destroy']);
                Route::patch('/accounts/{connection}/media/{media}/comments', [InstagramCommentController::class, 'setCommentsEnabled']);
            });
        });
    });

    Route::get('/connections', [ConnectionController::class, 'index']);
    Route::get('/connections/metrics', [ConnectionController::class, 'metrics']);
    Route::get('/connections/meta-config', [ConnectionController::class, 'metaConfig']);
    // Health + recent activity for the detail drawer. No extra permission: it
    // reports on messages the caller can already read, and the controller still
    // gates on connection access.
    Route::get('/connections/{id}/activity', [ConnectionController::class, 'activity']);

    // Connection routes - protected by permissions
    Route::post('/connections', [ConnectionController::class, 'store'])->middleware('permission:connections.create');
    Route::post('/connections/{id}/duplicate', [ConnectionController::class, 'duplicate'])->middleware('permission:connections.create');
    Route::post('/connections/{id}/connect', [ConnectionController::class, 'connect'])->middleware('permission:connections.connect');
    Route::get('/connections/{id}/oauth', [ConnectionController::class, 'oauth'])->middleware('permission:connections.oauth');
    Route::get('/connections/{id}/business-profile', [ConnectionController::class, 'businessProfile']);
    Route::put('/connections/{id}/business-profile', [ConnectionController::class, 'updateBusinessProfile'])->middleware('permission:connections.update');
    // Bringing a number in from another BSP: claim → send code → verify.
    // Gated on connections.connect, same as any other act of attaching a
    // number to a connection.
    Route::post('/connections/{id}/migration/phone-number', [ConnectionController::class, 'migrateNumber'])->middleware('permission:connections.connect');
    Route::post('/connections/{id}/migration/request-code', [ConnectionController::class, 'migrationRequestCode'])->middleware('permission:connections.connect');
    Route::post('/connections/{id}/migration/verify-code', [ConnectionController::class, 'migrationVerifyCode'])->middleware('permission:connections.connect');
    // Email only — edits the stored mailbox credentials in place (see updateCredentials).
    Route::put('/connections/{id}/credentials', [ConnectionController::class, 'updateCredentials'])->middleware('permission:connections.connect');
    Route::put('/connections/{id}', [ConnectionController::class, 'update'])->middleware('permission:connections.update');
    Route::post('/connections/{id}/check-status', [ConnectionController::class, 'checkStatus'])->middleware('permission:connections.check-status');
    // Reuses the check-status permission on purpose: both are "poke this
    // connection", and a new permission would not be granted to existing roles.
    Route::post('/connections/{id}/sync', [ConnectionController::class, 'syncInbox'])->middleware('permission:connections.check-status');
    Route::post('/connections/{id}/generate-api-key', [ConnectionController::class, 'generateApiKey'])->middleware('permission:connections.generate-api-key');
    Route::post('/connections/{id}/disconnect', [ConnectionController::class, 'disconnect'])->middleware('permission:connections.disconnect');
    Route::delete('/connections/{id}', [ConnectionController::class, 'destroy'])->middleware('permission:connections.delete');
    Route::put('/connections/{id}/automated-messages', [ConnectionController::class, 'updateAutomatedMessages'])->middleware('permission:connections.update-automated-messages');
    Route::put('/connections/{id}/ai-suggest', [ConnectionController::class, 'updateAiSuggest'])->middleware(['feature:ai_agent_hub', 'permission:connections.update']);

    // "Respond with AI" — tenant-managed AI agents (openai/gemini/anthropic keys).
    // Listing is also allowed for connections.update so the link picker works.

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

    // Statistics - gated by the `statistics` plan feature. One route per
    // section of the page; all of them take the same filter set.
    Route::middleware('feature:statistics')->group(function () {
        Route::middleware('permission:statistics.tenant.view')->group(function () {
            Route::get('/statistics/filters', [StatisticsController::class, 'filters']);
            Route::get('/statistics/overview', [StatisticsController::class, 'overview']);
            Route::get('/statistics/volume', [StatisticsController::class, 'volume']);
            Route::get('/statistics/service', [StatisticsController::class, 'service']);
            Route::get('/statistics/topics', [StatisticsController::class, 'topics']);
            Route::get('/statistics/automation', [StatisticsController::class, 'automation']);
            Route::get('/statistics/health', [StatisticsController::class, 'health']);
        });

        Route::get('/statistics/agents', [StatisticsController::class, 'agents'])->middleware('permission:statistics.agents.view');

        // The live monitor. Same feature and same viewing permission as the
        // rest of Statistics, but no filter set and no period: it only ever
        // describes the last few minutes. Polled every couple of seconds by an
        // open wallboard, so it is throttled well above that — a stuck client
        // must not be able to hammer the database in a tight loop.
        Route::get('/statistics/live', [LiveController::class, 'index'])
            ->middleware(['permission:statistics.tenant.view', 'throttle:120,1']);
    });

    // AI Agent Hub routes - protected by permissions + the `ai_agent_hub` feature
    Route::prefix('ai-hub')->middleware('feature:ai_agent_hub')->group(function () {
        Route::post('/provision', [AiHubProvisionController::class, 'store'])->middleware('permission:ai-agents.create');
        Route::get('/models', [AiHubModelController::class, 'index'])->middleware('permission:ai-agents.view');

        Route::get('/provider-credentials', [AiHubProviderCredentialController::class, 'index'])->middleware('permission:ai-agents.view');
        Route::post('/provider-credentials', [AiHubProviderCredentialController::class, 'store'])->middleware('permission:ai-agents.create');
        Route::patch('/provider-credentials/{id}', [AiHubProviderCredentialController::class, 'update'])->middleware('permission:ai-agents.update');
        Route::delete('/provider-credentials/{id}', [AiHubProviderCredentialController::class, 'destroy'])->middleware('permission:ai-agents.delete');

        // connections.update may also list: the Connections page needs the
        // agent roster for the "Respond with AI" link dropdown.
        Route::get('/agents', [AiHubAgentController::class, 'index'])->middleware('permission:ai-agents.view|connections.update');
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

        // Platform settings (API Way credentials, etc.) + WhatsApp delivery audit.
        Route::middleware('permission:bo.settings.manage')->group(function () {
            Route::get('/settings', [AdminSettingsController::class, 'show']);
            Route::put('/settings', [AdminSettingsController::class, 'update']);

            // ProxyBR partner catalog — doubles as the "test connection" probe.
            Route::get('/apiway/catalog', [AdminApiwayController::class, 'catalog']);

            // WhatsApp message logs + issued OTPs (monitoring).
            Route::get('/whatsapp-logs', [AdminWhatsappLogController::class, 'index']);
            Route::get('/otps', [AdminOtpController::class, 'index']);
        });

        // AI spend. The platform's largest variable cost, and until this
        // endpoint the only one with no reader — ai_hub_runs has recorded
        // tokens and provider cost since the AI Hub shipped.
        Route::get('/ai-usage', [AdminAiUsageController::class, 'index'])
            ->middleware('permission:bo.ai-usage.view');

        // Campaigns platform-wide, plus the ability to stop one. A bad blast
        // spends the platform's WhatsApp reputation, not just the tenant's.
        Route::middleware('permission:bo.broadcasts.manage')->group(function () {
            Route::get('/broadcasts', [AdminBroadcastController::class, 'index']);
            Route::post('/broadcasts/{broadcast}/pause', [AdminBroadcastController::class, 'pause']);
            Route::post('/broadcasts/{broadcast}/cancel', [AdminBroadcastController::class, 'cancel']);
        });

        // Are the daemons, workers and scheduler alive? Nothing else answers
        // this: they never serve a request, so their death is silent.
        Route::get('/health', [AdminHealthController::class, 'index'])
            ->middleware('permission:bo.health.view');

        // Disk per customer + whether retention is keeping up.
        Route::get('/storage', [AdminStorageController::class, 'index'])
            ->middleware('permission:bo.storage.view');

        // Volume and backlog per customer. Deliberately not a message reader —
        // impersonation is the audited path for actually looking at a thread.
        Route::get('/conversations-overview', [AdminConversationOverviewController::class, 'index'])
            ->middleware('permission:bo.conversations.view');

        // The same question with the period removed: what is moving right now,
        // and who is staffing it. Keeps the metadata-only rule above — see
        // AdminLiveController. Polled, so throttled above the poll rate.
        Route::get('/live', [AdminLiveController::class, 'index'])
            ->middleware(['permission:bo.live.view', 'throttle:120,1']);

        // CSV exports for the tables people were copying by hand.
        Route::middleware('permission:bo.reports.export')->group(function () {
            Route::get('/reports', [AdminReportController::class, 'index']);
            Route::get('/reports/{report}', [AdminReportController::class, 'download']);
        });

        // Billing — plan catalogue management
        Route::middleware('permission:bo.plans.manage')->group(function () {
            Route::get('/plans', [AdminPlanController::class, 'index']);
            // The feature/quota vocabulary, so the plan editor stops keeping
            // its own copy of it — the copy went stale and silently dropped
            // `crm` from every plan it saved.
            Route::get('/plans/meta', [AdminPlanController::class, 'meta']);
            Route::post('/plans', [AdminPlanController::class, 'store']);
            Route::put('/plans/{plan}', [AdminPlanController::class, 'update']);
            Route::delete('/plans/{plan}', [AdminPlanController::class, 'destroy']);
        });

        // Per-tenant exceptions to the plan: a feature for a month, a raised
        // cap during a migration. Never grants platform access — that is what
        // a comped subscription is for.
        Route::middleware('permission:bo.entitlements.manage')->group(function () {
            Route::get('/customers/{tenant}/entitlements', [AdminEntitlementController::class, 'show']);
            Route::put('/customers/{tenant}/entitlements', [AdminEntitlementController::class, 'update']);
            Route::delete('/customers/{tenant}/entitlements', [AdminEntitlementController::class, 'destroy']);
        });

        // Billing — subscriptions + manual (comp) assignment
        Route::middleware('permission:bo.subscriptions.manage')->group(function () {
            Route::get('/subscriptions', [AdminSubscriptionController::class, 'index']);
            Route::get('/apiway/subscriptions', [AdminApiwayController::class, 'subscriptions']);
            // Books a manual MercadoPago refund against a purchase that was
            // captured but never provisioned — clears it off the Health page.
            Route::post('/apiway/subscriptions/{subscription}/settle-refund', [AdminApiwayController::class, 'settleRefund']);
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

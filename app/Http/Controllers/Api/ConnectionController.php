<?php

namespace App\Http\Controllers\Api;

use App\Enums\Billing\Feature;
use App\Enums\Connection\Channel;
use App\Enums\Connection\Status as ConnectionStatus;
use App\Enums\Connection\SyncStatus;
use App\Events\ConnectionUpdated;
use App\Exceptions\ConnectionException;
use App\Http\Controllers\Controller;
use App\Http\Resources\ConnectionResource;
use App\Jobs\SyncEmailInbox;
use App\Models\AiHubAgent;
use App\Models\Connection;
use App\Models\Conversation;
use App\Services\Billing\SubscriptionGate;
use App\Services\BusinessHours;
use App\Services\Connection\Channels\EmailChannel;
use App\Services\Connection\ConnectionActivity;
use App\Services\Connection\ConnectionService;
use App\Services\Connection\Meta\FacebookConfig;
use App\Services\Connection\Meta\InstagramConfig;
use App\Services\Connection\TikTok\TikTokAuthClient;
use App\Services\Connection\WhatsApp\WhatsappBusinessProfileService;
use App\Services\Email\EmailInboxSynchronizer;
use App\Services\Flow\InteractiveNodes;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ConnectionController extends Controller
{
    public function __construct(
        protected ConnectionService $connectionService,
    ) {
        //
    }

    public function index()
    {
        $user = request()->user();

        // "Last activity" for the list column. Read off conversations rather
        // than messages: `last_message_at` is already maintained by the
        // Message::created hook, and conversations.connection_id is indexed —
        // so this stays a keyed lookup instead of a MAX() over every message
        // the tenant ever exchanged.
        $lastActivity = Conversation::query()
            ->selectRaw('MAX(last_message_at)')
            ->whereColumn('conversations.connection_id', 'connections.id');

        // Owner gets all connections, agents get only assigned connections
        if ($user->hasRole('owner')) {
            $connections = $user->tenant->connections()
                ->select('connections.*')
                ->addSelect(['last_activity_at' => $lastActivity])
                ->orderBy('connections.created_at', 'DESC')
                ->get();
        } else {
            // Agent: only get connections they have access to
            $connections = $user->connections()
                ->where('tenant_id', $user->tenant_id)
                ->select('connections.*')
                ->addSelect(['last_activity_at' => $lastActivity])
                ->orderBy('connections.created_at', 'DESC')
                ->get();
        }

        return response()->json([
            'data' => $connections->toResourceCollection(ConnectionResource::class),
        ]);
    }

    public function metrics(Request $request)
    {
        $tenant = $request->user()->tenant;

        $instanceIds = $tenant->connections()
            ->where('channel', Channel::WhatsappApiway->value)
            ->get()
            ->map(function (Connection $connection) {
                return $connection->credentials['instance_id'] ?? null;
            })
            ->filter()
            ->unique()
            ->values()
            ->all();

        $metrics = app(\App\Services\Connection\Proxy\ApiwayMetricsService::class)
            ->todayForInstances($instanceIds);

        return response()->json([
            'data' => [
                'sentToday' => $metrics['sentToday'],
                'receivedToday' => $metrics['receivedToday'],
            ],
        ]);
    }

    /**
     * Health and recent activity for one connection — the detail drawer's
     * lower half. Read entirely out of `messages`; see ConnectionActivity for
     * what that does and does not make knowable.
     */
    public function activity(int $id, Request $request)
    {
        $connection = $this->accessibleConnection($id);

        $validated = $request->validate([
            'days' => ['nullable', 'integer', 'min:1', 'max:'.ConnectionActivity::MAX_DAYS],
            // Minutes east of UTC, as the browser reports it, so "per day"
            // buckets on the viewer's calendar rather than on UTC's.
            'timezone_offset' => ['nullable', 'integer', 'min:-840', 'max:840'],
        ]);

        $activity = new ConnectionActivity(
            $connection,
            (int) ($validated['days'] ?? ConnectionActivity::DEFAULT_DAYS),
            (int) ($validated['timezone_offset'] ?? 0) * 60,
        );

        return response()->json(['data' => $activity->build()]);
    }

    /**
     * Copy a connection's *settings* into a new, unconnected one.
     *
     * Credentials are deliberately not copied: they identify one WhatsApp
     * number, one Page, one mailbox. Two connections sharing them would both
     * receive the same webhooks and each overwrite the other's threads. The
     * copy therefore lands Inactive and goes through Connect like any new one —
     * what is saved is the part that takes time to redo (flow, colour,
     * automated messages, service hours).
     */
    public function duplicate(int $id, Request $request)
    {
        $connection = $this->accessibleConnection($id);
        $tenant = $request->user()->tenant;

        if (! app(SubscriptionGate::class)->canConsume($tenant, 'max_connections', $tenant->connections()->count())) {
            return response()->json([
                'message' => 'You have reached the maximum number of connections for your plan.',
                'code' => 'quota_exceeded',
                'quota' => 'max_connections',
            ], 422);
        }

        $copy = $tenant->connections()->create([
            'channel' => $connection->channel,
            'name' => $this->copyName($connection, $tenant),
            'color' => $connection->color,
            'flow_id' => $connection->flow_id,
            'status' => ConnectionStatus::Inactive,
            'accept_message' => $connection->accept_message,
            'closing_message' => $connection->closing_message,
            'return_to_last_agent' => $connection->return_to_last_agent,
            'return_to_last_agent_minutes' => $connection->return_to_last_agent_minutes,
            'service_hours' => $connection->service_hours,
            'ai_suggest_agent_id' => $connection->ai_suggest_agent_id,
        ]);

        return response()->json([
            'message' => 'Connection duplicated successfully',
            'data' => $copy->toResource(ConnectionResource::class),
        ], 201);
    }

    /**
     * "Name (2)", "Name (3)" … — the suffix counts up past whatever copies
     * already exist so duplicating twice does not produce two identical rows,
     * which is the one thing that makes a connection list unusable.
     */
    private function copyName(Connection $connection, $tenant): string
    {
        $base = preg_replace('/\s\(\d+\)$/', '', $connection->name);
        $taken = $tenant->connections()->pluck('name')->all();

        for ($n = 2; $n < 100; $n++) {
            $candidate = mb_substr($base, 0, 90)." ({$n})";

            if (! in_array($candidate, $taken, true)) {
                return $candidate;
            }
        }

        return mb_substr($base, 0, 90).' ('.uniqid().')';
    }

    /**
     * A connection of this tenant that the caller may actually see. Agents hold
     * a `connection_user` row per connection; owners hold none and reach all.
     */
    private function accessibleConnection(int $id): Connection
    {
        $connection = request()->user()->tenant->connections()->findOrFail($id);

        abort_unless(request()->user()->canAccessConnection($connection->id), 403);

        return $connection;
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'channel' => ['required', Rule::enum(Channel::class)],
            'name' => ['required', 'string', 'max:100'],
            'color' => ['nullable', 'hex_color', 'max:7'],
            'flow_id' => ['nullable', 'exists:flows,id'],
        ]);

        $tenant = $request->user()->tenant;
        $gate = app(SubscriptionGate::class);

        // API Way connections are backed by purchased instances — the real
        // gate is owning an available instance at connect time. The feature
        // flag also turns on implicitly for tenants with live instances.
        if ($validated['channel'] === Channel::WhatsappApiway->value
            && ! $gate->feature($tenant, Feature::WhatsappApi->value)) {
            return response()->json([
                'message' => 'This feature (whatsapp_api) is not included in your current plan.',
                'code' => 'feature_not_in_plan',
                'feature' => Feature::WhatsappApi->value,
            ], 403);
        }

        if (! $gate->canConsume($tenant, 'max_connections', $tenant->connections()->count())) {
            return response()->json([
                'message' => 'You have reached the maximum number of connections for your plan.',
                'code' => 'quota_exceeded',
                'quota' => 'max_connections',
            ], 422);
        }

        $this->assertFlowRunsOnChannel(Channel::from($validated['channel']), $validated['flow_id'] ?? null);

        $connection = $tenant->connections()->create($validated);

        return response()->json([
            'message' => 'Connection created successfully',
            'data' => $connection->toResource(ConnectionResource::class),
        ], 201);
    }

    /**
     * Fetch the WhatsApp Cloud API business profile for this connection.
     */
    public function businessProfile(int $id, WhatsappBusinessProfileService $service)
    {
        $connection = $this->whatsappOfficialConnection($id);

        return response()->json([
            'data' => $service->get($connection),
        ]);
    }

    /**
     * Update the WhatsApp business profile's text fields.
     */
    public function updateBusinessProfile(int $id, Request $request, WhatsappBusinessProfileService $service)
    {
        $connection = $this->whatsappOfficialConnection($id);

        $validated = $request->validate([
            'about' => ['nullable', 'string', 'max:139'],
            'description' => ['nullable', 'string', 'max:512'],
            'address' => ['nullable', 'string', 'max:256'],
            'email' => ['nullable', 'email', 'max:128'],
            'vertical' => ['nullable', 'string', 'max:64'],
            'websites' => ['nullable', 'array', 'max:2'],
            'websites.*' => ['string', 'url', 'max:256'],
        ]);

        return response()->json([
            'data' => $service->update($connection, $validated),
        ]);
    }

    /**
     * A flow containing reply-button / list nodes can only drive WhatsApp
     * Official connections — the Cloud API is the only channel that renders
     * them. Mirrors the check FlowController makes when the flow is saved.
     */
    private function assertFlowRunsOnChannel(Channel $channel, int|string|null $flowId): void
    {
        if ($flowId === null || $channel === InteractiveNodes::CHANNEL) {
            return;
        }

        if (!InteractiveNodes::flowUsesInteractive($flowId)) {
            return;
        }

        throw ValidationException::withMessages([
            'flow_id' => [
                'This flow uses WhatsApp buttons or a list menu, so it can only be assigned to a WhatsApp Official connection.',
            ],
        ]);
    }

    /**
     * Resolve a tenant-scoped connection and assert it is WhatsApp Official.
     */
    private function whatsappOfficialConnection(int $id): Connection
    {
        $connection = request()->user()->tenant->connections()->findOrFail($id);

        if ($connection->channel !== Channel::WhatsappOfficial) {
            abort(422, 'Business profile is only available for WhatsApp Official connections');
        }

        return $connection;
    }

    public function update(int $id, Request $request)
    {
        $connection = request()->user()->tenant->connections()->findOrFail($id);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'color' => ['nullable', 'hex_color', 'max:7'],
            'flow_id' => ['nullable', 'exists:flows,id'],
            // Both `sometimes`: a client that predates the setting must not
            // switch it off by omitting it.
            'return_to_last_agent' => ['sometimes', 'boolean'],
            // A day is the ceiling on purpose — past that it stops being "the
            // same visit" and becomes a routing rule nobody remembers writing.
            'return_to_last_agent_minutes' => ['sometimes', 'integer', 'min:1', 'max:1440'],
        ]);

        $this->assertFlowRunsOnChannel($connection->channel, $validated['flow_id'] ?? null);

        $connection->update($validated);

        broadcast(new ConnectionUpdated($connection));

        return response()->json([
            'message' => 'Connection updated successfully',
            'data' => $connection->toResource(ConnectionResource::class),
        ], 200);
    }

    /**
     * Update the stored credentials of an email connection (host, port, security,
     * address or password) without having to disconnect and reconnect the mailbox —
     * a disconnect wipes `credentials`, which would strand the inbox mid-sync.
     *
     * Email only: every other channel gets its credentials from an OAuth callback or
     * from the provider, so there is nothing here for a user to hand-edit.
     */
    public function updateCredentials(int $id, Request $request, EmailChannel $channel)
    {
        $connection = $request->user()->tenant->connections()->findOrFail($id);

        if ($connection->channel !== Channel::Email) {
            return response()->json([
                'message' => 'Credentials can only be edited on email connections.',
            ], 422);
        }

        // Password is optional on update: blank keeps the one already on file.
        $request->validate([
            ...EmailChannel::rules(),
            'password' => ['nullable', 'string'],
        ]);

        try {
            $channel->updateCredentials($connection, $request->all());

            return response()->json([
                'message' => 'Credentials updated successfully',
                'data' => $connection->fresh()->toResource(ConnectionResource::class),
            ]);
        } catch (ValidationException $th) {
            throw $th;
        } catch (ConnectionException $th) {
            $status = $th->getHttpStatusCode();
            // A mailbox rejecting the login must not read as "your session expired":
            // the SPA logs the user out on any 401 (same guard as connect()).
            $status = in_array($status, [401, 419], true) ? 502 : $status;

            return response()->json(['message' => $th->getMessage()], $status);
        } finally {
            broadcast(new ConnectionUpdated($connection->fresh()));
        }
    }

    public function connect(int $id, Request $request)
    {
        $connection = request()->user()->tenant->connections()->findOrFail($id);

        if ($connection->channel === Channel::Email) {
            $request->validate(EmailChannel::rules());
        }

        try {
            $this->connectionService->connect($connection, $request->all());

            return response()->json([
                'message' => 'Running connection successfully',
                'data' => $connection->toResource(ConnectionResource::class),
            ], 200);
        } catch (ValidationException $th) {
            throw $th;
        } catch (ConnectionException $th) {
            $status = $th->getHttpStatusCode();
            $message = $th->getMessage();
            if (in_array($status, [401, 419], true)) {
                // Never forward an upstream/provider auth failure as 401 — the SPA logs
                // the user out on any 401. Surface it as a gateway error instead.
                $status = 502;
                $message = 'Nao foi possivel conectar a instancia junto ao provedor. Verifique a configuracao da integracao.';
            }

            return response()->json(['message' => $message], $status);
        } catch (\Throwable $th) {
            return response()->json([
                'message' => 'Failed to run connection',
            ], 500);
        } finally {
            broadcast(new ConnectionUpdated($connection));
        }
    }

    public function checkStatus(int $id)
    {
        $connection = request()->user()->tenant->connections()->findOrFail($id);

        try {
            $this->connectionService->checkStatus($connection);

            return response()->json([
                'message' => 'Status checked successfully',
                'data' => $connection->toResource(ConnectionResource::class),
            ], 200);
        } catch (ConnectionException $th) {
            $status = $th->getHttpStatusCode();
            $message = $th->getMessage();
            if (in_array($status, [401, 419], true)) {
                // Never forward an upstream/provider auth failure as 401 — the SPA logs
                // the user out on any 401. Surface it as a gateway error instead.
                $status = 502;
                $message = 'Nao foi possivel conectar a instancia junto ao provedor. Verifique a configuracao da integracao.';
            }

            return response()->json(['message' => $message], $status);
        } catch (\Throwable $th) {
            return response()->json([
                'message' => 'Failed to check connection',
            ], 500);
        } finally {
            broadcast(new ConnectionUpdated($connection));
        }
    }

    /**
     * Queue an inbound-mail pull for an email connection.
     *
     * Marks the connection 'syncing' and broadcasts before returning so the
     * SPA reflects the state immediately, rather than looking idle until a
     * worker happens to pick the job up.
     */
    public function syncInbox(int $id, EmailInboxSynchronizer $synchronizer)
    {
        $connection = request()->user()->tenant->connections()->findOrFail($id);

        if ($connection->channel !== Channel::Email) {
            return response()->json([
                'message' => 'Apenas conexoes de e-mail podem ser sincronizadas.',
            ], 422);
        }

        if ($connection->status !== ConnectionStatus::Active) {
            return response()->json([
                'message' => 'Conecte a caixa de e-mail antes de sincronizar.',
            ], 422);
        }

        // Already running: report success so a double-click is harmless.
        if ($synchronizer->isSyncing($connection)) {
            return response()->json([
                'message' => 'Sincronizacao ja em andamento.',
                'data' => $connection->toResource(ConnectionResource::class),
            ], 200);
        }

        $connection->forceFill([
            'sync_status' => SyncStatus::Syncing,
            'sync_error' => null,
            'sync_started_at' => now(),
        ])->save();

        broadcast(new ConnectionUpdated($connection));

        SyncEmailInbox::dispatch($connection->id);

        return response()->json([
            'message' => 'Sincronizacao iniciada.',
            'data' => $connection->toResource(ConnectionResource::class),
        ], 202);
    }

    public function generateApiKey(int $id)
    {
        $connection = Connection::where('tenant_id', request()->user()->tenant_id)->findOrFail($id);
        $this->connectionService->generateApiKey($connection);

        return response()->json([
            'message' => 'API Key generated successfully',
            'data' => $connection->toResource(ConnectionResource::class),
        ], 200);
    }

    public function disconnect(int $id)
    {
        $connection = request()->user()->tenant->connections()->findOrFail($id);

        try {
            $this->connectionService->disconnect($connection);

            return response()->json([
                'message' => 'Connection disconnected successfully',
                'data' => $connection->toResource(ConnectionResource::class),
            ], 200);
        } catch (ConnectionException $th) {
            return response()->json([
                'message' => $th->getMessage(),
            ], $th->getHttpStatusCode());
        } catch (\Throwable $th) {
            return response()->json([
                'message' => 'Failed to disconnect connection',
            ], 500);
        } finally {
            broadcast(new ConnectionUpdated($connection));
        }
    }

    public function destroy(int $id)
    {
        $connection = request()->user()->tenant->connections()->findOrFail($id);

        try {
            $this->connectionService->delete($connection);

            return response()->json([
                'message' => 'Connection deleted successfully',
            ], 200);
        } catch (ConnectionException $th) {
            return response()->json([
                'message' => $th->getMessage(),
            ], $th->getHttpStatusCode());
        } catch (\Throwable $th) {
            return response()->json([
                'message' => 'Failed to delete connection',
            ], 500);
        }
    }

    public function oauth(int $id, Request $request)
    {
        $connection = request()->user()->tenant->connections()->findOrFail($id);

        // Instagram, Messenger and TikTok use OAuth URL generation (WhatsApp uses embedded signup in frontend)
        $oauthUrl = match ($connection->channel) {
            Channel::Instagram => $this->instagramOauth($connection),
            Channel::Messenger => $this->messengerOauth($connection),
            Channel::TikTok => $this->tiktokOauth($connection),
            default => null,
        };

        if ($oauthUrl === null) {
            return response()->json([
                'message' => 'This connection does not support OAuth URL generation',
            ], 400);
        }

        Log::info('Generated OAuth URL', [
            'connection_id' => $connection->id,
            'channel' => $connection->channel->value,
            'oauth_url' => $oauthUrl,
        ]);

        return response()->json([
            'message' => 'OAuth URL generated successfully',
            'data' => [
                'oauth_url' => $oauthUrl,
            ],
        ], 200);
    }

    public function updateAutomatedMessages(int $id, Request $request)
    {
        $connection = request()->user()->tenant->connections()->findOrFail($id);

        $validated = $request->validate([
            'accept_message' => ['nullable', 'string', 'max:1000'],
            'closing_message' => ['nullable', 'string', 'max:1000'],
        ]);

        $connection->update($validated);

        broadcast(new ConnectionUpdated($connection));

        return response()->json([
            'message' => 'Automated messages updated successfully',
            'data' => $connection->toResource(ConnectionResource::class),
        ], 200);
    }

    /**
     * Link/unlink a "Respond with AI" agent to this connection. A null
     * agent_id turns the feature off; the agents themselves are managed in
     * AiSuggestController.
     */
    public function updateAiSuggest(int $id, Request $request)
    {
        $connection = request()->user()->tenant->connections()->findOrFail($id);

        $validated = $request->validate([
            'agent_id' => ['nullable', 'integer'],
        ]);

        $agentId = $validated['agent_id'] ?? null;

        if ($agentId !== null) {
            // Must be one of this tenant's AI Hub agents (the same agents the
            // flow AIAgent nodes use).
            AiHubAgent::where('id', $agentId)
                ->whereHas('aiHubTenant', fn ($q) => $q->where('tenant_id', request()->user()->tenant_id))
                ->firstOrFail();
        }

        $connection->update(['ai_suggest_agent_id' => $agentId]);
        $connection->load('aiSuggestAgent');

        broadcast(new ConnectionUpdated($connection));

        return response()->json([
            'message' => 'AI suggestions updated successfully',
            'data' => $connection->toResource(ConnectionResource::class),
        ], 200);
    }

    /**
     * Email is a shared inbox: no flow ever runs on it and there is no AI → human
     * handoff, so a schedule would have nothing to gate.
     */
    private function assertSupportsServiceHours(Connection $connection): void
    {
        if ($connection->channel === Channel::Email) {
            abort(422, 'Service hours do not apply to email connections.');
        }
    }

    /**
     * Return the connection's service hours (falling back to a sensible default
     * when nothing has been configured yet) plus the live open/closed state.
     */
    public function serviceHours(int $id)
    {
        $connection = request()->user()->tenant->connections()->findOrFail($id);
        $this->assertSupportsServiceHours($connection);

        $config = $connection->service_hours ?: BusinessHours::defaultConfig();

        return response()->json([
            'data' => array_merge($config, [
                'is_open_now' => BusinessHours::isOpen($connection),
            ]),
        ], 200);
    }

    /**
     * Update the connection's service hours.
     */
    public function updateServiceHours(int $id, Request $request)
    {
        $connection = request()->user()->tenant->connections()->findOrFail($id);
        $this->assertSupportsServiceHours($connection);

        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            'timezone' => ['required', 'string', Rule::in(timezone_identifiers_list())],
            'away_message' => ['nullable', 'string', 'max:1000'],
            'days' => ['required', 'array'],
            'days.*' => ['array'],
            'days.*.*.open' => ['required', 'date_format:H:i'],
            'days.*.*.close' => ['required', 'date_format:H:i'],
        ]);

        // Keep only the recognised day keys, in canonical order.
        $days = [];
        foreach (BusinessHours::DAYS as $day) {
            $days[$day] = array_values($validated['days'][$day] ?? []);
        }

        $connection->update([
            'service_hours' => [
                'enabled' => (bool) $validated['enabled'],
                'timezone' => $validated['timezone'],
                'days' => $days,
                'away_message' => $validated['away_message'] ?? '',
            ],
        ]);

        broadcast(new ConnectionUpdated($connection));

        return $this->serviceHours($id);
    }

    private function instagramOauth(Connection $connection): string
    {
        // Create state parameter with connection_id
        $state = base64_encode(json_encode([
            'connection_id' => $connection->id,
            'timestamp' => time(),
        ]));

        $clientId = InstagramConfig::clientId();
        $redirectUri = InstagramConfig::redirectUri(); // Tidak di-encode
        // Scopes are baked into the token at authorization time, so an account
        // connected before publishing existed holds a token without those two
        // permissions and cannot be upgraded in place — it has to come back
        // through here. That is why the post grid surfaces a "reconnect" prompt
        // on Meta's permission errors rather than just reporting them.
        $scope = urlencode(implode(',', [
            'instagram_business_basic',
            'instagram_business_manage_messages',
            'instagram_business_content_publish',
            'instagram_business_manage_comments',
        ])); // Di-encode

        Log::info('Generating Instagram OAuth URL', [
            'connection_id' => $connection->id,
            'redirect_uri_in_oauth' => $redirectUri,
        ]);

        // Build URL manual sesuai contoh Meta
        $oauthUrl = 'https://www.instagram.com/oauth/authorize'
            .'?force_reauth=true'
            ."&client_id={$clientId}"
            ."&redirect_uri={$redirectUri}"
            .'&response_type=code'
            ."&scope={$scope}"
            ."&state={$state}";

        return $oauthUrl;
    }

    private function tiktokOauth(Connection $connection): string
    {
        // Create state parameter with connection_id (same shape as Instagram's)
        $state = base64_encode(json_encode([
            'connection_id' => $connection->id,
            'timestamp' => time(),
        ]));

        return TikTokAuthClient::authorizeUrl($state);
    }

    private function messengerOauth(Connection $connection): string
    {
        // channel marks the state so the shared /oauth/facebook/callback route
        // can tell this popup flow apart from WhatsApp Embedded Signup.
        $state = base64_encode(json_encode([
            'connection_id' => $connection->id,
            'channel' => Channel::Messenger->value,
            'timestamp' => time(),
        ]));

        $appId = FacebookConfig::appId();
        $redirectUri = urlencode(FacebookConfig::redirectUri());

        // auth_type=rerequest: once a user has completed this app's login,
        // Facebook silently skips the "choose the Pages" screen on later
        // logins — anyone who opted in zero Pages would be stuck with an
        // empty /me/accounts forever. rerequest re-shows that screen so the
        // user can add the missing Page(s).
        $url = 'https://www.facebook.com/v25.0/dialog/oauth'
            . "?client_id={$appId}"
            . "&redirect_uri={$redirectUri}"
            . '&response_type=code'
            . '&auth_type=rerequest'
            . '&state=' . urlencode($state);

        // Business-type apps use Facebook Login for Business: the dialog takes
        // a login configuration (config_id) and IGNORES the classic scope
        // param — without it the token carries no page permissions at all.
        // The configuration must grant pages_show_list + pages_messaging +
        // pages_manage_metadata over the Pages asset (user access token).
        if ($messengerConfigId = FacebookConfig::messengerConfigId()) {
            return $url . '&config_id=' . urlencode($messengerConfigId);
        }

        // Consumer-type apps: classic scope-based request.
        // pages_show_list → /me/accounts, pages_messaging → Send API,
        // pages_manage_metadata → /{page}/subscribed_apps.
        return $url . '&scope=' . urlencode('pages_show_list,pages_messaging,pages_manage_metadata');
    }
}

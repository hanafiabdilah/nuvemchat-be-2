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
use App\Models\Connection;
use App\Services\Billing\SubscriptionGate;
use App\Services\BusinessHours;
use App\Services\Connection\Channels\EmailChannel;
use App\Services\Connection\ConnectionService;
use App\Services\Connection\Meta\InstagramConfig;
use App\Services\Connection\WhatsApp\WhatsappBusinessProfileService;
use App\Services\Email\EmailInboxSynchronizer;
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

        // Owner gets all connections, agents get only assigned connections
        if ($user->hasRole('owner')) {
            $connections = $user->tenant->connections()->orderBy('created_at', 'DESC')->get();
        } else {
            // Agent: only get connections they have access to
            $connections = $user->connections()
                ->where('tenant_id', $user->tenant_id)
                ->orderBy('created_at', 'DESC')
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

        if ($validated['channel'] === Channel::WhatsappApiway->value) {
            if (! $gate->feature($tenant, Feature::WhatsappApi->value)) {
                return response()->json([
                    'message' => 'This feature (whatsapp_api) is not included in your current plan.',
                    'code' => 'feature_not_in_plan',
                    'feature' => Feature::WhatsappApi->value,
                ], 403);
            }

            $instancesCount = $tenant->connections()
                ->where('channel', Channel::WhatsappApiway->value)
                ->count();

            if (! $gate->canConsume($tenant, 'max_instances', $instancesCount)) {
                return response()->json([
                    'message' => 'You have reached the maximum number of connections for your plan.',
                    'code' => 'quota_exceeded',
                    'quota' => 'max_instances',
                ], 422);
            }
        }

        if (! $gate->canConsume($tenant, 'max_connections', $tenant->connections()->count())) {
            return response()->json([
                'message' => 'You have reached the maximum number of connections for your plan.',
                'code' => 'quota_exceeded',
                'quota' => 'max_connections',
            ], 422);
        }

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
        ]);

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

        // Dedicated/custom proxy is a paid add-on gated by the `proxy` feature.
        if ($connection->channel === Channel::WhatsappApiway->value) {
            $proxyMode = $request->input('proxy_mode', $connection->credentials['proxy_mode'] ?? 'shared');

            if (in_array($proxyMode, ['dedicated', 'custom'], true)
                && ! app(SubscriptionGate::class)->feature($request->user()->tenant, Feature::Proxy->value)) {
                return response()->json([
                    'message' => 'This feature (proxy) is not included in your current plan.',
                    'code' => 'feature_not_in_plan',
                    'feature' => Feature::Proxy->value,
                ], 403);
            }
        }

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

    public function migrate(int $id, Request $request)
    {
        $connection = request()->user()->tenant->connections()->findOrFail($id);

        try {
            $this->connectionService->migrate($connection, $request->all());

            return response()->json([
                'message' => 'Connection migrated successfully',
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
            Log::error('Failed to migrate connection', [
                'connection_id' => $connection->id,
                'error' => $th->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to migrate connection',
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

        // Only Instagram uses OAuth URL generation (WhatsApp uses embedded signup in frontend)
        if ($connection->channel !== Channel::Instagram) {
            return response()->json([
                'message' => 'This connection does not support OAuth URL generation',
            ], 400);
        }

        $oauthUrl = $this->instagramOauth($connection);

        Log::info('Generated Instagram OAuth URL', [
            'connection_id' => $connection->id,
            'oauth_url' => $oauthUrl,
        ]);

        return response()->json([
            'message' => 'Instagram OAuth URL generated successfully',
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
        $scope = urlencode('instagram_business_basic,instagram_business_manage_messages'); // Di-encode

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
}

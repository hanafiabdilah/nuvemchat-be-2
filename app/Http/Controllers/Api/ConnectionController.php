<?php

namespace App\Http\Controllers\Api;

use App\Enums\Connection\Channel;
use App\Events\ConnectionUpdated;
use App\Exceptions\ConnectionException;
use App\Http\Controllers\Controller;
use App\Http\Resources\ConnectionResource;
use App\Models\Connection;
use App\Services\Connection\ConnectionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ConnectionController extends Controller
{
    public function __construct(
        protected ConnectionService $connectionService,
    ){
        //
    }

    public function index()
    {
        $user = request()->user();

        // Owner gets all connections, agents get only assigned connections
        if ($user->role === 'owner') {
            $connections = $user->tenant->connections()->get();
        } else {
            // Agent: only get connections they have access to
            $connections = $user->connections()
                ->where('tenant_id', $user->tenant_id)
                ->get();
        }

        return response()->json([
            'data' => $connections->toResourceCollection(ConnectionResource::class),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'channel' => ['required', Rule::enum(Channel::class)],
            'name' => ['required', 'string', 'max:100'],
            'color' => ['nullable', 'hex_color', 'max:7'],
        ]);

        $connection = $request->user()->tenant->connections()->create($validated);

        return response()->json([
            'message' => 'Connection created successfully',
            'data' => $connection->toResource(ConnectionResource::class),
        ], 201);
    }

    public function update(int $id, Request $request)
    {
        $connection = request()->user()->tenant->connections()->findOrFail($id);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'color' => ['nullable', 'hex_color', 'max:7'],
        ]);

        $connection->update($validated);

        broadcast(new ConnectionUpdated($connection));

        return response()->json([
            'message' => 'Connection updated successfully',
            'data' => $connection->toResource(ConnectionResource::class),
        ], 200);
    }

    public function connect(int $id, Request $request)
    {
        $connection = request()->user()->tenant->connections()->findOrFail($id);

        try {
            $this->connectionService->connect($connection, $request->all());

            return response()->json([
                'message' => 'Running connection successfully',
                'data' => $connection->toResource(ConnectionResource::class),
            ], 200);
        } catch(ValidationException $th) {
            throw $th;
        } catch(ConnectionException $th){
            return response()->json([
                'message' => $th->getMessage(),
            ], $th->getHttpStatusCode());
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
        } catch(ConnectionException $th){
            return response()->json([
                'message' => $th->getMessage(),
            ], $th->getHttpStatusCode());
        } catch (\Throwable $th) {
            return response()->json([
                'message' => 'Failed to check connection',
            ], 500);
        } finally {
            broadcast(new ConnectionUpdated($connection));
        }
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
        } catch(ConnectionException $th){
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
        } catch(ConnectionException $th){
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

        // Validate that this is an oauth connection
        if (!in_array($connection->channel, [Channel::Instagram])) {
            return response()->json([
                'message' => 'This connection does not support OAuth',
            ], 400);
        }

        $oauthUrl = match($connection->channel) {
            Channel::Instagram => $this->instagramOauth($connection),
            default => null,
        };

        Log::info('Generated OAuth URL for connection', [
            'connection_id' => $connection->id,
            'channel' => $connection->channel->value,
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
            'welcoming_message' => ['nullable', 'string', 'max:1000'],
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

    private function instagramOauth(Connection $connection): string
    {
        // Create state parameter with connection_id
        $state = base64_encode(json_encode([
            'connection_id' => $connection->id,
            'timestamp' => time(),
        ]));

        $clientId = config('services.instagram.client_id');
        $redirectUri = config('services.instagram.redirect_uri'); // Tidak di-encode
        $scope = urlencode('instagram_business_basic,instagram_business_manage_messages,instagram_business_manage_comments,instagram_business_content_publish,instagram_business_manage_insights'); // Di-encode

        Log::info('Generating Instagram OAuth URL', [
            'connection_id' => $connection->id,
            'redirect_uri_in_oauth' => $redirectUri,
        ]);

        // Build URL manual sesuai contoh Meta
        $oauthUrl = "https://www.instagram.com/oauth/authorize"
            . "?force_reauth=true"
            . "&client_id={$clientId}"
            . "&redirect_uri={$redirectUri}"
            . "&response_type=code"
            . "&scope={$scope}"
            . "&state={$state}";

        return $oauthUrl;
    }
}

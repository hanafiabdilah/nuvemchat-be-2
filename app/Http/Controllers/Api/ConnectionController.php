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
}

<?php

namespace App\Http\Controllers\Api;

use App\Enums\Connection\Channel;
use App\Exceptions\ConnectionException;
use App\Http\Controllers\Controller;
use App\Http\Resources\ConnectionResource;
use App\Models\Connection;
use App\Services\Connection\ConnectionService;
use Illuminate\Http\Request;
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
        $connections = request()->user()->tenant->connections()->get();

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

    public function generateApiKey(int $id)
    {
        $connection = request()->user()->tenant->connections()->findOrFail($id);
        $this->connectionService->generateApiKey($connection);

        return response()->json([
            'message' => 'API Key generated successfully',
            'data' => $connection->toResource(ConnectionResource::class),
        ], 200);
    }
}

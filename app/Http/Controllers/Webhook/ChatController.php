<?php

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\Controller;
use App\Models\Connection;
use App\Services\Webhook\ChatService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ChatController extends Controller
{
    public function __construct(
        protected ChatService $chatService,
    ){
        //
    }

    public function handle(Request $request, $id)
    {
        $connection = Connection::find($id);

        if(!$connection) {
            return response()->json([
                'message' => 'Connection not found',
            ], 404);
        };

        $this->chatService->handle($connection, $request->all());

        return response()->json([
            'message' => 'Webhook received successfully',
        ], 200);
    }
}

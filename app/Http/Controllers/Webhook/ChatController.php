<?php

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\Controller;
use App\Models\Connection;
use App\Services\Webhook\ChatService;
use App\Services\Webhook\ChatWebhookSecret;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ChatController extends Controller
{
    public function __construct(
        protected ChatService $chatService,
    ){
        //
    }

    /**
     * Inbound messages for Telegram and WhatsApp API Way.
     *
     * `$token` is the optional last path segment: the API Way core can only be
     * given a URL, so that is where its half of the credential rides. Telegram
     * sends the same secret as a header instead and reaches here with $token
     * null. Which carrier was used is ChatWebhookSecret's problem, not this
     * method's — see that class for why an unsecured connection is still
     * served for now.
     */
    public function handle(Request $request, $id, ?string $token = null)
    {
        $connection = Connection::find($id);

        if(!$connection) {
            return response()->json([
                'message' => 'Connection not found',
            ], 404);
        };

        if (! ChatWebhookSecret::authorizes($request, $connection, $token)) {
            return response()->json([
                'message' => 'Invalid webhook credentials',
            ], 401);
        }

        $this->chatService->handle($connection, $request->all());

        return response()->json([
            'message' => 'Webhook received successfully',
        ], 200);
    }
}

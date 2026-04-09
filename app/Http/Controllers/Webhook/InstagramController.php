<?php

namespace App\Http\Controllers\Webhook;

use App\Enums\Connection\Channel;
use App\Http\Controllers\Controller;
use App\Models\Connection;
use App\Services\Webhook\ChatService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class InstagramController extends Controller
{
    public function __construct(
        protected ChatService $chatService,
    ){
        //
    }

    public function verify(Request $request)
    {
        $challenge = $request->query('hub_challenge');
        $verifyToken = $request->query('hub_verify_token');
        $mode = $request->query('hub_mode');

        if($verifyToken !== config('services.instagram.webhook_verify_token')) {
            return response('Invalid verification token', 403);
        }

        return response($challenge, 200);
    }

    public function handle(Request $request)
    {
        Log::info('Instagram webhook received', json_encode($request->all()));

        $object = $request->input('object');

        if($object !== 'instagram') {
            return response()->json([
                'message' => 'Invalid webhook object',
            ], 400);
        }

        foreach($request->input('entry', []) as $entry) {
            $userId = $entry['id'] ?? null;

            if(!$userId) {
                Log::error('Missing user ID in Instagram webhook entry', [
                    'entry' => $entry,
                ]);
                continue;
            }

            $connection = Connection::where('channel', Channel::Instagram)
                ->where('credentials->user_id', (string) $userId)
                ->first();

            if(!$connection) {
                Log::error('Connection not found for Instagram webhook', [
                    'user_id' => $userId,
                ]);
                continue;
            };

            $this->chatService->handle($connection, $entry);
        }

        return response()->json([
            'message' => 'Webhook received successfully',
        ], 200);
    }
}

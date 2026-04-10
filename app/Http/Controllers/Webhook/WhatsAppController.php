<?php

namespace App\Http\Controllers\Webhook;

use App\Enums\Connection\Channel;
use App\Http\Controllers\Controller;
use App\Models\Connection;
use App\Services\Webhook\ChatService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WhatsAppController extends Controller
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

        if($verifyToken !== config('services.facebook.webhook_verify_token')) {
            return response('Invalid verification token', 403);
        }

        return response($challenge, 200);
    }

    public function handle(Request $request)
    {
        Log::info('WhatsApp webhook received', $request->all());

        $object = $request->input('object');

        if($object !== 'whatsapp_business_account') {
            return response()->json([
                'message' => 'Invalid webhook object',
            ], 400);
        }

        foreach($request->input('entry', []) as $entry) {
            $businessAccountId = $entry['id'] ?? null;

            if(!$businessAccountId) {
                Log::error('Missing business account ID in WhatsApp webhook entry', [
                    'entry' => $entry,
                ]);
                continue;
            }

            $connection = Connection::where('channel', Channel::WhatsappOfficial)
                ->where('credentials->business_account_id', (string) $businessAccountId)
                ->first();

            if(!$connection) {
                Log::error('Connection not found for WhatsApp webhook', [
                    'business_account_id' => $businessAccountId,
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

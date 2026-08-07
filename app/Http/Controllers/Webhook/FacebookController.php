<?php

namespace App\Http\Controllers\Webhook;

use App\Enums\Connection\Channel;
use App\Http\Controllers\Controller;
use App\Models\Connection;
use App\Services\Connection\Meta\FacebookConfig;
use App\Services\Webhook\ChatService;
use App\Services\Webhook\MetaSignatureVerifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Receives Messenger (Facebook Page) webhooks. WhatsApp Cloud API events use
 * their own /webhook/whatsapp endpoint even though both live under the same
 * Meta app — this route only ever sees object=page payloads.
 */
class FacebookController extends Controller
{
    public function __construct(
        protected ChatService $chatService,
    ) {
        //
    }

    public function verify(Request $request)
    {
        $challenge = $request->query('hub_challenge');
        $verifyToken = $request->query('hub_verify_token');

        if ($verifyToken !== FacebookConfig::webhookVerifyToken()) {
            return response('Invalid verification token', 403);
        }

        return response($challenge, 200);
    }

    public function handle(Request $request)
    {
        if (!MetaSignatureVerifier::verify($request, FacebookConfig::appSecret(), 'facebook')) {
            return response()->json(['message' => 'Invalid signature'], 401);
        }

        Log::info('Facebook webhook received', $request->all());

        $object = $request->input('object');

        if ($object !== 'page') {
            return response()->json([
                'message' => 'Invalid webhook object',
            ], 400);
        }

        foreach ($request->input('entry', []) as $entry) {
            $pageId = $entry['id'] ?? null;

            if (!$pageId) {
                Log::error('Missing page ID in Facebook webhook entry', [
                    'entry' => $entry,
                ]);
                continue;
            }

            $connection = Connection::where('channel', Channel::Messenger)
                ->where('credentials->page_id', (string) $pageId)
                ->first();

            if (!$connection) {
                Log::error('Connection not found for Facebook webhook', [
                    'page_id' => $pageId,
                ]);
                continue;
            }

            $this->chatService->handle($connection, $entry);
        }

        return response()->json([
            'message' => 'Webhook received successfully',
        ], 200);
    }
}

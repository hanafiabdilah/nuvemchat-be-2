<?php

namespace App\Http\Controllers\Webhook;

use App\Enums\Connection\Channel;
use App\Events\TemplateStatusUpdated;
use App\Http\Controllers\Controller;
use App\Models\Connection;
use App\Services\Connection\Meta\FacebookConfig;
use App\Services\Webhook\ChatService;
use App\Services\Webhook\Handlers\Chat\WhatsappCallHandler;
use App\Services\Webhook\Handlers\Chat\WhatsappCoexistenceHandler;
use App\Services\Webhook\MetaSignatureVerifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WhatsAppController extends Controller
{
    public function __construct(
        protected ChatService $chatService,
        protected WhatsappCoexistenceHandler $coexistenceHandler,
        protected WhatsappCallHandler $callHandler,
    ){
        //
    }

    public function verify(Request $request)
    {
        $challenge = $request->query('hub_challenge');
        $verifyToken = $request->query('hub_verify_token');
        $mode = $request->query('hub_mode');

        if($verifyToken !== FacebookConfig::webhookVerifyToken()) {
            return response('Invalid verification token', 403);
        }

        return response($challenge, 200);
    }

    public function handle(Request $request)
    {
        if (!MetaSignatureVerifier::verify($request, FacebookConfig::appSecret(), 'whatsapp')) {
            return response()->json(['message' => 'Invalid signature'], 401);
        }

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

            // The WABA webhook multiplexes several fields. Route by field:
            // templates, calls, and Coexistence fields (message echoes, history
            // sync, contact sync, account_update) each have their own handler;
            // only the remaining changes (live messages/statuses) go to
            // ChatService.
            $changes = $entry['changes'] ?? [];
            $hasTemplateStatus = collect($changes)
                ->contains(fn ($change) => ($change['field'] ?? null) === 'message_template_status_update');

            if ($hasTemplateStatus) {
                $this->handleTemplateStatusUpdate($connection, $changes);
                continue;
            }

            $chatChanges = [];

            foreach ($changes as $change) {
                $field = $change['field'] ?? null;

                if (in_array($field, WhatsappCallHandler::FIELDS, true)) {
                    $this->callHandler->handleChange($connection, $change);
                } elseif (in_array($field, WhatsappCoexistenceHandler::FIELDS, true)) {
                    $this->coexistenceHandler->handleChange($connection, $change);
                } else {
                    $chatChanges[] = $change;
                }
            }

            if (!empty($chatChanges)) {
                $entry['changes'] = $chatChanges;
                $this->chatService->handle($connection, $entry);
            }
        }

        return response()->json([
            'message' => 'Webhook received successfully',
        ], 200);
    }

    /**
     * Log each template status change and broadcast it so a dashboard viewing
     * the Templates page can refresh / notify. Templates are proxied live from
     * Meta, so there's nothing to persist here.
     */
    private function handleTemplateStatusUpdate(Connection $connection, array $changes): void
    {
        foreach ($changes as $change) {
            if (($change['field'] ?? null) !== 'message_template_status_update') {
                continue;
            }

            $value = $change['value'] ?? [];

            Log::info('WhatsApp template status update', [
                'connection_id' => $connection->id,
                'template_name' => $value['message_template_name'] ?? null,
                'event' => $value['event'] ?? null,
            ]);

            broadcast(new TemplateStatusUpdated($connection->tenant_id, $value));
        }
    }
}

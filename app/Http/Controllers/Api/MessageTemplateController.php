<?php

namespace App\Http\Controllers\Api;

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status as ConnectionStatus;
use App\Enums\Conversation\Status as ConversationStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\MessageResource;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Events\ConversationUpdated;
use App\Events\MessageReceived;
use App\Services\Connection\WhatsApp\WhatsappTemplateService;
use App\Services\Message\MessageService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class MessageTemplateController extends Controller
{
    public function __construct(
        private WhatsappTemplateService $templates,
    ) {}

    /**
     * List the WABA's message templates (proxied live from Meta).
     */
    public function index(Request $request)
    {
        $connection = $this->resolveConnection($request);

        return response()->json([
            'data' => $this->templates->list($connection),
        ]);
    }

    /**
     * Create a template on the WABA. Meta returns it in a PENDING review state.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'connection_id' => 'nullable|integer',
            'name' => 'required|string|max:512',
            'category' => 'required|string', // MARKETING | UTILITY | AUTHENTICATION
            'language' => 'required|string',
            'components' => 'required|array|min:1',
        ]);

        $connection = $this->resolveConnection($request);

        return response()->json([
            'data' => $this->templates->create($connection, $data),
        ], 201);
    }

    /**
     * Delete a template by name (all language variants of that name).
     */
    public function destroy(Request $request, string $name)
    {
        $connection = $this->resolveConnection($request);

        $this->templates->delete($connection, $name);

        return response()->json(['message' => 'Template deleted']);
    }

    /**
     * Send a template message, either into an existing conversation or to a
     * brand-new phone number (find-or-create contact + conversation). This is
     * how an agent re-engages a customer outside the 24-hour window.
     */
    public function send(Request $request)
    {
        $data = $request->validate([
            'connection_id' => 'nullable|integer',
            'conversation_id' => 'nullable|integer',
            'phone_number' => 'required_without:conversation_id|string',
            'contact_name' => 'nullable|string|max:255',
            'template_name' => 'required|string',
            'language' => 'required|string',
            'components' => 'nullable|array',
        ]);

        $user = $request->user();

        if (!empty($data['conversation_id'])) {
            $conversation = Conversation::whereHas('connection', function ($q) use ($user) {
                $q->where('tenant_id', $user->tenant_id)
                    ->where('channel', Channel::WhatsappOfficial);
            })->findOrFail($data['conversation_id']);
        } else {
            $connection = $this->resolveConnection($request);
            $phone = preg_replace('/\D+/', '', $data['phone_number']);

            if ($phone === '') {
                throw ValidationException::withMessages([
                    'phone_number' => 'A valid phone number is required.',
                ]);
            }

            $contact = Contact::createFromExternalData($connection, $phone, $data['contact_name'] ?? $phone);

            $conversation = Conversation::where('connection_id', $connection->id)
                ->where('contact_id', $contact->id)
                ->whereIn('status', [
                    ConversationStatus::Active,
                    ConversationStatus::Pending,
                    ConversationStatus::AiHandling,
                ])
                ->first();

            if (!$conversation) {
                $conversation = Conversation::create([
                    'contact_id' => $contact->id,
                    'connection_id' => $connection->id,
                    'external_id' => $phone,
                    'status' => ConversationStatus::Active,
                    'user_id' => $user->id,
                ]);
            }
        }

        $message = (new MessageService())->sendTemplate($conversation, $data);
        $message?->update(['sent_by_user_id' => $user->id]);

        // A template re-opens the conversation; make it active + assigned so the
        // agent can keep chatting within the freshly opened session window.
        $conversation->update([
            'status' => ConversationStatus::Active,
            'user_id' => $conversation->user_id ?? $user->id,
        ]);

        broadcast(new ConversationUpdated($conversation));
        broadcast(new MessageReceived($message));

        return response()->json([
            'data' => new MessageResource($message),
        ]);
    }

    /**
     * Resolve the WhatsApp Official connection for the current tenant. Honors an
     * explicit connection_id, otherwise picks the tenant's first active one.
     */
    private function resolveConnection(Request $request): Connection
    {
        $user = $request->user();

        $query = Connection::where('tenant_id', $user->tenant_id)
            ->where('channel', Channel::WhatsappOfficial);

        if ($request->filled('connection_id')) {
            $query->where('id', $request->input('connection_id'));
        } else {
            $query->where('status', ConnectionStatus::Active);
        }

        $connection = $query->first();

        if (!$connection) {
            abort(404, 'No WhatsApp Official connection found');
        }

        return $connection;
    }
}

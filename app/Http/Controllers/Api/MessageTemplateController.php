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
use App\Services\Conversation\OutboundConversationResolver;
use App\Services\Message\MessageService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class MessageTemplateController extends Controller
{
    public function __construct(
        private WhatsappTemplateService $templates,
        private OutboundConversationResolver $conversations,
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
     * Create a template on one or more WhatsApp Business Accounts. Meta returns
     * each in a PENDING review state.
     *
     * A template belongs to a WABA, not to a phone number, so every connection
     * under the same WABA already shares it — see resolveTargetConnections().
     * One WABA failing (a duplicate name, a rejected component) must not lose
     * the others, so each is reported separately instead of aborting the call.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'connection_id' => 'nullable|integer',
            'connection_ids' => 'nullable|array',
            'connection_ids.*' => 'integer',
            'name' => 'required|string|max:512',
            'category' => 'required|string', // MARKETING | UTILITY | AUTHENTICATION
            'language' => 'required|string',
            'components' => 'required|array|min:1',
            // Lets Meta re-file the template if it disagrees with the category,
            // instead of rejecting it outright.
            'allow_category_change' => 'nullable|boolean',
            // Whether variables are numbered ({{1}}) or named ({{order_id}}).
            'parameter_format' => 'nullable|in:POSITIONAL,NAMED',
        ]);

        $results = [];
        $created = 0;

        foreach ($this->resolveTargetConnections($request) as $connection) {
            try {
                $results[] = [
                    'connection_id' => $connection->id,
                    'connection_name' => $connection->name,
                    'status' => 'created',
                    'template' => $this->templates->create($connection, $data),
                ];
                $created++;
            } catch (\Throwable $e) {
                $results[] = [
                    'connection_id' => $connection->id,
                    'connection_name' => $connection->name,
                    'status' => 'failed',
                    'message' => $e->getMessage(),
                ];
            }
        }

        return response()->json([
            'data' => $results,
            'created' => $created,
        ], $created > 0 ? 201 : 422);
    }

    /**
     * Upload a sample image/video and return the handle a media header (or a
     * carousel card) is created with. Meta wants the bytes at creation time,
     * not a URL, so this has to happen before the template is submitted.
     */
    public function media(Request $request)
    {
        $request->validate([
            'connection_id' => 'nullable|integer',
            // Meta's own ceiling for a template header sample is 5 MB for
            // images, 16 MB for video and 100 MB for a document; the widest
            // that is sane to proxy is enforced here and Meta rejects anything
            // past its own limit with a clear message.
            'file' => 'required|file|mimes:jpeg,jpg,png,mp4,pdf|max:16384',
        ]);

        $connection = $this->resolveConnection($request);
        $file = $request->file('file');

        return response()->json([
            'data' => [
                'handle' => $this->templates->uploadHandle(
                    $connection,
                    file_get_contents($file->getRealPath()),
                    $file->getMimeType(),
                    $file->getClientOriginalName(),
                ),
            ],
        ]);
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
            $conversation = Conversation::visibleTo($user)
                ->whereHas('connection', fn ($q) => $q->where('channel', Channel::WhatsappOfficial))
                ->findOrFail($data['conversation_id']);
        } else {
            $connection = $this->resolveConnection($request);
            $phone = preg_replace('/\D+/', '', $data['phone_number']);

            if ($phone === '') {
                throw ValidationException::withMessages([
                    'phone_number' => 'A valid phone number is required.',
                ]);
            }

            $contact = Contact::createFromExternalData($connection, $phone, $data['contact_name'] ?? $phone);

            // Cannot come back null here: the resolver addresses WhatsApp by the
            // contact's own phone number, which we just created it with.
            $conversation = $this->conversations
                ->resolve($connection, $contact, assignedUserId: $user->id)
                ?->conversation;

            if (!$conversation) {
                abort(422, 'Could not open a conversation for this number');
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
     * The connections a create should actually reach, one per WhatsApp Business
     * Account.
     *
     * Templates live on the WABA, so two phone numbers under the same one see
     * the same template list; submitting twice would only earn a duplicate-name
     * error from Meta. Selecting several connections is therefore a way of
     * saying "put this on each of their WABAs", and connections that turn out to
     * share a WABA collapse into one call.
     *
     * @return array<int, Connection>
     */
    private function resolveTargetConnections(Request $request): array
    {
        $ids = array_filter((array) $request->input('connection_ids', []));

        if ($ids === []) {
            return [$this->resolveConnection($request)];
        }

        $connections = Connection::where('tenant_id', $request->user()->tenant_id)
            ->where('channel', Channel::WhatsappOfficial)
            ->whereIn('id', $ids)
            ->get();

        if ($connections->isEmpty()) {
            abort(404, 'No WhatsApp Official connection found');
        }

        return $connections
            // A connection with no WABA id cannot host a template at all; keying
            // those by their own id keeps them in the list so the caller gets a
            // per-connection error rather than a silent disappearance.
            ->unique(fn (Connection $connection) => $connection->credentials['business_account_id'] ?? 'connection:' . $connection->id)
            ->values()
            ->all();
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

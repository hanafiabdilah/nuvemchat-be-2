<?php

namespace App\Http\Controllers\Api;

use App\Enums\Broadcast\ContentType;
use App\Enums\Broadcast\Source;
use App\Enums\Broadcast\Status;
use App\Enums\Connection\Channel;
use App\Enums\Connection\Status as ConnectionStatus;
use App\Enums\Conversation\Status as ConversationStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\BroadcastRecipientResource;
use App\Http\Resources\BroadcastResource;
use App\Models\Broadcast;
use App\Models\Connection;
use App\Models\Conversation;
use App\Services\Broadcast\BroadcastService;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Broadcast campaigns: compose once, reach many, and open a real conversation
 * for each person reached.
 *
 * Everything here is scoped through the campaign's connection, which is the
 * only thing that carries a tenant — the same shape MessageTemplateController
 * uses.
 */
class BroadcastController extends Controller
{
    /**
     * Ceiling on one inbox selection. "Select all" picks every thread the
     * filters match, not only the ones on screen, and the ids travel in one
     * request body.
     */
    private const MAX_SELECTED_THREADS = 1000;

    public function __construct(
        private BroadcastService $broadcasts,
    ) {}

    public function index(Request $request)
    {
        $campaigns = Broadcast::with(['connection', 'creator', 'tag'])
            ->where('tenant_id', $request->user()->tenant_id)
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->filled('connection_id'), fn ($query) => $query->where('connection_id', $request->integer('connection_id')))
            ->when($request->filled('search'), fn ($query) => $query->where('name', 'like', '%' . $request->string('search') . '%'))
            ->orderByDesc('id')
            ->paginate($request->integer('per_page', 20));

        return BroadcastResource::collection($campaigns);
    }

    public function show(Request $request, int $id)
    {
        return new BroadcastResource($this->findForTenant($request, $id));
    }

    /**
     * The delivery report, one row per person. Paginated and filterable by
     * status because the interesting page of a 5.000-person campaign is almost
     * always "show me the failures".
     */
    public function recipients(Request $request, int $id)
    {
        $broadcast = $this->findForTenant($request, $id);

        $recipients = $broadcast->recipients()
            ->with('contact.tags')
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->filled('search'), function ($query) use ($request) {
                $term = '%' . $request->string('search') . '%';
                $query->where(fn ($q) => $q->where('address', 'like', $term)->orWhere('name', 'like', $term));
            })
            ->orderBy('id')
            ->paginate($request->integer('per_page', 50));

        return BroadcastRecipientResource::collection($recipients);
    }

    /**
     * Create a campaign and its recipient list in one shot, then either leave it
     * as a draft, hand it to the scheduler, or start it immediately.
     */
    public function store(Request $request)
    {
        $data = $this->validatePayload($request);
        $connection = $this->resolveConnection($request, (int) $data['connection_id']);

        $this->assertChannelAccepts($connection, ContentType::from($data['content_type']), $data['rate_per_minute'] ?? null);
        $this->assertCanDecideToSend($request, $data);

        $broadcast = DB::transaction(function () use ($request, $data, $connection) {
            $broadcast = Broadcast::create([
                'tenant_id' => $connection->tenant_id,
                'connection_id' => $connection->id,
                'created_by' => $request->user()->id,
                'name' => $data['name'],
                'status' => Status::Draft,
                'content_type' => $data['content_type'],
                'payload' => $data['payload'],
                'scheduled_at' => $data['scheduled_at'] ?? null,
                'rate_per_minute' => $data['rate_per_minute'] ?? $connection->channel->broadcastDefaultRatePerMinute(),
            ]);

            $this->broadcasts->createRecipients(
                $broadcast,
                $connection,
                $data['contact_ids'] ?? [],
                $data['manual_recipients'] ?? [],
            );

            return $broadcast;
        });

        if ($request->boolean('start_now')) {
            $this->broadcasts->start($broadcast);
        } elseif (! empty($data['scheduled_at'])) {
            $this->broadcasts->schedule($broadcast);
        }

        return (new BroadcastResource($broadcast->fresh(['connection', 'creator', 'tag'])))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Send one message into the active threads an agent selected in the inbox,
     * optionally resolving each one right after.
     *
     * This is a campaign underneath, not a loop in the request: the channel's
     * rate limit and send jitter still apply (twenty threads on API Way is two
     * minutes of sending, and a burst is what gets a number banned), the
     * delivery report still says who was skipped and why, and failures can
     * still be retried. What differs is the recipient row, which points at a
     * thread instead of an address — see BroadcastSender::deliverIntoThread().
     *
     * A selection can span several lines, and a campaign sends from one, so
     * this creates one campaign per connection.
     *
     * Only threads the caller could reply to by hand are included — Active,
     * theirs (or any, for an owner), on an active connection — and never
     * e-mail, which composes a mail with a subject rather than a chat message.
     * Everything else in the selection is counted as skipped, not refused: the
     * inbox bar lets people select across statuses.
     */
    public function storeForConversations(Request $request)
    {
        $contentType = $request->input('content_type');

        $data = $request->validate([
            'conversation_ids' => ['required', 'array', 'min:1', 'max:' . self::MAX_SELECTED_THREADS],
            'conversation_ids.*' => ['integer'],
            'name' => ['nullable', 'string', 'max:255'],
            'content_type' => ['required', Rule::in([ContentType::Text->value, ContentType::Media->value])],
            'payload' => ['required', 'array'],
            'payload.body' => [Rule::requiredIf($contentType === ContentType::Text->value), 'string'],
            'payload.media_type' => [Rule::requiredIf($contentType === ContentType::Media->value), Rule::in(['image', 'video', 'document', 'audio'])],
            'payload.media_url' => [Rule::requiredIf($contentType === ContentType::Media->value), 'url'],
            'payload.caption' => ['nullable', 'string'],
            'resolve_after' => ['nullable', 'boolean'],
        ]);

        $user = $request->user();
        $ids = array_values(array_unique(array_map('intval', $data['conversation_ids'])));

        $eligible = Conversation::with(['connection', 'contact'])
            ->visibleTo($user)
            ->whereIn('id', $ids)
            ->where('status', ConversationStatus::Active)
            ->orderBy('id')
            ->get()
            ->filter(function (Conversation $conversation) use ($user) {
                $connection = $conversation->getRelationValue('connection');

                return $connection
                    && $connection->status === ConnectionStatus::Active
                    && $connection->channel !== Channel::Email
                    && $conversation->isAccessibleBy($user);
            });

        if ($eligible->isEmpty()) {
            throw ValidationException::withMessages([
                'conversation_ids' => 'None of the selected conversations can receive this message. Only active conversations you are handling, on an active connection, are included.',
            ]);
        }

        $payload = Arr::only($data['payload'], $contentType === ContentType::Text->value
            ? ['body']
            : ['media_type', 'media_url', 'caption']);

        $byConnection = $eligible->groupBy('connection_id');
        $baseName = trim((string) ($data['name'] ?? '')) ?: 'Inbox message';

        // Messages per minute, keyed by connection id. A selection can span API
        // Way (capped at 60) and WhatsApp Official (1200), and one number for
        // both would be reckless on one line or crawl on the other. A line left
        // out gets its channel's default — the same ceiling and fallback a
        // campaign uses (see assertChannelAccepts()).
        $requestedRates = $request->validate([
            'rates_per_minute' => ['nullable', 'array'],
            'rates_per_minute.*' => ['integer', 'min:1'],
        ])['rates_per_minute'] ?? [];

        $rates = [];

        foreach ($byConnection as $connectionId => $conversations) {
            $channel = $conversations->first()->getRelationValue('connection')->channel;
            $max = $channel->broadcastMaxRatePerMinute();
            $rate = isset($requestedRates[$connectionId]) ? (int) $requestedRates[$connectionId] : null;

            if ($rate !== null && $rate > $max) {
                throw ValidationException::withMessages([
                    "rates_per_minute.{$connectionId}" => "This channel is capped at {$max} messages per minute.",
                ]);
            }

            $rates[$connectionId] = $rate ?? $channel->broadcastDefaultRatePerMinute();
        }

        $campaigns = DB::transaction(function () use ($byConnection, $baseName, $contentType, $payload, $data, $user, $rates) {
            return $byConnection->map(function ($conversations) use ($byConnection, $baseName, $contentType, $payload, $data, $user, $rates) {
                $connection = $conversations->first()->getRelationValue('connection');

                $broadcast = Broadcast::create([
                    'tenant_id' => $connection->tenant_id,
                    'connection_id' => $connection->id,
                    'created_by' => $user->id,
                    // Several campaigns from one click share a name otherwise,
                    // and the list would show the same line twice.
                    'name' => $byConnection->count() > 1 ? mb_substr("{$baseName} · {$connection->name}", 0, 255) : $baseName,
                    'status' => Status::Draft,
                    'source' => Source::Inbox,
                    'resolve_after' => (bool) ($data['resolve_after'] ?? false),
                    'content_type' => $contentType,
                    'payload' => $payload,
                    'rate_per_minute' => $rates[$connection->id],
                ]);

                $this->broadcasts->createThreadRecipients($broadcast, $conversations);

                return $broadcast;
            })->values();
        });

        foreach ($campaigns as $broadcast) {
            $this->broadcasts->start($broadcast);
        }

        $queued = (int) $campaigns->sum(fn (Broadcast $broadcast) => $broadcast->total_recipients);

        return response()->json([
            'data' => BroadcastResource::collection($campaigns->map->fresh(['connection', 'creator', 'tag'])),
            'queued' => $queued,
            'skipped' => count($ids) - $queued,
        ], 201);
    }

    /**
     * Edit a campaign that has not started. Recipients are replaced wholesale
     * when a new list is supplied — patching a partly-sent list would mean
     * reasoning about who has already been messaged, which is exactly what the
     * "not started" guard avoids.
     */
    public function update(Request $request, int $id)
    {
        $broadcast = $this->findForTenant($request, $id);

        if (! $broadcast->status->isEditable()) {
            throw ValidationException::withMessages([
                'status' => 'Only a draft or scheduled campaign can be edited.',
            ]);
        }

        $data = $this->validatePayload($request);
        $connection = $this->resolveConnection($request, (int) $data['connection_id']);

        $this->assertChannelAccepts($connection, ContentType::from($data['content_type']), $data['rate_per_minute'] ?? null);
        $this->assertCanDecideToSend($request, $data);

        DB::transaction(function () use ($broadcast, $connection, $data) {
            $broadcast->update([
                'connection_id' => $connection->id,
                'name' => $data['name'],
                'content_type' => $data['content_type'],
                'payload' => $data['payload'],
                'scheduled_at' => $data['scheduled_at'] ?? null,
                'rate_per_minute' => $data['rate_per_minute'] ?? $connection->channel->broadcastDefaultRatePerMinute(),
            ]);

            $broadcast->recipients()->delete();

            $this->broadcasts->createRecipients(
                $broadcast,
                $connection,
                $data['contact_ids'] ?? [],
                $data['manual_recipients'] ?? [],
            );
        });

        if (! empty($data['scheduled_at'])) {
            $this->broadcasts->schedule($broadcast);
        }

        return new BroadcastResource($broadcast->fresh(['connection', 'creator', 'tag']));
    }

    public function destroy(Request $request, int $id)
    {
        $broadcast = $this->findForTenant($request, $id);

        if ($broadcast->status->isActive()) {
            throw ValidationException::withMessages([
                'status' => 'Cancel the campaign before deleting it.',
            ]);
        }

        // The conversations and messages it produced are real history and stay;
        // only the campaign and its report go.
        $broadcast->delete();

        return response()->json(['message' => 'Campaign deleted']);
    }

    public function start(Request $request, int $id)
    {
        return new BroadcastResource(
            $this->broadcasts->start($this->findForTenant($request, $id))->fresh(['connection', 'creator', 'tag'])
        );
    }

    public function pause(Request $request, int $id)
    {
        return new BroadcastResource(
            $this->broadcasts->pause($this->findForTenant($request, $id))->fresh(['connection', 'creator', 'tag'])
        );
    }

    public function resume(Request $request, int $id)
    {
        return new BroadcastResource(
            $this->broadcasts->resume($this->findForTenant($request, $id))->fresh(['connection', 'creator', 'tag'])
        );
    }

    public function cancel(Request $request, int $id)
    {
        return new BroadcastResource(
            $this->broadcasts->cancel($this->findForTenant($request, $id))->fresh(['connection', 'creator', 'tag'])
        );
    }

    public function retryFailed(Request $request, int $id)
    {
        return new BroadcastResource(
            $this->broadcasts->retryFailed($this->findForTenant($request, $id))->fresh(['connection', 'creator', 'tag'])
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function validatePayload(Request $request): array
    {
        $contentType = $request->input('content_type');

        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'connection_id' => ['required', 'integer'],
            'content_type' => ['required', Rule::enum(ContentType::class)],
            'payload' => ['required', 'array'],

            // Per-type payload shape. Meta's components array is passed through
            // untouched (tokens and all) — see VariableResolver for why we do
            // not rebuild it server-side.
            'payload.template_name' => [Rule::requiredIf($contentType === ContentType::Template->value), 'string'],
            'payload.language' => [Rule::requiredIf($contentType === ContentType::Template->value), 'string'],
            'payload.components' => ['nullable', 'array'],

            'payload.body' => [Rule::requiredIf(in_array($contentType, [ContentType::Text->value, ContentType::Email->value], true)), 'string'],
            'payload.subject' => [Rule::requiredIf($contentType === ContentType::Email->value), 'string', 'max:255'],

            'payload.media_type' => [Rule::requiredIf($contentType === ContentType::Media->value), Rule::in(['image', 'video', 'document', 'audio'])],
            // A campaign cannot re-upload a file per recipient, so media is
            // always a URL the channel fetches for itself.
            'payload.media_url' => [Rule::requiredIf($contentType === ContentType::Media->value), 'url'],
            'payload.caption' => ['nullable', 'string'],

            'contact_ids' => ['nullable', 'array'],
            'contact_ids.*' => ['integer'],
            'manual_recipients' => ['nullable', 'array'],
            'manual_recipients.*.address' => ['required', 'string', 'max:255'],
            'manual_recipients.*.name' => ['nullable', 'string', 'max:255'],

            'scheduled_at' => ['nullable', 'date', 'after:now'],
            'rate_per_minute' => ['nullable', 'integer', 'min:1'],
            'start_now' => ['nullable', 'boolean'],
        ]);
    }

    /**
     * Refuse combinations the platform would refuse anyway, but with an answer
     * the operator can act on instead of a Meta error code arriving one message
     * at a time in the delivery report.
     */
    private function assertChannelAccepts(Connection $connection, ContentType $contentType, ?int $ratePerMinute = null): void
    {
        $channel = $connection->channel;

        if (! $channel->supportsBroadcast()) {
            throw ValidationException::withMessages([
                'connection_id' => 'Campaigns cannot be sent on this channel.',
            ]);
        }

        if ($channel->broadcastRequiresTemplate() && $contentType !== ContentType::Template) {
            throw ValidationException::withMessages([
                'content_type' => 'WhatsApp Official only accepts an approved template here: a campaign reaches people outside the 24-hour window, where free-form messages are refused.',
            ]);
        }

        if ($contentType === ContentType::Template && $channel !== Channel::WhatsappOfficial) {
            throw ValidationException::withMessages([
                'content_type' => 'Message templates exist only on WhatsApp Official.',
            ]);
        }

        if (($contentType === ContentType::Email) !== ($channel === Channel::Email)) {
            throw ValidationException::withMessages([
                'content_type' => 'E-mail campaigns run on an e-mail connection, and an e-mail connection sends nothing else.',
            ]);
        }

        $max = $channel->broadcastMaxRatePerMinute();

        if ($ratePerMinute !== null && $ratePerMinute > $max) {
            throw ValidationException::withMessages([
                'rate_per_minute' => "This channel is capped at {$max} messages per minute.",
            ]);
        }
    }

    /**
     * `broadcasts.create` only earns a draft. Firing it now (`start_now`) or
     * scheduling it (`scheduled_at`) is the same decision `/start` requires
     * `broadcasts.send` for — reachable a second way here, so it needs the
     * same gate, or the permission split on the dedicated endpoint is
     * decorative.
     */
    private function assertCanDecideToSend(Request $request, array $data): void
    {
        $decidesToSend = $request->boolean('start_now') || ! empty($data['scheduled_at']);

        if (! $decidesToSend || $request->user()->can('broadcasts.send')) {
            return;
        }

        abort(response()->json([
            'message' => 'Starting or scheduling a campaign requires the permission to send campaigns.',
            'code' => 'broadcasts_send_required',
        ], 403));
    }

    private function resolveConnection(Request $request, int $connectionId): Connection
    {
        $connection = Connection::where('tenant_id', $request->user()->tenant_id)
            ->where('id', $connectionId)
            ->first();

        if (! $connection) {
            abort(404, 'Connection not found');
        }

        if ($connection->status !== ConnectionStatus::Active) {
            throw ValidationException::withMessages([
                'connection_id' => 'This connection is not active.',
            ]);
        }

        return $connection;
    }

    private function findForTenant(Request $request, int $id): Broadcast
    {
        return Broadcast::with(['connection', 'creator', 'tag'])
            ->where('tenant_id', $request->user()->tenant_id)
            ->findOrFail($id);
    }
}

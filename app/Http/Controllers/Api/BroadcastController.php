<?php

namespace App\Http\Controllers\Api;

use App\Enums\Broadcast\ContentType;
use App\Enums\Broadcast\Status;
use App\Enums\Connection\Channel;
use App\Enums\Connection\Status as ConnectionStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\BroadcastRecipientResource;
use App\Http\Resources\BroadcastResource;
use App\Models\Broadcast;
use App\Models\Connection;
use App\Services\Broadcast\BroadcastService;
use Illuminate\Http\Request;
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
    public function __construct(
        private BroadcastService $broadcasts,
    ) {}

    public function index(Request $request)
    {
        $campaigns = Broadcast::with(['connection', 'creator', 'tag'])
            ->where('tenant_id', $request->user()->tenant_id)
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->filled('connection_id'), fn ($query) => $query->where('connection_id', $request->integer('connection_id')))
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
            ->with('contact')
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

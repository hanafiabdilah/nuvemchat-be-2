<?php

namespace App\Services\Lead;

use App\Enums\Billing\Feature;
use App\Enums\Broadcast\AddressType;
use App\Enums\Connection\Channel;
use App\Enums\Connection\Status as ConnectionStatus;
use App\Enums\Conversation\Status as ConversationStatus;
use App\Enums\Lead\LeadSource;
use App\Enums\Lead\StageKind;
use App\Events\ConversationUpdated;
use App\Events\LeadUpdated;
use App\Events\MessageReceived;
use App\Exceptions\ChannelCapabilityException;
use App\Exceptions\PublicApiException;
use App\Exceptions\UpstreamServiceException;
use App\Models\ApiKey;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\LeadIntake;
use App\Models\LeadStage;
use App\Models\Tag;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Billing\SubscriptionGate;
use App\Services\Conversation\OutboundConversationResolver;
use App\Services\Conversation\SystemMessage;
use App\Services\Message\MessageService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Receives a prospect from another system and puts them in front of an agent.
 *
 * The caller is typically a scheduler in another product — "sign-ups that did
 * not pay within 3 hours" — and what it wants is one sentence: *make sure a
 * salesperson talks to this person*. So one call does everything that sentence
 * needs, in this order:
 *
 *   contact (found under either spelling of a Brazilian mobile, or created)
 *   → lead card (source `api`; an open card someone is working is reused)
 *   → conversation on a WhatsApp connection (queued, or assigned to an agent)
 *   → a note in the thread with what the form knew (e-mail, metadata)
 *   → optionally the first message (text on API Way, a template on Official)
 *
 * Two guarantees shape the code:
 *
 *  1. Every refusal happens before anything is written. A 422 leaves no
 *     contact, card or thread behind for the caller's retry to trip over.
 *  2. A retry with the same `reference` never sends a second message — it gets
 *     the first call's response back (LeadIntake is the idempotency record).
 *
 * The opening message failing is NOT a failed request: the lead is still in
 * the inbox, the thread says why nothing went out, and an agent can take it
 * from there. The response reports it in `opening_message`.
 */
final class LeadIntakeService
{
    /** Channels a lead can be delivered to: the ones addressed by phone number. */
    public const CHANNELS = [Channel::WhatsappOfficial, Channel::WhatsappApiway];

    public const NOTE_RECEIVED = 'lead_received_via_api';

    public const NOTE_RECEIVED_WITH_DETAILS = 'lead_received_via_api_details';

    public const NOTE_OPENING_FAILED = 'lead_opening_message_failed';

    /**
     * A row whose request died mid-way (a worker killed, a fatal error) must not
     * answer "still processing" to its retries forever.
     */
    private const STALE_INTAKE_MINUTES = 5;

    public function __construct(
        private LeadResolver $leads,
        private TemperatureScorer $scorer,
        private OutboundConversationResolver $conversations,
        private MessageService $messages,
        private SubscriptionGate $gate,
    ) {}

    /**
     * @param  array<string, mixed>  $data  already validated by the controller
     * @return array{status: int, body: array<string, mixed>}
     */
    public function receive(Tenant $tenant, ApiKey $key, array $data): array
    {
        $reference = trim((string) ($data['reference'] ?? '')) ?: null;

        if ($reference && ($replay = $this->replay($tenant, $reference))) {
            return $replay;
        }

        $connection = $this->connectionFor($tenant, $data['connection_id'] ?? null);
        $phone = $this->phoneFrom((string) $data['phone']);
        $assignee = $this->assigneeFor($tenant, $connection, $data['assign_to'] ?? null);
        $stage = $this->stageFor($tenant, $data['stage_id'] ?? null);
        $opening = $this->openingFor($connection, $data);

        try {
            $intake = LeadIntake::create([
                'tenant_id' => $tenant->id,
                'api_key_id' => $key->id,
                'reference' => $reference,
                'connection_id' => $connection->id,
                'payload' => [
                    'name' => $data['name'],
                    'phone' => $phone,
                    'email' => $data['email'] ?? null,
                    'metadata' => $data['metadata'] ?? null,
                ],
            ]);
        } catch (UniqueConstraintViolationException) {
            // Two retries raced past the lookup above; the loser answers with the winner.
            return $this->replay($tenant, (string) $reference)
                ?? throw new PublicApiException('Este lead ainda está sendo processado. Tente de novo em alguns segundos.', 'intake_in_progress', 409);
        }

        try {
            $body = $this->process($intake, $tenant, $key, $connection, $phone, $data, $assignee, $stage, $opening);
        } catch (\Throwable $th) {
            // Nothing was sent (the send path never throws), so the retry may
            // start over; a reused contact, card or thread is simply found again.
            $intake->delete();

            throw $th;
        }

        return ['status' => 201, 'body' => $body];
    }

    /**
     * Brazilian mobiles exist in two spellings on WhatsApp: with the ninth digit
     * (55 11 9 8765-4321) and, for numbers that predate it, without — and the id
     * WhatsApp reports is whichever it has on file. A sign-up form always has the
     * modern spelling, so matching only that would open a second contact next to
     * the one the inbox already shows.
     *
     * @return list<string>
     */
    public static function phoneVariants(string $phone): array
    {
        $variants = [$phone];

        if (str_starts_with($phone, '55')) {
            if (strlen($phone) === 13 && $phone[4] === '9') {
                $variants[] = substr($phone, 0, 4).substr($phone, 5);
            } elseif (strlen($phone) === 12 && in_array($phone[4], ['6', '7', '8', '9'], true)) {
                $variants[] = substr($phone, 0, 4).'9'.substr($phone, 4);
            }
        }

        return $variants;
    }

    /**
     * @return array{status: int, body: array<string, mixed>}|null
     */
    private function replay(Tenant $tenant, string $reference): ?array
    {
        $intake = LeadIntake::where('tenant_id', $tenant->id)->where('reference', $reference)->first();

        if (! $intake) {
            return null;
        }

        if ($intake->result === null) {
            if ($intake->created_at?->lt(now()->subMinutes(self::STALE_INTAKE_MINUTES))) {
                $intake->delete();

                return null;
            }

            throw new PublicApiException('Este lead ainda está sendo processado. Tente de novo em alguns segundos.', 'intake_in_progress', 409);
        }

        return ['status' => 200, 'body' => array_merge($intake->result, ['duplicate' => true])];
    }

    /**
     * The WhatsApp connection the prospect will be talked to on. Optional in the
     * request because most workspaces have exactly one — and when they have more,
     * the refusal lists them, so the integrator can pick without opening Pingly.
     */
    private function connectionFor(Tenant $tenant, mixed $connectionId): Connection
    {
        $whatsapp = Connection::where('tenant_id', $tenant->id)
            ->whereIn('channel', array_map(fn (Channel $channel) => $channel->value, self::CHANNELS));

        if ($connectionId !== null && $connectionId !== '') {
            // The public id (conn_…), never the numeric primary key.
            $connection = (clone $whatsapp)->where('public_id', (string) $connectionId)->first();

            if (! $connection) {
                throw ValidationException::withMessages([
                    'connection_id' => 'Conexão não encontrada entre as conexões de WhatsApp desta conta.',
                ]);
            }

            if (! $this->isActive($connection)) {
                throw new PublicApiException(
                    "A conexão \"{$connection->name}\" não está ativa. Reconecte-a no Pingly ou informe outra connection_id.",
                    'connection_inactive',
                );
            }

            return $connection;
        }

        $active = (clone $whatsapp)->orderBy('id')->get()->filter(fn (Connection $c) => $this->isActive($c))->values();

        if ($active->count() === 1) {
            return $active->first();
        }

        if ($active->isEmpty()) {
            throw new PublicApiException(
                'Esta conta não tem nenhuma conexão de WhatsApp ativa para receber leads.',
                'no_whatsapp_connection',
            );
        }

        throw new PublicApiException(
            'Esta conta tem mais de uma conexão de WhatsApp ativa. Informe connection_id para escolher por qual número o lead será atendido.',
            'connection_required',
            extra: [
                'connections' => $active->map(fn (Connection $c) => [
                    'id' => $c->public_id,
                    'name' => $c->name,
                    'channel' => $c->channel->value,
                ])->all(),
            ],
        );
    }

    private function isActive(Connection $connection): bool
    {
        $status = $connection->status instanceof \BackedEnum ? $connection->status->value : $connection->status;

        return $status === ConnectionStatus::Active->value;
    }

    private function phoneFrom(string $raw): string
    {
        $phone = AddressType::Phone->normalize($raw);

        if (! AddressType::Phone->isValid($phone)) {
            throw ValidationException::withMessages([
                'phone' => 'Informe o número de WhatsApp com DDI e DDD, só com dígitos — por exemplo 5511987654321.',
            ]);
        }

        return $phone;
    }

    /** `assign_to` accepts the agent's id or their login e-mail — the one the other system is likelier to know. */
    private function assigneeFor(Tenant $tenant, Connection $connection, mixed $assignTo): ?User
    {
        if ($assignTo === null || $assignTo === '') {
            return null;
        }

        $query = User::where('tenant_id', $tenant->id);

        $user = is_numeric($assignTo)
            ? $query->find((int) $assignTo)
            : $query->whereRaw('LOWER(email) = ?', [mb_strtolower(trim((string) $assignTo))])->first();

        if (! $user) {
            throw ValidationException::withMessages(['assign_to' => 'Atendente não encontrado nesta conta.']);
        }

        // Same rule as every other assignment: a thread handed to someone who
        // cannot open its connection is a thread nobody can answer.
        if (! $user->canAccessConnection($connection)) {
            throw ValidationException::withMessages([
                'assign_to' => "{$user->name} não tem acesso à conexão \"{$connection->name}\".",
            ]);
        }

        return $user;
    }

    private function stageFor(Tenant $tenant, mixed $stageId): ?LeadStage
    {
        if ($stageId === null || $stageId === '') {
            return null;
        }

        $stage = LeadStage::whereKey((int) $stageId)
            ->whereHas('pipeline', fn ($query) => $query->where('tenant_id', $tenant->id))
            ->first();

        if (! $stage) {
            throw ValidationException::withMessages(['stage_id' => 'Etapa não encontrada no funil desta conta.']);
        }

        // A prospect is by definition not a closed sale.
        if ($stage->kind !== StageKind::Open) {
            throw ValidationException::withMessages([
                'stage_id' => 'Um lead recebido pela API só pode entrar em uma etapa aberta — não em ganho ou perdido.',
            ]);
        }

        return $stage;
    }

    /**
     * What the first message is, checked against what the channel allows before
     * anything is created.
     *
     * @return array{type: 'text'|'template', data: array<string, mixed>}|null
     */
    private function openingFor(Connection $connection, array $data): ?array
    {
        $text = trim((string) ($data['message'] ?? ''));
        $template = $data['template'] ?? null;

        if ($text !== '' && $template) {
            throw ValidationException::withMessages(['message' => 'Envie message ou template, não os dois.']);
        }

        if ($template) {
            if ($connection->channel !== Channel::WhatsappOfficial) {
                throw new PublicApiException(
                    'Modelos (template) só existem no WhatsApp Oficial. Nesta conexão, envie a primeira mensagem em message.',
                    'template_not_supported',
                );
            }

            return ['type' => 'template', 'data' => [
                'template_name' => $template['name'],
                'language' => $template['language'],
                'components' => $template['components'] ?? null,
            ]];
        }

        if ($text === '') {
            return null;
        }

        if (! $connection->channel->canStartConversation()) {
            throw new PublicApiException(
                'O WhatsApp Oficial não permite iniciar uma conversa com texto livre. Envie a primeira mensagem com um modelo aprovado em template.',
                'template_required',
            );
        }

        return ['type' => 'text', 'data' => ['message' => $text]];
    }

    /**
     * @return array<string, mixed> the response body
     */
    private function process(
        LeadIntake $intake,
        Tenant $tenant,
        ApiKey $key,
        Connection $connection,
        string $phone,
        array $data,
        ?User $assignee,
        ?LeadStage $stage,
        ?array $opening,
    ): array {
        [$contact, $contactCreated] = $this->contactFor($connection, $phone, trim((string) $data['name']));

        // Before the thread, and the order is load-bearing: creating a
        // conversation dispatches EnsureLeadForConversation, which would open
        // the card itself — labelled as the customer having written first.
        [$lead, $leadCreated] = $this->leadFor($tenant, $contact, $data, $assignee, $stage, $connection);

        // Never null on these two channels: the thread's address is the phone number itself.
        $resolved = $this->conversations->resolve(
            $connection,
            $contact,
            assignedUserId: $assignee?->id,
            // A thread already open is somebody's work in progress; the API adds
            // to it without moving it.
            activateOnReuse: false,
            // Nobody named → the queue every agent watches. An Active thread
            // without an owner is the state "Assumir chat" exists to repair.
            createAs: $assignee ? ConversationStatus::Active : ConversationStatus::Pending,
        );

        $conversation = $resolved->conversation;

        if ($lead) {
            $conversation->setRelation('contact', $contact)->setRelation('connection', $connection);
            $this->leads->attach($conversation);
        }

        $ignoredTags = $this->tag($conversation, $tenant, $data['tags'] ?? []);

        $this->note($conversation, $contact, $key, $data);

        $openingResult = $opening
            ? $this->sendOpening($conversation, $contact, $opening)
            : ['status' => 'not_requested'];

        $conversation->refresh();

        $body = [
            'id' => $intake->id,
            'reference' => $intake->reference,
            'duplicate' => false,
            'contact' => [
                'id' => $contact->id,
                'name' => $contact->name,
                'phone' => $contact->external_id,
                'created' => $contactCreated,
            ],
            'lead' => $lead ? [
                'id' => $lead->id,
                'created' => $leadCreated,
                'status' => $lead->status->value,
                'stage' => $lead->stage ? ['id' => $lead->stage->id, 'name' => $lead->stage->name] : null,
            ] : null,
            'conversation' => [
                'id' => $conversation->id,
                'created' => $resolved->wasCreated,
                'status' => $conversation->status->value,
                'connection_id' => $connection->public_id,
                'assigned_to' => $conversation->user_id
                    ? ['id' => $conversation->user_id, 'name' => User::whereKey($conversation->user_id)->value('name')]
                    : null,
            ],
            'opening_message' => $openingResult,
            'ignored_tags' => $ignoredTags,
        ];

        // Saved before the announcements below: once a message has gone out,
        // nothing after it may throw the intake away and invite a resend.
        $intake->forceFill([
            'contact_id' => $contact->id,
            'lead_id' => $lead?->id,
            'conversation_id' => $conversation->id,
            'opening_status' => $openingResult['status'],
            'result' => $body,
        ])->save();

        $this->quietly(fn () => broadcast(new ConversationUpdated($conversation->load('contact'))));

        if ($lead) {
            $this->quietly(fn () => broadcast(new LeadUpdated($lead)));
        }

        Log::info('Lead received through the public API', [
            'intake_id' => $intake->id,
            'tenant_id' => $tenant->id,
            'api_key_id' => $key->id,
            'connection_id' => $connection->id,
            'contact_id' => $contact->id,
            'lead_id' => $lead?->id,
            'conversation_id' => $conversation->id,
            'opening_status' => $openingResult['status'],
        ]);

        return $body;
    }

    /**
     * @return array{0: Contact, 1: bool}
     */
    private function contactFor(Connection $connection, string $phone, string $name): array
    {
        $existing = Contact::where('tenant_id', $connection->tenant_id)
            ->where('is_group', false)
            ->whereIn('external_id', self::phoneVariants($phone))
            ->orderByRaw('external_id = ? desc', [$phone])
            ->first();

        // Someone the inbox already knows keeps the name it shows for them; the
        // name typed on the form is still in the note.
        if ($existing) {
            return [$existing, false];
        }

        $contact = Contact::createFromExternalData(
            $connection,
            $phone,
            $name,
            // API Way stores the number as the username too (see its webhook handler).
            $connection->channel === Channel::WhatsappApiway ? $phone : null,
        );

        return [$contact, $contact->wasRecentlyCreated];
    }

    /**
     * @return array{0: ?Lead, 1: bool}
     */
    private function leadFor(Tenant $tenant, Contact $contact, array $data, ?User $assignee, ?LeadStage $stage, Connection $connection): array
    {
        // Same gate as EnsureLeadForConversation, master switch included.
        // Without the CRM the person still reaches the inbox — that is the part
        // the caller cannot do without — there is just no board to put a card on.
        if (config('services.billing.enforce') && ! $this->gate->feature($tenant, Feature::Crm->value)) {
            return [null, false];
        }

        $lead = $this->leads->openLeadFor($contact)
            ?? $this->leads->open($contact, null, LeadSource::Api, $tenant->id);

        $created = $lead->wasRecentlyCreated;

        // An open card someone is already working keeps its column, title and
        // value: a second sign-up from the same person is news for the thread,
        // not a reason to drag the sale back to the start. Only blanks are filled.
        $updates = array_filter([
            'title' => $lead->title ? null : ($data['title'] ?? null),
            'value' => $lead->value !== null ? null : ($data['value'] ?? null),
            'owner_id' => $lead->owner_id ? null : $assignee?->id,
            'source_connection_id' => $lead->source_connection_id ? null : $connection->id,
        ], fn ($value) => $value !== null && $value !== '');

        if ($updates !== []) {
            $lead->update($updates);
        }

        if ($created && $stage && $stage->id !== $lead->stage_id) {
            $lead->moveToStage($stage);
        }

        $this->scorer->apply($lead);

        return [$lead->fresh(['stage', 'contact']), $created];
    }

    /**
     * Tags are matched by name against the workspace's existing ones. An unknown
     * name is reported, never fatal and never created: a tag renamed in the
     * dashboard must not start losing every lead the integration sends, and an
     * outside system should not be writing the workspace's vocabulary.
     *
     * @return list<string> names that matched no tag
     */
    private function tag(Conversation $conversation, Tenant $tenant, array $names): array
    {
        $names = collect($names)
            ->map(fn ($name) => trim((string) $name))
            ->filter()
            ->unique(fn (string $name) => mb_strtolower($name))
            ->values();

        if ($names->isEmpty()) {
            return [];
        }

        $tags = Tag::where('tenant_id', $tenant->id)->get()->keyBy(fn (Tag $tag) => mb_strtolower($tag->name));

        $ids = [];
        $ignored = [];

        foreach ($names as $name) {
            if ($tag = $tags->get(mb_strtolower($name))) {
                $ids[] = $tag->id;
            } else {
                $ignored[] = $name;
            }
        }

        if ($ids !== []) {
            $conversation->tags()->syncWithoutDetaching($ids);
        }

        return $ignored;
    }

    /**
     * What the form knew that the contact row cannot hold — contacts have no
     * e-mail column — written where the agent who opens the thread is reading.
     * It is also what makes a brand-new thread visible at all: the inbox lists
     * conversations that have at least one message.
     */
    private function note(Conversation $conversation, Contact $contact, ApiKey $key, array $data): void
    {
        $name = trim((string) $data['name']);

        $details = collect([
            $name !== '' && $name !== $contact->name ? $name : null,
            $data['email'] ?? null,
        ])->merge(collect($data['metadata'] ?? [])->map(
            fn ($value, $field) => $value === null || $value === '' ? null : "{$field}: ".(is_bool($value) ? ($value ? 'true' : 'false') : $value)
        ))->filter()->implode(' · ');

        if ($details === '') {
            SystemMessage::info($conversation, "Lead received through the API ({$key->name}).", self::NOTE_RECEIVED, ['key' => $key->name]);

            return;
        }

        SystemMessage::info(
            $conversation,
            "Lead received through the API ({$key->name}): {$details}",
            self::NOTE_RECEIVED_WITH_DETAILS,
            ['key' => $key->name, 'details' => $details],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function sendOpening(Conversation $conversation, Contact $contact, array $opening): array
    {
        // Same opt-out campaigns honour: someone who answered "parar" to an
        // automated message has asked not to get the next one either.
        if ($contact->hasOptedOutOfBroadcasts()) {
            return [
                'status' => 'skipped',
                'code' => 'contact_opted_out',
                'error' => 'O contato pediu para não receber mensagens automáticas; a primeira mensagem não foi enviada.',
            ];
        }

        try {
            $message = $opening['type'] === 'template'
                ? $this->messages->sendTemplate($conversation, $opening['data'])
                : $this->messages->sendMessage($conversation, $opening['data']);
        } catch (\Throwable $th) {
            $error = $this->failureReason($th);

            Log::warning('Public API lead: opening message failed', [
                'conversation_id' => $conversation->id,
                'exception' => $th::class,
                'error' => $th->getMessage(),
            ]);

            SystemMessage::info($conversation, "The opening message could not be sent: {$error}", self::NOTE_OPENING_FAILED, ['error' => $error]);

            return ['status' => 'failed', 'code' => 'send_failed', 'error' => $error];
        }

        if ($message) {
            $this->quietly(fn () => broadcast(new MessageReceived($message)));
        }

        return ['status' => 'sent', 'message_id' => $message?->id];
    }

    /** MessageService already translated channel refusals into our words; anything else is not for the caller. */
    private function failureReason(\Throwable $th): string
    {
        if ($th instanceof UpstreamServiceException || $th instanceof ChannelCapabilityException) {
            return $th->getMessage();
        }

        if ($th instanceof ValidationException) {
            return (string) ($th->validator->errors()->first() ?: 'Dados inválidos para a primeira mensagem.');
        }

        return 'Não foi possível enviar a primeira mensagem.';
    }

    /**
     * A dashboard that does not light up is a small problem; a request that
     * fails after the prospect's phone already buzzed is a large one.
     */
    private function quietly(callable $announce): void
    {
        try {
            $announce();
        } catch (\Throwable $th) {
            Log::warning('Public API lead: realtime announcement failed', ['error' => $th->getMessage()]);
        }
    }
}

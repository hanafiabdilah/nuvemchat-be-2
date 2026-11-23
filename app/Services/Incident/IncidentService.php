<?php

namespace App\Services\Incident;

use App\Enums\Connection\Channel;
use App\Enums\Conversation\Status as ConversationStatus;
use App\Enums\Conversation\Type as ConversationType;
use App\Enums\Incident\DeliveryKind;
use App\Enums\Incident\DeliverySource;
use App\Enums\Incident\DeliveryStatus;
use App\Enums\Incident\IncidentSource;
use App\Enums\Incident\IncidentStatus;
use App\Enums\Incident\MatchMode;
use App\Enums\Incident\ReplyMode;
use App\Enums\Incident\ResolutionAudience;
use App\Enums\Message\MessageType;
use App\Enums\Message\SenderType;
use App\Exceptions\PublicApiException;
use App\Jobs\RunIncidentOutreach;
use App\Models\AiHubAgent;
use App\Models\ApiKey;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\Incident;
use App\Models\IncidentDelivery;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Everything a person (or the public API) does to an incident: open it now or
 * for later, change it while it runs, give it more time, and end it.
 *
 * Two rules hold across all of it:
 *
 *  - **One live incident per connection.** Two would mean two notices to the
 *    same customer, or a race over which one answers. Checked under a
 *    per-workspace lock so two people opening incidents at the same moment
 *    cannot both pass.
 *  - **An incident always ends.** `ends_at` is required and capped at 72
 *    hours; a forgotten one stops answering on its own (incidents:tick), and
 *    the people who asked are told on WhatsApp before it does.
 */
class IncidentService
{
    /**
     * The rules a create (or, with $partial, an update) is held to. One copy,
     * read by the dashboard and the public API alike.
     *
     * @return array<string, mixed>
     */
    public function rules(bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return [
            'title' => [$required, 'string', 'max:120'],
            'connection_ids' => [$required, 'array', 'min:1', 'max:200'],
            'connection_ids.*' => ['integer'],
            'match_mode' => ['sometimes', Rule::enum(MatchMode::class)],
            'keywords' => ['sometimes', 'nullable', 'array', 'max:'.Incident::MAX_KEYWORDS],
            'keywords.*' => ['string', 'min:'.IncidentMatcher::MIN_KEYWORD_LENGTH, 'max:'.IncidentMatcher::MAX_KEYWORD_LENGTH],
            'exclude_keywords' => ['sometimes', 'nullable', 'array', 'max:'.Incident::MAX_KEYWORDS],
            'exclude_keywords.*' => ['string', 'min:'.IncidentMatcher::MIN_KEYWORD_LENGTH, 'max:'.IncidentMatcher::MAX_KEYWORD_LENGTH],
            'reply_mode' => ['sometimes', Rule::enum(ReplyMode::class)],
            'ai_hub_agent_id' => ['sometimes', 'nullable', 'integer'],
            'ai_brief' => ['sometimes', 'nullable', 'string', 'max:4000'],
            'message' => [$required, 'string', 'max:4096'],
            'media_url' => ['sometimes', 'nullable', 'url', 'max:2048'],
            'media_type' => ['sometimes', 'nullable', Rule::in(['image', 'video', 'document', 'audio'])],
            'reply_in_agent_threads' => ['sometimes', 'boolean'],
            'continue_flow' => ['sometimes', 'boolean'],
            'notify_recent_minutes' => ['sometimes', 'integer', 'min:0', 'max:'.Incident::MAX_NOTIFY_RECENT_MINUTES],
            'resolution_message' => ['sometimes', 'nullable', 'string', 'max:4096'],
            'resolution_audience' => ['sometimes', Rule::enum(ResolutionAudience::class)],
            'starts_at' => ['sometimes', 'nullable', 'date'],
            'ends_at' => ['sometimes', 'nullable', 'date'],
            'duration_minutes' => ['sometimes', 'nullable', 'integer', 'min:'.Incident::MIN_DURATION_MINUTES, 'max:'.Incident::MAX_DURATION_MINUTES],
        ];
    }

    /** @param  array<string, mixed>  $input */
    public function validate(array $input, bool $partial = false): array
    {
        return Validator::make($input, $this->rules($partial))->validate();
    }

    /**
     * Open an incident: live at once, or scheduled when `starts_at` is in the
     * future.
     *
     * @param  array<string, mixed>  $data  validated by rules()
     */
    public function create(Tenant $tenant, array $data, ?User $actor, IncidentSource $source = IncidentSource::Manual, ?ApiKey $key = null): Incident
    {
        $connections = $this->connectionsFor($tenant, (array) $data['connection_ids'], $actor);
        $attributes = $this->contentAttributes($data, null, (int) $tenant->id);

        $startsAt = $this->parseTime($data['starts_at'] ?? null);
        $scheduled = $startsAt !== null && $startsAt->isFuture();
        $start = $scheduled ? $startsAt : now();
        $endsAt = $this->endFor($start, $data);

        $incident = $this->locked($tenant, function () use ($tenant, $connections, $attributes, $scheduled, $start, $endsAt, $actor, $source, $key) {
            $this->assertNoOverlap($tenant, $connections, $start, $endsAt, null);

            $incident = Incident::create(array_merge($attributes, [
                'tenant_id' => $tenant->id,
                'status' => $scheduled ? IncidentStatus::Scheduled : IncidentStatus::Active,
                'starts_at' => $scheduled ? $start : null,
                'ends_at' => $endsAt,
                'activated_at' => $scheduled ? null : now(),
                'source' => $source,
                'created_by' => $actor?->id,
                'api_key_id' => $key?->id,
            ]));

            $incident->connections()->sync($connections->pluck('id')->all());

            return $incident;
        });

        Log::info('Incident opened', [
            'tenant_id' => $tenant->id,
            'incident_id' => $incident->id,
            'status' => $incident->status->value,
            'source' => $source->value,
            'actor_id' => $actor?->id,
            'connection_ids' => $connections->pluck('id')->all(),
        ]);

        if ($incident->status === IncidentStatus::Active) {
            $this->afterActivation($incident);
        } else {
            IncidentEvents::changed($incident);
        }

        return $incident->fresh(['connections']);
    }

    /**
     * Change an incident that is scheduled or running. Everything a running
     * one says can change, and the next customer gets the new words; when it
     * starts can only change while it has not.
     *
     * @param  array<string, mixed>  $data  validated by rules(partial: true)
     */
    public function update(Incident $incident, array $data, ?User $actor): Incident
    {
        if ($incident->status->isOver()) {
            throw new PublicApiException(__('incidents.errors.over'), 'incident_over', 409);
        }

        $tenant = $incident->tenant;
        $connections = array_key_exists('connection_ids', $data)
            ? $this->connectionsFor($tenant, (array) $data['connection_ids'], $actor)
            : $incident->connections()->get();

        $attributes = $this->contentAttributes($data, $incident, (int) $incident->tenant_id);

        $scheduled = $incident->status === IncidentStatus::Scheduled;
        $start = $scheduled
            ? ($this->parseTime($data['starts_at'] ?? null) ?? $incident->starts_at)
            : ($incident->activated_at ?? now());

        if ($scheduled && $start->isPast()) {
            $start = now();
        }

        if (! empty($data['ends_at']) || ! empty($data['duration_minutes'])) {
            $endsAt = $this->endFor($start, $data);
        } elseif ($scheduled && ! $start->equalTo($incident->starts_at)) {
            // Moving a scheduled one keeps its length: "tomorrow at 2, for
            // four hours" moved to 3 is still four hours.
            $endsAt = $this->endFor($start, ['duration_minutes' => max(
                Incident::MIN_DURATION_MINUTES,
                (int) round($incident->starts_at->diffInMinutes($incident->ends_at)),
            )]);
        } else {
            $endsAt = $incident->ends_at;
        }

        $this->locked($tenant, function () use ($incident, $tenant, $connections, $attributes, $start, $endsAt) {
            $this->assertNoOverlap($tenant, $connections, $start, $endsAt, $incident->id);

            $incident->fill($attributes);
            $incident->ends_at = $endsAt;

            if ($incident->status === IncidentStatus::Scheduled) {
                $incident->starts_at = $start;
            }

            if ($incident->isDirty('ends_at')) {
                $incident->reminder_sent_at = null;
            }

            $incident->save();
            $incident->connections()->sync($connections->pluck('id')->all());
        });

        IncidentEvents::changed($incident);

        return $incident->fresh(['connections']);
    }

    /** More time for one that is running (or scheduled), from whichever is later: its end or now. */
    public function extend(Incident $incident, int $minutes): Incident
    {
        if ($incident->status->isOver()) {
            throw new PublicApiException(__('incidents.errors.over'), 'incident_over', 409);
        }

        $from = $incident->ends_at->isFuture() ? $incident->ends_at->copy() : now();
        $start = $incident->starts_at ?? $incident->activated_at ?? now();
        $endsAt = $from->addMinutes($minutes);

        // The cap is on how long it runs, counted from when it started, not
        // from now: extending a 70-hour incident by another 72 would be a
        // three-day incident by a different name.
        $cap = $start->copy()->addMinutes(Incident::MAX_DURATION_MINUTES);

        if ($endsAt->greaterThan($cap)) {
            $endsAt = $cap;
        }

        if (! $endsAt->greaterThan($incident->ends_at)) {
            throw new PublicApiException(__('incidents.errors.max_duration'), 'incident_max_duration');
        }

        $this->locked($incident->tenant, function () use ($incident, $start, $endsAt) {
            $this->assertNoOverlap($incident->tenant, $incident->connections()->get(), $start, $endsAt, $incident->id);

            $incident->forceFill(['ends_at' => $endsAt, 'reminder_sent_at' => null])->save();
        });

        IncidentEvents::changed($incident);

        return $incident->fresh(['connections']);
    }

    /**
     * It is fixed. Stops answering at once and, when asked to, tells everyone
     * who was told it was broken — paced per connection, like a campaign.
     */
    public function resolve(Incident $incident, ?User $actor, bool $sendResolution, ?string $message = null, ?ResolutionAudience $audience = null): Incident
    {
        if ($incident->status !== IncidentStatus::Active) {
            throw new PublicApiException(__('incidents.errors.not_active'), 'incident_not_active', 409);
        }

        $message = $message !== null ? trim($message) : null;

        if ($message !== null && $message !== '') {
            $incident->resolution_message = $message;
        }

        if ($audience) {
            $incident->resolution_audience = $audience;
        }

        if ($sendResolution && trim((string) $incident->resolution_message) === '') {
            throw ValidationException::withMessages(['resolution_message' => __('incidents.errors.resolution_required')]);
        }

        $incident->forceFill([
            'status' => IncidentStatus::Resolved,
            'resolved_at' => now(),
            'resolved_by' => $actor?->id,
        ])->save();

        $this->dropUnsentNotices($incident);

        if ($sendResolution) {
            $this->seedResolution($incident);
        }

        Log::info('Incident resolved', [
            'tenant_id' => $incident->tenant_id,
            'incident_id' => $incident->id,
            'actor_id' => $actor?->id,
            'send_resolution' => $sendResolution,
        ]);

        IncidentEvents::changed($incident);

        return $incident->fresh(['connections']);
    }

    /** A scheduled one that is no longer needed. A running one is resolved, not canceled. */
    public function cancel(Incident $incident): Incident
    {
        if ($incident->status !== IncidentStatus::Scheduled) {
            throw new PublicApiException(__('incidents.errors.not_scheduled'), 'incident_not_scheduled', 409);
        }

        $incident->forceFill(['status' => IncidentStatus::Canceled])->save();

        IncidentEvents::changed($incident);

        return $incident->fresh(['connections']);
    }

    /**
     * The clock ran out on one nobody closed. Nothing is said to customers —
     * nobody confirmed it is fixed — and that is the difference from resolve.
     */
    public function expire(Incident $incident): void
    {
        $updated = Incident::whereKey($incident->id)
            ->where('status', IncidentStatus::Active)
            ->update(['status' => IncidentStatus::Expired, 'updated_at' => now()]);

        if ($updated === 0) {
            return;
        }

        $incident->refresh();
        $this->dropUnsentNotices($incident);

        Log::info('Incident expired', ['tenant_id' => $incident->tenant_id, 'incident_id' => $incident->id]);

        IncidentEvents::changed($incident);
    }

    /** A scheduled one whose time has come. False when another got its connections first. */
    public function activate(Incident $incident): bool
    {
        $activated = $this->locked($incident->tenant, function () use ($incident) {
            $incident->refresh();

            if ($incident->status !== IncidentStatus::Scheduled) {
                return false;
            }

            if ($incident->ends_at->isPast()) {
                $incident->forceFill(['status' => IncidentStatus::Expired])->save();

                return false;
            }

            $busy = $this->conflicts($incident->tenant, $incident->connections()->get(), now(), $incident->ends_at, $incident->id)
                ->filter(fn (Incident $other) => $other->status === IncidentStatus::Active);

            if ($busy->isNotEmpty()) {
                Log::warning('Incident not activated: its connections already have a live incident', [
                    'incident_id' => $incident->id,
                    'blocking_incident_ids' => $busy->pluck('id')->all(),
                ]);

                return false;
            }

            $incident->forceFill(['status' => IncidentStatus::Active, 'activated_at' => now()])->save();

            return true;
        });

        if ($activated) {
            $this->afterActivation($incident);
        }

        return $activated;
    }

    /**
     * Send the notice to one conversation by hand — one whose first message
     * did not match, or whose notice failed. Checked like any send the platform
     * starts on its own: closed windows and opted-out contacts are skipped.
     */
    public function sendManually(Incident $incident, IncidentDelivery $delivery): IncidentDelivery
    {
        if (! $incident->isLive()) {
            throw new PublicApiException(__('incidents.errors.not_active'), 'incident_not_active', 409);
        }

        if ($delivery->kind !== DeliveryKind::Notice
            || ! in_array($delivery->status, [DeliveryStatus::NotMatched, DeliveryStatus::Failed], true)) {
            throw new PublicApiException(__('incidents.errors.cannot_send'), 'incident_delivery_not_sendable', 409);
        }

        $delivery->forceFill([
            'status' => DeliveryStatus::Pending,
            'source' => DeliverySource::Manual,
            'reason' => null,
            'error' => null,
        ])->save();

        // Sent here rather than queued: somebody pressed the button and is
        // waiting to see whether it went.
        app(IncidentSender::class)->send($delivery);

        IncidentEvents::changed($incident);

        return $delivery->refresh();
    }

    /**
     * Counts by kind and status, for the incident page and the banner.
     *
     * @return array{notice: array<string, int>, resolution: array<string, int>}
     */
    public function stats(Incident $incident): array
    {
        $empty = array_fill_keys(array_map(fn (DeliveryStatus $s) => $s->value, DeliveryStatus::cases()), 0);
        $stats = ['notice' => $empty, 'resolution' => $empty];

        IncidentDelivery::where('incident_id', $incident->id)
            ->selectRaw('kind, status, COUNT(*) as total')
            ->groupBy('kind', 'status')
            ->get()
            ->each(function ($row) use (&$stats) {
                $kind = $row->kind instanceof DeliveryKind ? $row->kind->value : (string) $row->kind;
                $status = $row->status instanceof DeliveryStatus ? $row->status->value : (string) $row->status;
                $stats[$kind][$status] = (int) $row->total;
            });

        return $stats;
    }

    /**
     * Start the paced sends for an incident that just went live, and tell the
     * people who wrote shortly before it was switched on.
     */
    private function afterActivation(Incident $incident): void
    {
        if ($incident->notify_recent_minutes > 0) {
            try {
                $this->seedRetro($incident);
            } catch (\Throwable $th) {
                // The incident is live and answering whoever writes next; the
                // look back is a courtesy on top, not a reason to fail.
                Log::error('IncidentService: could not reach the recent conversations', [
                    'incident_id' => $incident->id,
                    'error' => $th->getMessage(),
                ]);
            }
        }

        $this->dispatchOutreach($incident, DeliveryKind::Notice);

        IncidentEvents::changed($incident);
    }

    /**
     * The customers who wrote in the last N minutes before the incident was
     * switched on — the ones already waiting when someone noticed. Judged by
     * the same rule as everyone after them: the first message of theirs in
     * that window that counts decides.
     */
    private function seedRetro(Incident $incident): void
    {
        $since = ($incident->activated_at ?? now())->copy()->subMinutes($incident->notify_recent_minutes);
        $connectionIds = $incident->connections()->pluck('connections.id')->all();

        if ($connectionIds === []) {
            return;
        }

        $conversations = Conversation::with('connection')
            ->whereIn('connection_id', $connectionIds)
            ->where('status', '!=', ConversationStatus::Resolved)
            ->where('type', '!=', ConversationType::Group)
            ->whereHas('messages', fn ($query) => $query
                ->where('sender_type', SenderType::Incoming)
                ->where('message_type', '!=', MessageType::Info)
                ->where('created_at', '>=', $since))
            ->orderByDesc('last_message_at')
            ->limit(max(1, (int) config('incidents.retro_limit', 2000)))
            ->get();

        foreach ($conversations as $conversation) {
            if (! IncidentResponder::covers($conversation)
                || (! $incident->reply_in_agent_threads && IncidentResponder::heldByAgent($conversation))
                || IncidentResponder::decided($incident, $conversation)) {
                continue;
            }

            $messages = $conversation->messages()
                ->where('sender_type', SenderType::Incoming)
                ->where('message_type', '!=', MessageType::Info)
                ->where('created_at', '>=', $since)
                ->exceptExternalAutomation()
                ->orderBy('id')
                ->get();

            foreach ($messages as $message) {
                $verdict = IncidentMatcher::judgeMessage($incident, $message);

                if (! $verdict->counted()) {
                    continue;
                }

                IncidentResponder::claim($incident, $conversation, $message, $verdict, DeliverySource::Retro);
                break;
            }
        }
    }

    /** One "it is fixed" row for everyone who was told, and the pumps to send them. */
    private function seedResolution(Incident $incident): void
    {
        $now = now();

        IncidentDelivery::where('incident_id', $incident->id)
            ->where('kind', DeliveryKind::Notice)
            ->where('status', DeliveryStatus::Sent)
            ->when($incident->resolution_audience === ResolutionAudience::Open, fn ($query) => $query
                ->whereHas('conversation', fn ($q) => $q->where('status', '!=', ConversationStatus::Resolved)))
            ->select(['conversation_id', 'connection_id'])
            ->orderBy('id')
            ->chunk(500, function (Collection $rows) use ($incident, $now) {
                IncidentDelivery::query()->insertOrIgnore($rows->map(fn ($row) => [
                    'incident_id' => $incident->id,
                    'conversation_id' => $row->conversation_id,
                    'connection_id' => $row->connection_id,
                    'kind' => DeliveryKind::Resolution->value,
                    'status' => DeliveryStatus::Pending->value,
                    'source' => DeliverySource::Resolution->value,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all());
            });

        $this->dispatchOutreach($incident, DeliveryKind::Resolution);
    }

    /** Notices the platform was going to send on its own, now that nobody needs them. */
    private function dropUnsentNotices(Incident $incident): void
    {
        IncidentDelivery::where('incident_id', $incident->id)
            ->where('kind', DeliveryKind::Notice)
            ->where('status', DeliveryStatus::Pending)
            ->where('source', '!=', DeliverySource::Reactive)
            ->update([
                'status' => DeliveryStatus::Skipped,
                'reason' => IncidentDelivery::REASON_INCIDENT_OVER,
                'updated_at' => now(),
            ]);
    }

    /** One pump per connection that has something waiting. */
    public function dispatchOutreach(Incident $incident, DeliveryKind $kind): void
    {
        IncidentDelivery::where('incident_id', $incident->id)
            ->where('kind', $kind)
            ->where('status', DeliveryStatus::Pending)
            ->whereNotNull('connection_id')
            ->distinct()
            ->pluck('connection_id')
            ->each(fn ($connectionId) => RunIncidentOutreach::dispatch($incident->id, (int) $connectionId, $kind->value));
    }

    /**
     * The words, the matching and the options out of a validated payload —
     * with what was not sent kept from $existing.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function contentAttributes(array $data, ?Incident $existing, int $tenantId): array
    {
        $pick = fn (string $key, mixed $default) => array_key_exists($key, $data) ? $data[$key] : ($existing?->{$key} ?? $default);

        $mode = $pick('match_mode', MatchMode::All);
        $mode = $mode instanceof MatchMode ? $mode : MatchMode::from((string) $mode);

        $keywords = $this->keywordsFrom($pick('keywords', []), 'keywords');
        $exclude = $this->keywordsFrom($pick('exclude_keywords', []), 'exclude_keywords');

        if ($mode === MatchMode::Keywords && $keywords === []) {
            throw ValidationException::withMessages(['keywords' => __('incidents.errors.keywords_required')]);
        }

        $mediaUrl = trim((string) $pick('media_url', '')) ?: null;
        $mediaType = $mediaUrl ? ((string) $pick('media_type', '') ?: 'image') : null;

        $audience = $pick('resolution_audience', ResolutionAudience::All);
        $audience = $audience instanceof ResolutionAudience ? $audience : ResolutionAudience::from((string) $audience);

        $replyMode = $pick('reply_mode', ReplyMode::Fixed);
        $replyMode = $replyMode instanceof ReplyMode ? $replyMode : ReplyMode::from((string) $replyMode);
        $brief = trim((string) $pick('ai_brief', '')) ?: null;
        $agentId = $pick('ai_hub_agent_id', null);
        $agentId = $agentId !== null && $agentId !== '' ? (int) $agentId : null;

        if ($replyMode === ReplyMode::Ai) {
            if ($agentId === null || ! $this->agentBelongs($tenantId, $agentId)) {
                throw ValidationException::withMessages(['ai_hub_agent_id' => __('incidents.errors.ai_agent_required')]);
            }

            if ($brief === null) {
                throw ValidationException::withMessages(['ai_brief' => __('incidents.errors.ai_brief_required')]);
            }
        } elseif ($agentId !== null && ! $this->agentBelongs($tenantId, $agentId)) {
            $agentId = null;
        }

        return [
            'title' => trim((string) $pick('title', '')),
            'match_mode' => $mode,
            'reply_mode' => $replyMode,
            'ai_hub_agent_id' => $replyMode === ReplyMode::Ai ? $agentId : null,
            'ai_brief' => $brief,
            'keywords' => $keywords ?: null,
            'exclude_keywords' => $exclude ?: null,
            'message' => trim((string) $pick('message', '')),
            'media_url' => $mediaUrl,
            'media_type' => $mediaType,
            'reply_in_agent_threads' => (bool) $pick('reply_in_agent_threads', false),
            'continue_flow' => (bool) $pick('continue_flow', false),
            'notify_recent_minutes' => (int) $pick('notify_recent_minutes', 0),
            'resolution_message' => trim((string) $pick('resolution_message', '')) ?: null,
            'resolution_audience' => $audience,
        ];
    }

    /** An active AI agent of this workspace — never one from another. */
    private function agentBelongs(int $tenantId, int $agentId): bool
    {
        return AiHubAgent::whereKey($agentId)
            ->where('status', 'ACTIVE')
            ->whereHas('aiHubTenant', fn ($query) => $query->where('tenant_id', $tenantId))
            ->exists();
    }

    /** @return array<int, string> */
    private function keywordsFrom(mixed $raw, string $field): array
    {
        $keywords = IncidentMatcher::cleanKeywords(is_array($raw) ? $raw : []);

        foreach ($keywords as $keyword) {
            if (! IncidentMatcher::isUsableKeyword($keyword)) {
                throw ValidationException::withMessages([$field => __('incidents.errors.keyword_too_short', ['keyword' => $keyword])]);
            }
        }

        return $keywords;
    }

    /**
     * The connections named, held to this workspace, to ones that can answer
     * (e-mail is a shared inbox, not a chat), and to the ones the person can
     * reach — an attendant with one inbox does not get to answer the others.
     *
     * @param  array<int, mixed>  $ids
     * @return Collection<int, Connection>
     */
    private function connectionsFor(Tenant $tenant, array $ids, ?User $actor): Collection
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));

        $connections = Connection::where('tenant_id', $tenant->id)->whereIn('id', $ids)->get();

        if ($connections->count() !== count($ids)) {
            throw ValidationException::withMessages(['connection_ids' => __('incidents.errors.connection_unknown')]);
        }

        if ($connections->contains(fn (Connection $c) => $c->channel === Channel::Email)) {
            throw ValidationException::withMessages(['connection_ids' => __('incidents.errors.connection_email')]);
        }

        if ($actor && ! $actor->canAccessAllConnections()
            && $connections->contains(fn (Connection $c) => ! $actor->canAccessConnection($c))) {
            throw ValidationException::withMessages(['connection_ids' => __('incidents.errors.connection_unknown')]);
        }

        return $connections;
    }

    /** @param  array<string, mixed>  $data */
    private function endFor(CarbonInterface $start, array $data): Carbon
    {
        $endsAt = $this->parseTime($data['ends_at'] ?? null);

        if ($endsAt === null) {
            $minutes = (int) ($data['duration_minutes'] ?? 0) ?: Incident::DEFAULT_DURATION_MINUTES;
            $endsAt = Carbon::instance($start)->copy()->addMinutes($minutes);
        }

        $length = Carbon::instance($start)->diffInMinutes($endsAt, false);

        if ($endsAt->lessThanOrEqualTo(now()->addMinute())) {
            throw ValidationException::withMessages(['ends_at' => __('incidents.errors.ends_in_past')]);
        }

        if ($length < Incident::MIN_DURATION_MINUTES) {
            throw ValidationException::withMessages(['ends_at' => __('incidents.errors.min_duration')]);
        }

        if ($length > Incident::MAX_DURATION_MINUTES) {
            throw ValidationException::withMessages(['ends_at' => __('incidents.errors.max_duration')]);
        }

        return $endsAt;
    }

    private function parseTime(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof CarbonInterface) {
            return Carbon::instance($value);
        }

        try {
            return Carbon::parse((string) $value);
        } catch (\Throwable) {
            throw ValidationException::withMessages(['starts_at' => __('incidents.errors.bad_time')]);
        }
    }

    /**
     * @param  Collection<int, Connection>  $connections
     */
    private function assertNoOverlap(Tenant $tenant, Collection $connections, CarbonInterface $start, CarbonInterface $end, ?int $ignoreId): void
    {
        $conflict = $this->conflicts($tenant, $connections, $start, $end, $ignoreId)->first();

        if (! $conflict) {
            return;
        }

        $shared = $conflict->connections->pluck('id')->intersect($connections->pluck('id'));
        $names = $connections->whereIn('id', $shared->all())->pluck('name')->implode(', ');

        throw new PublicApiException(
            __('incidents.errors.overlap', ['title' => $conflict->title, 'connections' => $names]),
            'incident_overlap',
            422,
            ['incident_id' => $conflict->id],
        );
    }

    /**
     * Open incidents sharing a connection with these and overlapping this time.
     *
     * @param  Collection<int, Connection>  $connections
     * @return Collection<int, Incident>
     */
    private function conflicts(Tenant $tenant, Collection $connections, CarbonInterface $start, CarbonInterface $end, ?int $ignoreId): Collection
    {
        $ids = $connections->pluck('id')->all();

        return Incident::with('connections:id')
            ->where('tenant_id', $tenant->id)
            ->open()
            ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
            ->whereHas('connections', fn ($query) => $query->whereIn('connections.id', $ids))
            ->get()
            ->filter(function (Incident $other) use ($start, $end) {
                $otherStart = $other->starts_at ?? $other->activated_at ?? $other->created_at;

                return $otherStart->lessThan($end) && $start->lessThan($other->ends_at);
            })
            ->values();
    }

    /**
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    private function locked(Tenant $tenant, callable $work): mixed
    {
        return Cache::lock("incidents:tenant:{$tenant->id}", 15)->block(10, fn () => DB::transaction($work));
    }
}

<?php

namespace App\Http\Controllers\Api\Crm;

use App\Http\Controllers\Controller;
use App\Http\Resources\Crm\CustomerResource;
use App\Http\Resources\TagResource;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\CrmActivity;
use App\Models\Customer;
use App\Models\CustomerRelationship;
use App\Models\User;
use App\Services\Crm\CustomerFilter;
use App\Services\Crm\CustomerResolver;
use App\Services\Crm\CustomerService;
use App\Services\Crm\CustomFields;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The CRM's people: the list, the customer page (Customer 360) and every
 * change made from either, plus the lookup the inbox panel uses.
 *
 * Conversations are always read through Conversation::visibleTo() — a
 * customer page is not a way to read threads the inbox would not show.
 */
class CustomerController extends Controller
{
    public function __construct(
        private readonly CustomerService $customers,
        private readonly CustomerResolver $resolver,
    ) {}

    public function index(Request $request)
    {
        $data = $request->validate([
            ...CustomerFilter::listRules(),
            'sort' => ['nullable', Rule::in(['recent', 'name', 'created'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $user = $request->user();
        $query = CustomerFilter::applyList($this->listQuery($user), $data, $user);

        match ($data['sort'] ?? 'recent') {
            'name' => $query->orderBy('customers.name'),
            'created' => $query->orderByDesc('customers.id'),
            // People who wrote most recently first; never-contacted ones last.
            default => $query->orderByRaw('last_interaction_at IS NULL')->orderByDesc('last_interaction_at')->orderByDesc('customers.id'),
        };

        return CustomerResource::collection($query->paginate($data['per_page'] ?? 50));
    }

    public function show(Request $request, string $id)
    {
        $customer = $this->visible($request, $id);
        $customer->load(['contacts.tags', 'company', 'owner', 'relationships.related']);
        $customer->loadMax('conversations as last_interaction_at', 'last_message_at');

        $user = $request->user();
        $contactIds = $customer->contacts->pluck('id');

        // Per identity: how many of its conversations this viewer can open,
        // and the latest of them — the "go to the conversation" link.
        $conversations = Conversation::query()
            ->visibleTo($user)
            ->whereIn('contact_id', $contactIds)
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->get(['id', 'contact_id', 'status', 'last_message_at']);

        return response()->json([
            'data' => (new CustomerResource($customer))->resolve(),
            'identities' => $customer->contacts->map(function (Contact $contact) use ($conversations) {
                $mine = $conversations->where('contact_id', $contact->id);

                return [
                    'id' => $contact->id,
                    'channel' => $contact->channel?->value,
                    'name' => $contact->name,
                    'username' => $contact->username,
                    'external_id' => $contact->external_id,
                    'photo_profile_url' => $contact->photo_profile_url,
                    'broadcast_opted_out' => $contact->broadcast_opted_out_at !== null,
                    'tags' => TagResource::collection($contact->tags),
                    'conversations_count' => $mine->count(),
                    'latest_conversation_id' => $mine->first()?->id,
                ];
            })->values(),
            'relationships' => $customer->relationships
                ->filter(fn (CustomerRelationship $row) => $row->related !== null)
                ->map(fn (CustomerRelationship $row) => [
                    'customer_id' => $row->related->id,
                    'name' => $row->related->name,
                    'status' => $row->related->status?->value,
                    'label' => $row->label,
                ])->values(),
            'custom_field_definitions' => CustomFields::definitions($customer->tenant_id, 'customer')->map->only(['key', 'label', 'type', 'options'])->values(),
            'counts' => [
                'conversations' => $conversations->count(),
                'open_conversations' => $conversations->filter(fn (Conversation $c) => in_array($c->status?->value, ['pending', 'active', 'ai_handling'], true))->count(),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        $data = $this->customers->validate($user->tenant_id, $request->all(), creating: true);

        $customer = $this->customers->create($user, $data);

        return (new CustomerResource($customer->load(['company', 'owner'])))->response()->setStatusCode(201);
    }

    public function update(Request $request, string $id)
    {
        $customer = $this->visible($request, $id);
        $data = $this->customers->validate($customer->tenant_id, $request->all(), creating: false);

        $customer = $this->customers->update($customer, $request->user(), $data);

        return new CustomerResource($customer->load(['contacts.tags', 'company', 'owner']));
    }

    public function destroy(Request $request, string $id)
    {
        $this->customers->delete($this->visible($request, $id));

        return response()->json(['message' => __('crm.customers.deleted')]);
    }

    public function syncTags(Request $request, string $id)
    {
        $customer = $this->visible($request, $id);

        $data = $request->validate([
            'tags' => ['present', 'array'],
            'tags.*' => ['integer'],
        ]);

        return response()->json([
            'data' => TagResource::collection($this->customers->syncTags($customer, $request->user(), $data['tags'])),
        ]);
    }

    /** Make a channel contact one of this customer's identities. */
    public function linkContact(Request $request, string $id)
    {
        $customer = $this->visible($request, $id);

        $data = $request->validate(['contact_id' => ['required', 'integer']]);
        $contact = Contact::where('tenant_id', $customer->tenant_id)->findOrFail($data['contact_id']);

        $this->customers->linkContact($customer, $contact, $request->user());

        return response()->json(['message' => __('crm.customers.linked')]);
    }

    /** Separate one identity: it becomes a customer of its own. */
    public function unlinkContact(Request $request, string $id, string $contactId)
    {
        $customer = $this->visible($request, $id);
        $contact = Contact::where('tenant_id', $customer->tenant_id)->findOrFail($contactId);

        $separate = $this->customers->unlinkContact($customer, $contact, $request->user());

        return response()->json([
            'message' => __('crm.customers.unlinked'),
            'customer_id' => $separate->id,
        ]);
    }

    /** Fold another customer into this one. */
    public function merge(Request $request, string $id)
    {
        $customer = $this->visible($request, $id);

        $data = $request->validate(['source_id' => ['required', 'integer']]);
        $source = $this->visible($request, (string) $data['source_id']);

        $this->customers->merge($customer, $source, $request->user());

        return response()->json(['message' => __('crm.customers.merged')]);
    }

    public function duplicates(Request $request, string $id)
    {
        $customer = $this->visible($request, $id);

        return response()->json([
            'data' => $this->customers->duplicates($customer, $request->user())->map(fn (Customer $match) => [
                'id' => $match->id,
                'name' => $match->name,
                'phone' => $match->phone,
                'email' => $match->email,
                'company' => $match->company?->name,
                'channels' => $match->contacts->pluck('channel')->map(fn ($c) => $c?->value)->filter()->unique()->values(),
                'reasons' => array_values(array_filter([
                    $customer->phone && $match->phone === $customer->phone ? 'phone' : null,
                    $customer->email && $match->email === $customer->email ? 'email' : null,
                    mb_strtolower(trim($match->name)) === mb_strtolower(trim($customer->name)) ? 'name' : null,
                ])),
            ])->values(),
        ]);
    }

    /** Every conversation of every identity, newest first — the ones this viewer may open. */
    public function conversations(Request $request, string $id)
    {
        $customer = $this->visible($request, $id);
        $user = $request->user();

        $page = Conversation::query()
            ->visibleTo($user)
            ->whereIn('contact_id', $customer->contacts()->select('id'))
            ->with(['connection:id,name,channel,color', 'contact:id,channel,name', 'agent:id,name', 'lastMessage'])
            ->orderByRaw('last_message_at IS NULL')
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->paginate((int) $request->integer('per_page', 20));

        return response()->json([
            'data' => collect($page->items())->map(function (Conversation $conversation) use ($user) {
                $connection = $conversation->getRelationValue('connection');
                $last = $conversation->getRelationValue('lastMessage');

                return [
                    'id' => $conversation->id,
                    'status' => $conversation->status?->value,
                    'channel' => $connection?->channel?->value ?? $conversation->contact?->channel?->value,
                    'connection' => $connection ? ['id' => (string) $connection->id, 'name' => $connection->name, 'color' => $connection->color] : null,
                    'assignee' => $conversation->agent ? ['id' => $conversation->agent->id, 'name' => $conversation->agent->name] : null,
                    // The last thing said — unless the thread is exclusive to
                    // somebody else, where even a preview is content.
                    'preview' => $last && $conversation->isReadableBy($user) ? Str::limit((string) $last->body, 140) : null,
                    'last_message_at' => $conversation->last_message_at?->toIso8601String(),
                    'created_at' => $conversation->created_at?->toIso8601String(),
                    'resolved_at' => $conversation->resolved_at?->toIso8601String(),
                ];
            }),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    /**
     * The customer's timeline, newest first: what was done in the CRM, and the
     * conversations they opened and were resolved — read from the
     * conversations themselves, not copied, so the history of every thread
     * before the CRM existed is on it too.
     */
    public function timeline(Request $request, string $id)
    {
        $customer = $this->visible($request, $id);
        $user = $request->user();
        $limit = 30;

        $before = $request->filled('before') ? Carbon::parse((string) $request->query('before')) : now()->addMinute();

        // Everything about the person: their own entries and those on their
        // leads and deals (crm_activities.customer_id).
        $activities = CrmActivity::query()
            ->where('customer_id', $customer->id)
            ->where('occurred_at', '<', $before)
            ->with('user:id,name')
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (CrmActivity $activity) => [
                'id' => 'a-'.$activity->id,
                'type' => $activity->type,
                'occurred_at' => $activity->occurred_at->toIso8601String(),
                'user' => $activity->user ? ['id' => $activity->user->id, 'name' => $activity->user->name] : null,
                'conversation_id' => $activity->conversation_id,
                'data' => $activity->data ?? (object) [],
                'body' => $activity->body,
                'subject_type' => $activity->subject_type,
                'subject_id' => $activity->subject_id,
            ]);

        $threads = Conversation::query()
            ->visibleTo($user)
            ->whereIn('contact_id', $customer->contacts()->select('id'))
            ->where(fn (Builder $q) => $q->where('created_at', '<', $before)->orWhere('resolved_at', '<', $before))
            ->with('connection:id,name,channel')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();

        $events = collect();

        foreach ($threads as $thread) {
            $connection = $thread->getRelationValue('connection');
            $data = [
                'conversation_id' => $thread->id,
                'channel' => $connection?->channel?->value,
                'connection_name' => $connection?->name,
            ];

            if ($thread->created_at && $thread->created_at->lt($before)) {
                $events->push(['id' => 'c-'.$thread->id.'-started', 'type' => 'conversation_started', 'occurred_at' => $thread->created_at->toIso8601String(), 'user' => null, 'conversation_id' => $thread->id, 'data' => $data]);
            }

            if ($thread->resolved_at && $thread->resolved_at->lt($before)) {
                $events->push(['id' => 'c-'.$thread->id.'-resolved', 'type' => 'conversation_resolved', 'occurred_at' => $thread->resolved_at->toIso8601String(), 'user' => null, 'conversation_id' => $thread->id, 'data' => $data]);
            }
        }

        $entries = $activities->concat($events)
            ->sortByDesc('occurred_at')
            ->values()
            ->take($limit);

        return response()->json([
            'data' => $entries->values(),
            'next_before' => $entries->count() === $limit ? $entries->last()['occurred_at'] : null,
        ]);
    }

    public function addRelationship(Request $request, string $id)
    {
        $customer = $this->visible($request, $id);

        $data = $request->validate([
            'related_customer_id' => ['required', 'integer'],
            'label' => ['nullable', 'string', 'max:60'],
        ]);

        $related = $this->visible($request, (string) $data['related_customer_id']);
        $this->customers->addRelationship($customer, $related, $request->user(), $data['label'] ?? null);

        return response()->json(['message' => __('crm.customers.relationship_added')], 201);
    }

    public function removeRelationship(Request $request, string $id, string $relatedId)
    {
        $customer = $this->visible($request, $id);
        $related = Customer::where('tenant_id', $customer->tenant_id)->findOrFail($relatedId);

        $this->customers->removeRelationship($customer, $related, $request->user());

        return response()->json(['message' => __('crm.customers.relationship_removed')]);
    }

    /**
     * The customer behind a channel contact, for the panel beside a thread —
     * linked on the spot when the sweep has not reached it yet.
     *
     * Authorised by the conversation, not by the customer list: someone who
     * can open this person's thread can see who they are.
     */
    public function forContact(Request $request, string $contactId)
    {
        $user = $request->user();
        $contact = Contact::where('tenant_id', $user->tenant_id)->where('is_group', false)->findOrFail($contactId);

        $canSee = Conversation::query()->visibleTo($user)->where('contact_id', $contact->id)->exists()
            || Customer::query()->visibleTo($user)->whereKey($contact->customer_id)->exists();

        if (! $canSee) {
            throw new HttpException(403, __('crm.customers.no_access'));
        }

        $customer = $this->resolver->attach($contact);

        if (! $customer) {
            throw new HttpException(422, __('crm.customers.not_linked'));
        }

        $customer->load(['contacts.tags', 'company', 'owner']);
        $customer->loadMax('conversations as last_interaction_at', 'last_message_at');

        return response()->json([
            'data' => (new CustomerResource($customer))->resolve(),
            'identities' => $customer->contacts->map(fn (Contact $each) => [
                'id' => $each->id,
                'channel' => $each->channel?->value,
                'name' => $each->name,
                'username' => $each->username,
                'external_id' => $each->external_id,
                'latest_conversation_id' => Conversation::query()->visibleTo($user)->where('contact_id', $each->id)->orderByDesc('last_message_at')->value('id'),
            ])->values(),
        ]);
    }

    /** Who may be put in charge of a customer: the workspace's people. */
    public function owners(Request $request)
    {
        return response()->json([
            'data' => User::query()
                ->where('tenant_id', $request->user()->tenant_id)
                ->orderBy('name')
                ->get(['id', 'name', 'avatar_path'])
                ->map(fn (User $user) => ['id' => $user->id, 'name' => $user->name, 'avatar' => $user->avatar_url])
                ->values(),
        ]);
    }

    /** The list's base: what this viewer may see, with what a row draws. */
    private function listQuery(User $user): Builder
    {
        return Customer::query()
            ->visibleTo($user)
            ->with(['contacts:id,customer_id,channel,photo_profile', 'contacts.tags', 'company:id,name', 'owner:id,name,avatar_path'])
            ->withMax('conversations as last_interaction_at', 'last_message_at');
    }

    private function visible(Request $request, string $id): Customer
    {
        $customer = Customer::query()->visibleTo($request->user())->find($id);

        if (! $customer) {
            throw new HttpException(404, __('crm.customers.not_found'));
        }

        return $customer;
    }
}

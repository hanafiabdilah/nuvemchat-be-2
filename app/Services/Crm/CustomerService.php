<?php

namespace App\Services\Crm;

use App\Enums\Broadcast\AddressType;
use App\Enums\Crm\CustomerStatus;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\CustomerRelationship;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Contact\ContactTags;
use App\Support\PhoneVariants;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Everything people do to a customer: create, edit, link and unlink channel
 * contacts, merge (leads, deals and tasks included), delete — each written to
 * the timeline.
 *
 * The rule that keeps the inbox and the CRM from disagreeing: the customer is
 * the person, so what is true of the person is written to every channel
 * contact they own. A name set here becomes every linked contact's (locked)
 * name, and tags are the same on all of them (ContactTags fans a change out to
 * the siblings) — whichever screen someone edits from.
 */
class CustomerService
{
    /** The fields the timeline records changes to. */
    private const TRACKED = ['name', 'email', 'phone', 'company_id', 'job_title', 'owner_user_id', 'status', 'source'];

    public function __construct(
        private readonly CustomerResolver $resolver,
        private readonly ContactTags $contactTags,
    ) {}

    /**
     * The validated customer fields of a create or edit request.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function validate(int $tenantId, array $input, bool $creating): array
    {
        $sometimes = $creating ? [] : ['sometimes'];

        $data = validator($input, [
            'name' => [...$sometimes, 'required', 'string', 'max:255'],
            'email' => [...$sometimes, 'nullable', 'email', 'max:255'],
            'phone' => [...$sometimes, 'nullable', 'string', 'max:32'],
            'job_title' => [...$sometimes, 'nullable', 'string', 'max:255'],
            'status' => [...$sometimes, 'nullable', Rule::in(CustomerStatus::values())],
            'source' => [...$sometimes, 'nullable', 'string', 'max:40'],
            'company_id' => [...$sometimes, 'nullable', 'integer', Rule::exists('companies', 'id')->where('tenant_id', $tenantId)],
            'owner_user_id' => [...$sometimes, 'nullable', 'integer', Rule::exists('users', 'id')->where('tenant_id', $tenantId)],
            'custom_fields' => [...$sometimes, 'nullable', 'array'],
        ])->validate();

        if (array_key_exists('phone', $data) && filled($data['phone'])) {
            $phone = self::canonicalPhone((string) $data['phone'], Tenant::find($tenantId)?->market?->phone_country);

            if (! AddressType::Phone->isValid($phone)) {
                throw ValidationException::withMessages(['phone' => __('crm.customers.invalid_phone')]);
            }

            $data['phone'] = $phone;
        }

        if (array_key_exists('email', $data) && filled($data['email'])) {
            $data['email'] = AddressType::Email->normalize((string) $data['email']);
        }

        return $data;
    }

    /**
     * A typed phone number in the shape a WhatsApp contact carries: digits,
     * country code first. `+` and `00` mean it is already international; a
     * trunk zero (0812…) or a national number short enough to have no country
     * code (11 98765-4321) is read in the workspace's country — the market's
     * calling code — which is how people write their own country's numbers.
     */
    public static function canonicalPhone(string $typed, ?string $callingCode = null): string
    {
        $typed = trim($typed);
        $digits = AddressType::Phone->normalize($typed);
        $callingCode = $callingCode !== null ? preg_replace('/\D/', '', $callingCode) : '';

        if (str_starts_with($typed, '+')) {
            return $digits;
        }

        if (str_starts_with($digits, '00')) {
            return substr($digits, 2);
        }

        if ($callingCode === '') {
            return $digits;
        }

        if (str_starts_with($digits, '0')) {
            return $callingCode.ltrim($digits, '0');
        }

        // No country code fits in 11 digits with a full national number after
        // it (a Brazilian mobile is 13 with its 55), so anything shorter is a
        // national number.
        return strlen($digits) <= 11 ? $callingCode.$digits : $digits;
    }

    /** @param  array<string, mixed>  $data  validated */
    public function create(User $actor, array $data): Customer
    {
        $customer = DB::transaction(function () use ($actor, $data) {
            $customer = Customer::create([
                'tenant_id' => $actor->tenant_id,
                'name' => $data['name'],
                'email' => $data['email'] ?? null,
                'phone' => $data['phone'] ?? null,
                'job_title' => $data['job_title'] ?? null,
                'company_id' => $data['company_id'] ?? null,
                'owner_user_id' => $data['owner_user_id'] ?? null,
                'status' => $data['status'] ?? CustomerStatus::New->value,
                'source' => $data['source'] ?? 'manual',
                'custom_fields' => CustomFields::apply($actor->tenant_id, 'customer', [], $data['custom_fields'] ?? []) ?: null,
            ]);

            CrmTimeline::record($customer, CrmTimeline::CREATED, $actor, ['source' => $customer->source]);

            return $customer;
        });

        return $customer;
    }

    /** @param  array<string, mixed>  $data  validated */
    public function update(Customer $customer, ?User $actor, array $data): Customer
    {
        $before = $this->snapshot($customer);

        if (array_key_exists('custom_fields', $data)) {
            $data['custom_fields'] = CustomFields::apply(
                $customer->tenant_id,
                'customer',
                $customer->custom_fields ?? [],
                $data['custom_fields'] ?? [],
            ) ?: null;
        }

        $customer->fill($data)->save();

        $changes = CrmTimeline::diff($before, $this->snapshot($customer));

        if (isset($changes['company_id'])) {
            $changes['company_id']['from_name'] = Company::find($changes['company_id']['from'])?->name;
            $changes['company_id']['to_name'] = $customer->company?->name;
        }

        if (isset($changes['owner_user_id'])) {
            $changes['owner_user_id']['from_name'] = User::find($changes['owner_user_id']['from'])?->name;
            $changes['owner_user_id']['to_name'] = $customer->owner?->name;
        }

        if ($changes !== []) {
            CrmTimeline::record($customer, CrmTimeline::UPDATED, $actor, ['changes' => $changes]);
        }

        if (isset($changes['name'])) {
            $this->applyNameToContacts($customer);
        }

        // What the person is (status, e-mail, company…) feeds their leads' scores.
        if ($changes !== []) {
            LeadScoring::rescore($customer->tenant_id, $customer->id);
        }

        return $customer->fresh(['company', 'owner']);
    }

    /**
     * A contact renamed from the inbox renames the person: the customer and
     * every other channel contact they own.
     */
    public function renameFromContact(Contact $contact, User $actor): void
    {
        $customer = $this->resolver->attach($contact);

        if (! $customer || $customer->name === $contact->name) {
            return;
        }

        $this->update($customer, $actor, ['name' => $contact->name]);
    }

    /**
     * Tags for the person: the same set on every channel contact they own.
     * Written through ContactTags, which also tells the inbox.
     *
     * @param  array<int, mixed>  $tagIds
     */
    public function syncTags(Customer $customer, ?User $actor, array $tagIds): Collection
    {
        $contact = $customer->contacts()->orderBy('id')->first();

        if (! $contact) {
            throw ValidationException::withMessages(['tags' => __('crm.customers.tags_need_contact')]);
        }

        $before = $this->tagNames($customer);
        $tags = $this->contactTags->sync($contact, $tagIds);
        $after = $tags->pluck('name')->sort()->values()->all();

        if ($before !== $after) {
            CrmTimeline::record($customer, CrmTimeline::TAGS_CHANGED, $actor, ['from' => $before, 'to' => $after]);
        }

        return $tags;
    }

    /**
     * Make a channel contact one of this customer's identities.
     *
     * If that leaves the customer it came from with no identities at all, the
     * two were the same person all along, so the old one is merged in rather
     * than left behind as an empty card.
     */
    public function linkContact(Customer $target, Contact $contact, User $actor): Customer
    {
        if ($contact->is_group) {
            throw ValidationException::withMessages(['contact_id' => __('crm.customers.group_cannot_link')]);
        }

        if ($contact->customer_id === $target->id) {
            return $target;
        }

        $previous = $contact->customer;

        DB::transaction(function () use ($target, $contact, $previous, $actor) {
            Contact::query()->whereKey($contact->id)->update(['customer_id' => $target->id]);

            CrmTimeline::record($target, CrmTimeline::CONTACT_LINKED, $actor, $this->contactData($contact));

            if ($previous && ! $previous->contacts()->exists()) {
                $this->absorb($target, $previous, $actor);
            } elseif ($previous) {
                CrmTimeline::record($previous, CrmTimeline::CONTACT_UNLINKED, $actor, $this->contactData($contact) + [
                    'moved_to' => $target->name,
                ]);
            }
        });

        $this->unifyTags($target);

        return $target->fresh();
    }

    /**
     * Separate one identity from the customer: it becomes a customer of its
     * own. Refused for the last identity — the customer would be left empty,
     * which is a delete wearing a different name.
     */
    public function unlinkContact(Customer $customer, Contact $contact, User $actor): Customer
    {
        if ($contact->customer_id !== $customer->id) {
            throw new HttpException(404, __('crm.customers.contact_not_linked'));
        }

        if ($customer->contacts()->count() < 2) {
            throw ValidationException::withMessages(['contact_id' => __('crm.customers.last_contact')]);
        }

        return DB::transaction(function () use ($customer, $contact, $actor) {
            Contact::query()->whereKey($contact->id)->update(['customer_id' => null]);
            $contact->customer_id = null;

            $separate = $this->resolver->createFor($contact);

            CrmTimeline::record($customer, CrmTimeline::CONTACT_UNLINKED, $actor, $this->contactData($contact) + [
                'moved_to' => $separate->name,
            ]);

            return $separate;
        });
    }

    /** Fold `$source` into `$target`: one person, two records. */
    public function merge(Customer $target, Customer $source, User $actor): Customer
    {
        if ($target->id === $source->id || $target->tenant_id !== $source->tenant_id) {
            throw ValidationException::withMessages(['source_id' => __('crm.customers.merge_same')]);
        }

        DB::transaction(function () use ($target, $source, $actor) {
            Contact::query()->where('customer_id', $source->id)->update(['customer_id' => $target->id]);
            $this->absorb($target, $source, $actor);
        });

        $this->unifyTags($target);

        return $target->fresh();
    }

    /**
     * Delete a customer — only one no channel contact belongs to. One with
     * identities would be re-created the moment its contacts were swept, so
     * deleting it would only erase the CRM's notes about a person who still
     * writes in; unlink or merge says what is actually meant.
     */
    public function delete(Customer $customer): void
    {
        $count = $customer->contacts()->count();

        if ($count > 0) {
            throw ValidationException::withMessages(['customer' => __('crm.customers.delete_has_contacts', ['count' => $count])]);
        }

        // Their sales history would go with them (the rows cascade); a
        // customer someone sold to is not deleted by accident.
        $sales = $customer->leads()->count() + $customer->deals()->count();

        if ($sales > 0) {
            throw ValidationException::withMessages(['customer' => __('crm.customers.delete_has_sales', ['count' => $sales])]);
        }

        DB::transaction(function () use ($customer) {
            Contact::query()->where('customer_id', $customer->id)->update(['customer_id' => null]);
            CustomerRelationship::query()->where('related_customer_id', $customer->id)->delete();
            \App\Models\CrmActivity::query()
                ->where(fn ($q) => $q->where('subject_type', $customer->getMorphClass())->where('subject_id', $customer->id))
                ->orWhere('customer_id', $customer->id)
                ->delete();
            $customer->delete();
        });
    }

    /**
     * Records that look like the same person: the same phone, the same e-mail,
     * or exactly the same name. Offered, never acted on.
     *
     * @return Collection<int, Customer>
     */
    public function duplicates(Customer $customer, User $viewer, int $limit = 10): Collection
    {
        $name = trim($customer->name);
        $usableName = $name !== '' && preg_match('/^[\d\s+()\-]+$/', $name) !== 1;

        if (! $customer->phone && ! $customer->email && ! $usableName) {
            return collect();
        }

        return Customer::query()
            ->visibleTo($viewer)
            ->whereKeyNot($customer->id)
            ->where(function ($query) use ($customer, $name, $usableName) {
                if ($customer->phone) {
                    $query->orWhereIn('phone', PhoneVariants::of($customer->phone));
                }
                if ($customer->email) {
                    $query->orWhere('email', $customer->email);
                }
                if ($usableName) {
                    $query->orWhereRaw('LOWER(name) = ?', [mb_strtolower($name)]);
                }
            })
            ->with(['contacts:id,customer_id,channel', 'company:id,name'])
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /** Link two customers, both directions, with the same label. */
    public function addRelationship(Customer $customer, Customer $related, User $actor, ?string $label): void
    {
        if ($customer->id === $related->id) {
            throw ValidationException::withMessages(['related_customer_id' => __('crm.customers.relate_self')]);
        }

        foreach ([[$customer, $related], [$related, $customer]] as [$from, $to]) {
            CustomerRelationship::updateOrCreate(
                ['customer_id' => $from->id, 'related_customer_id' => $to->id],
                ['tenant_id' => $from->tenant_id, 'label' => $label],
            );
        }

        CrmTimeline::record($customer, CrmTimeline::RELATIONSHIP_ADDED, $actor, ['related_id' => $related->id, 'related_name' => $related->name, 'label' => $label]);
        CrmTimeline::record($related, CrmTimeline::RELATIONSHIP_ADDED, $actor, ['related_id' => $customer->id, 'related_name' => $customer->name, 'label' => $label]);
    }

    public function removeRelationship(Customer $customer, Customer $related, User $actor): void
    {
        $deleted = CustomerRelationship::query()
            ->where(fn ($q) => $q->where('customer_id', $customer->id)->where('related_customer_id', $related->id))
            ->orWhere(fn ($q) => $q->where('customer_id', $related->id)->where('related_customer_id', $customer->id))
            ->delete();

        if ($deleted > 0) {
            CrmTimeline::record($customer, CrmTimeline::RELATIONSHIP_REMOVED, $actor, ['related_id' => $related->id, 'related_name' => $related->name]);
        }
    }

    /**
     * Everything of `$source` that is not its contacts moves to `$target`,
     * then `$source` goes. The caller has already moved the contacts.
     */
    private function absorb(Customer $target, Customer $source, User $actor): void
    {
        $filled = [];

        foreach (['email', 'phone', 'company_id', 'job_title', 'owner_user_id', 'source'] as $field) {
            if (blank($target->{$field}) && filled($source->{$field})) {
                $filled[$field] = $source->{$field};
            }
        }

        // Theirs fill gaps; ours win where both have a value.
        $custom = array_merge($source->custom_fields ?? [], $target->custom_fields ?? []);

        $target->fill($filled + ['custom_fields' => $custom ?: null])->save();

        // Relationships: re-point both directions, dropping what would become
        // a relationship with oneself or a second copy of an existing one.
        foreach (CustomerRelationship::query()->where('customer_id', $source->id)->get() as $row) {
            $exists = CustomerRelationship::query()->where('customer_id', $target->id)->where('related_customer_id', $row->related_customer_id)->exists();
            $exists || $row->related_customer_id === $target->id ? $row->delete() : $row->update(['customer_id' => $target->id]);
        }

        foreach (CustomerRelationship::query()->where('related_customer_id', $source->id)->get() as $row) {
            $exists = CustomerRelationship::query()->where('customer_id', $row->customer_id)->where('related_customer_id', $target->id)->exists();
            $exists || $row->customer_id === $target->id ? $row->delete() : $row->update(['related_customer_id' => $target->id]);
        }

        // Their leads, deals and tasks are the same person's now.
        \App\Models\Lead::query()->where('customer_id', $source->id)->update(['customer_id' => $target->id]);
        \App\Models\Deal::query()->where('customer_id', $source->id)->update(['customer_id' => $target->id]);
        \App\Models\CrmTask::query()->where('customer_id', $source->id)->update(['customer_id' => $target->id]);
        \App\Models\CrmTask::query()->where('subject_type', 'customer')->where('subject_id', $source->id)->update(['subject_id' => $target->id]);

        CrmTimeline::move($source, $target);
        CrmTimeline::record($target, CrmTimeline::MERGED, $actor, [
            'from_id' => $source->id,
            'from_name' => $source->name,
            'filled' => array_keys($filled),
        ]);

        $source->delete();
    }

    /** The union of the linked contacts' tags, applied to all of them. */
    private function unifyTags(Customer $customer): void
    {
        $contacts = $customer->contacts()->with('tags:id')->get();

        if ($contacts->count() < 2) {
            return;
        }

        $union = $contacts->flatMap(fn (Contact $contact) => $contact->tags->pluck('id'))->unique()->values()->all();

        if ($contacts->every(fn (Contact $contact) => $contact->tags->pluck('id')->sort()->values()->all() === collect($union)->sort()->values()->all())) {
            return;
        }

        $this->contactTags->sync($contacts->first(), $union);
    }

    /** The person's name on every channel contact, locked so a channel profile does not overwrite it. */
    private function applyNameToContacts(Customer $customer): void
    {
        $ids = $customer->contacts()->pluck('id');

        if ($ids->isEmpty()) {
            return;
        }

        Contact::query()->whereIn('id', $ids)->update(['name' => $customer->name, 'name_locked' => true]);

        // The inbox syncs conversations by `updated_at`; a contact rename
        // changes no conversation row, so without this a closed tab keeps the
        // old name.
        Conversation::query()->whereIn('contact_id', $ids)->update(['updated_at' => now()]);
    }

    /** @return array<string, mixed> */
    private function snapshot(Customer $customer): array
    {
        $values = [];

        foreach (self::TRACKED as $field) {
            $value = $customer->getAttribute($field);
            $values[$field] = $value instanceof \BackedEnum ? $value->value : $value;
        }

        // Custom fields are recorded key by key.
        foreach ($customer->custom_fields ?? [] as $key => $value) {
            $values['custom_fields.'.$key] = $value;
        }

        return $values;
    }

    /** @return list<string> */
    private function tagNames(Customer $customer): array
    {
        return $customer->contacts()->with('tags:id,name')->get()
            ->flatMap(fn (Contact $contact) => $contact->tags->pluck('name'))
            ->unique()->sort()->values()->all();
    }

    /** @return array<string, mixed> */
    private function contactData(Contact $contact): array
    {
        return [
            'contact_id' => $contact->id,
            'channel' => $contact->channel?->value,
            'identifier' => $contact->username ?: $contact->external_id,
        ];
    }
}

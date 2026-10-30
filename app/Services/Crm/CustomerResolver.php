<?php

namespace App\Services\Crm;

use App\Enums\Crm\CustomerStatus;
use App\Models\Contact;
use App\Models\Customer;
use App\Services\Contact\ContactIdentity;
use App\Support\PhoneVariants;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Finds the customer a channel contact belongs to, or creates one.
 *
 * Runs the moment a contact is created (ContactObserver), so a new person
 * writing in is a customer before their first message is answered, and by the
 * hourly `crm:link-contacts` sweep for contacts that existed before the CRM.
 *
 * Matching is deliberately narrow: the same phone number (WhatsApp, either
 * kind, shares one) or the same e-mail address. A name never links two
 * contacts on its own — two people called "Budi" are the commonest false
 * match there is. Anything looser is offered as a possible duplicate on the
 * customer's page and merged by a person.
 */
class CustomerResolver
{
    /**
     * The customer for this contact, linking (or creating) when it has none.
     * Null for groups. Never throws: it runs inside webhooks.
     */
    public function attach(Contact $contact): ?Customer
    {
        if ($contact->is_group) {
            return null;
        }

        if ($contact->customer_id) {
            return $contact->customer;
        }

        try {
            return $this->resolve($contact);
        } catch (Throwable $e) {
            Log::warning('CRM: contact could not be linked to a customer', [
                'contact_id' => $contact->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * A brand-new customer for this contact, never matched — what unlinking an
     * identity from a customer leaves it with.
     */
    public function createFor(Contact $contact): Customer
    {
        $identity = ContactIdentity::for($contact);

        $customer = Customer::create([
            'tenant_id' => $contact->tenant_id,
            'name' => $this->nameFor($contact, $identity),
            'phone' => $identity['phone'] ?? null,
            'email' => $identity['email'] ?? null,
            'status' => CustomerStatus::New,
            'source' => $contact->channel?->value,
        ]);

        $this->link($contact, $customer);

        CrmTimeline::record($customer, CrmTimeline::CREATED, data: [
            'source' => $customer->source,
            'contact_id' => $contact->id,
        ]);

        return $customer;
    }

    private function resolve(Contact $contact): Customer
    {
        $identity = ContactIdentity::for($contact);
        $phone = $identity['phone'] ?? null;
        $email = $identity['email'] ?? null;

        if ($phone === null && $email === null) {
            return $this->createFor($contact);
        }

        // Two contacts with the same number created in the same instant (the
        // two WhatsApp kinds) must not each make a customer.
        $key = 'crm:resolve:'.$contact->tenant_id.':'.($phone ?? $email);

        return Cache::lock($key, 10)->block(5, function () use ($contact, $phone, $email) {
            $contact->refresh();

            if ($contact->customer_id) {
                return $contact->customer;
            }

            $match = Customer::query()
                ->where('tenant_id', $contact->tenant_id)
                ->where(function ($query) use ($phone, $email) {
                    if ($phone !== null) {
                        // Either spelling of a Brazilian mobile: a number typed
                        // into the CRM has the ninth digit, the id WhatsApp
                        // reports may not.
                        $query->orWhereIn('phone', PhoneVariants::of($phone));
                    }
                    if ($email !== null) {
                        $query->orWhere('email', $email);
                    }
                })
                ->orderBy('id')
                ->first();

            if (! $match) {
                return $this->createFor($contact);
            }

            $this->link($contact, $match);

            CrmTimeline::record($match, CrmTimeline::CONTACT_LINKED, data: [
                'contact_id' => $contact->id,
                'channel' => $contact->channel?->value,
                'identifier' => $contact->username ?: $contact->external_id,
                'automatic' => true,
            ]);

            return $match;
        });
    }

    /**
     * Written through the query builder: a link is not a profile change, and
     * the contact's `updated_at` is nobody's cursor.
     */
    private function link(Contact $contact, Customer $customer): void
    {
        Contact::query()->whereKey($contact->id)->update(['customer_id' => $customer->id]);
        $contact->setAttribute('customer_id', $customer->id);
        $contact->setRelation('customer', $customer);
    }

    /** @param  array<string, string>  $identity */
    private function nameFor(Contact $contact, array $identity): string
    {
        return ContactIdentity::name($contact)
            ?? ($contact->username ?: null)
            ?? ($identity['phone'] ?? $identity['email'] ?? null)
            ?? ((string) $contact->name !== '' ? (string) $contact->name : (string) $contact->external_id);
    }
}

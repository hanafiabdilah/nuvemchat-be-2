<?php

namespace App\Services\Crm;

use App\Models\Company;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * What a task or a logged activity can be about, resolved through the same
 * visibility rules as the pages: a subject the user may not see is "not
 * found", never quietly attached to.
 */
class CrmSubjects
{
    public const TYPES = ['customer', 'lead', 'deal', 'company'];

    public static function resolve(User $user, string $type, int|string $id, string $field = 'subject_id'): Model
    {
        $subject = match ($type) {
            'customer' => Customer::query()->visibleTo($user)->find($id),
            'lead' => Lead::query()->visibleTo($user)->find($id),
            'deal' => Deal::query()->visibleTo($user)->find($id),
            'company' => Company::query()->where('tenant_id', $user->tenant_id)->find($id),
            default => null,
        };

        if (! $subject) {
            throw ValidationException::withMessages([$field => __('crm.subjects.not_found')]);
        }

        return $subject;
    }

    /**
     * A conversation the user may open, for linking a record to where it came
     * from — or a refusal.
     */
    public static function conversation(User $user, int|string|null $id): ?Conversation
    {
        if ($id === null || $id === '') {
            return null;
        }

        $conversation = Conversation::query()->visibleTo($user)->with('contact')->find($id);

        if (! $conversation) {
            throw ValidationException::withMessages(['conversation_id' => __('crm.subjects.conversation_not_found')]);
        }

        return $conversation;
    }

    /**
     * The customer behind a conversation — linked on the spot when the sweep
     * has not reached its contact yet. Groups have none.
     */
    public static function customerOfConversation(Conversation $conversation): Customer
    {
        $contact = $conversation->contact;

        $customer = $contact instanceof Contact ? app(CustomerResolver::class)->attach($contact) : null;

        if (! $customer) {
            throw ValidationException::withMessages(['conversation_id' => __('crm.subjects.no_customer')]);
        }

        return $customer;
    }
}

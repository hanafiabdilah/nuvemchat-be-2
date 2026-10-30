<?php

namespace App\Services\Crm\Automation;

use App\Models\CrmTask;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/** The four kinds of record an automation can act on, read one way. */
final class AutomationSubjects
{
    public static function typeOf(Model $subject): string
    {
        return match (true) {
            $subject instanceof Lead => 'lead',
            $subject instanceof Deal => 'deal',
            $subject instanceof CrmTask => 'task',
            default => 'customer',
        };
    }

    public static function find(string $type, int $id): ?Model
    {
        return match ($type) {
            'lead' => Lead::find($id),
            'deal' => Deal::find($id),
            'task' => CrmTask::find($id),
            'customer' => Customer::find($id),
            default => null,
        };
    }

    public static function customerOf(Model $subject): ?Customer
    {
        if ($subject instanceof Customer) {
            return $subject;
        }

        $id = $subject->getAttribute('customer_id');

        return $id ? Customer::find($id) : null;
    }

    public static function titleOf(Model $subject): string
    {
        return (string) ($subject instanceof Customer ? $subject->name : $subject->getAttribute('title'));
    }

    /** Who is in charge of it: the owner, or a task's assignee. */
    public static function ownerIdOf(Model $subject): ?int
    {
        $id = $subject instanceof CrmTask ? $subject->assignee_user_id : $subject->getAttribute('owner_user_id');

        return $id ? (int) $id : null;
    }

    public static function urlOf(Model $subject): string
    {
        return match (self::typeOf($subject)) {
            'lead' => "/leads/{$subject->getKey()}",
            'deal' => "/deals/{$subject->getKey()}",
            'task' => '/tasks',
            default => "/contacts/{$subject->getKey()}",
        };
    }

    /** Whether `$user` may see the record — nobody is told about what they cannot open. */
    public static function visibleTo(Model $subject, User $user): bool
    {
        if ((int) $user->tenant_id !== (int) $subject->getAttribute('tenant_id')) {
            return false;
        }

        return $subject::query()->visibleTo($user)->whereKey($subject->getKey())->exists();
    }
}

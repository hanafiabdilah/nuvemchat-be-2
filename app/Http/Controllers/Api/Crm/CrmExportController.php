<?php

namespace App\Http\Controllers\Api\Crm;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\CrmTask;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\User;
use App\Services\Billing\SubscriptionGate;
use App\Services\Crm\CustomFields;
use App\Services\Crm\CustomerFilter;
use App\Support\CsvRow;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CRM data out, as CSV: customers, companies, leads, deals and tasks — only
 * the rows the reader may see, filtered the way the list was, and each export
 * recorded in the audit trail (data leaving the workspace is exactly what that
 * trail is for). `crm-data.export`. Amounts are in the workspace currency's
 * main unit (with a currency column), times in the workspace's timezone.
 */
class CrmExportController extends Controller
{
    public const ENTITIES = ['customers', 'companies', 'leads', 'deals', 'tasks'];

    public function download(Request $request, string $entity): StreamedResponse
    {
        abort_unless(in_array($entity, self::ENTITIES, true), 404);

        // The sales side is the `crm` plan feature, like its pages.
        if (in_array($entity, ['leads', 'deals', 'tasks'], true) && config('services.billing.enforce')
            && ! app(SubscriptionGate::class)->feature($request->user()->tenant, 'crm')) {
            abort(403, __('crm.bulk.forbidden'));
        }

        $data = $request->validate([
            ...CustomerFilter::listRules(),
            'ids' => ['nullable', 'array', 'max:5000'],
            'ids.*' => ['integer'],
            'lead_status' => ['nullable', 'array'],
            'lead_status.*' => ['string', 'max:20'],
            'deal_status' => ['nullable', 'string', 'max:10'],
            'pipeline_id' => ['nullable', 'integer'],
        ]);

        $user = $request->user();
        $timezone = $user->tenant?->displayTimezone() ?? config('app.timezone');

        AuditLog::record('crm.export', "Exported CRM {$entity}", [
            'tenant_id' => $user->tenant_id,
            'entity' => $entity,
            'filters' => array_filter($data, fn ($value) => $value !== null && $value !== []),
        ]);

        return response()->streamDownload(function () use ($entity, $data, $user, $timezone) {
            $out = fopen('php://output', 'w');
            // Excel reads a UTF-8 CSV as Latin-1 unless the file opens with a BOM.
            fwrite($out, "\xEF\xBB\xBF");

            match ($entity) {
                'customers' => $this->customers($out, $user, $data, $timezone),
                'companies' => $this->companies($out, $user, $data, $timezone),
                'leads' => $this->leads($out, $user, $data, $timezone),
                'deals' => $this->deals($out, $user, $data, $timezone),
                'tasks' => $this->tasks($out, $user, $data, $timezone),
            };

            fclose($out);
        }, sprintf('%s-%s.csv', $entity, now($timezone)->format('Y-m-d')), [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    private function customers($out, User $user, array $data, string $timezone): void
    {
        $fields = CustomFields::definitions($user->tenant_id, 'customer');

        CsvRow::write($out, [
            'id', 'name', 'phone', 'email', 'status', 'source', 'company', 'job_title', 'person_in_charge',
            'channels', 'tags', 'created_at', 'last_contact_at',
            ...$fields->map(fn ($field) => 'custom.'.$field->key)->all(),
        ]);

        CustomerFilter::query($user, $data)
            ->when($data['ids'] ?? null, fn (Builder $q, array $ids) => $q->whereIn('customers.id', $ids))
            ->with(['contacts:id,customer_id,channel', 'contacts.tags:id,name', 'company:id,name', 'owner:id,name'])
            ->withMax('conversations as last_interaction_at', 'last_message_at')
            ->orderBy('customers.id')
            ->chunk(300, function ($customers) use ($out, $fields, $timezone) {
                foreach ($customers as $customer) {
                    /** @var Customer $customer */
                    CsvRow::write($out, [
                        $customer->id,
                        $customer->name,
                        $customer->phone,
                        $customer->email,
                        $customer->status?->value,
                        $customer->source,
                        $customer->company?->name,
                        $customer->job_title,
                        $customer->owner?->name,
                        $customer->contacts->map(fn ($c) => $c->channel?->value)->filter()->unique()->implode(', '),
                        $customer->contacts->flatMap->tags->pluck('name')->unique()->implode(', '),
                        self::time($customer->created_at, $timezone),
                        self::time($customer->last_interaction_at, $timezone),
                        ...$fields->map(fn ($field) => self::scalar($customer->custom_fields[$field->key] ?? null))->all(),
                    ]);
                }
            });
    }

    private function companies($out, User $user, array $data, string $timezone): void
    {
        CsvRow::write($out, ['id', 'name', 'domain', 'industry', 'email', 'phone', 'website', 'address', 'person_in_charge', 'customers', 'created_at']);

        Company::query()
            ->where('tenant_id', $user->tenant_id)
            ->when($data['ids'] ?? null, fn (Builder $q, array $ids) => $q->whereIn('id', $ids))
            ->with('owner:id,name')
            ->withCount('customers')
            ->orderBy('id')
            ->chunk(300, function ($companies) use ($out, $timezone) {
                foreach ($companies as $company) {
                    CsvRow::write($out, [
                        $company->id, $company->name, $company->domain, $company->industry, $company->email,
                        $company->phone, $company->website, $company->address, $company->owner?->name,
                        $company->customers_count, self::time($company->created_at, $timezone),
                    ]);
                }
            });
    }

    private function leads($out, User $user, array $data, string $timezone): void
    {
        CsvRow::write($out, ['id', 'title', 'customer', 'customer_phone', 'customer_email', 'status', 'score', 'source', 'value', 'currency', 'person_in_charge', 'disqualified_reason', 'created_at', 'converted_at']);

        $currency = $user->tenant?->currency();

        Lead::query()->visibleTo($user)
            ->when($data['ids'] ?? null, fn (Builder $q, array $ids) => $q->whereIn('crm_leads.id', $ids))
            ->when($data['lead_status'] ?? null, fn (Builder $q, array $status) => $q->whereIn('status', $status))
            ->when($data['owner'] ?? null, fn (Builder $q, string $owner) => self::owner($q, 'owner_user_id', $owner, $user))
            ->with(['customer:id,name,phone,email', 'owner:id,name'])
            ->orderBy('id')
            ->chunk(300, function ($leads) use ($out, $timezone, $currency) {
                foreach ($leads as $lead) {
                    CsvRow::write($out, [
                        $lead->id, $lead->title, $lead->customer?->name, $lead->customer?->phone, $lead->customer?->email,
                        $lead->status?->value, $lead->score, $lead->source, self::major($lead->value_cents, $currency), $lead->value_cents === null ? null : $currency,
                        $lead->owner?->name, $lead->disqualified_reason,
                        self::time($lead->created_at, $timezone), self::time($lead->converted_at, $timezone),
                    ]);
                }
            });
    }

    private function deals($out, User $user, array $data, string $timezone): void
    {
        CsvRow::write($out, ['id', 'title', 'customer', 'company', 'pipeline', 'stage', 'status', 'value', 'currency', 'expected_close', 'person_in_charge', 'lost_reason', 'created_at', 'won_at', 'lost_at']);

        Deal::query()->visibleTo($user)
            ->when($data['ids'] ?? null, fn (Builder $q, array $ids) => $q->whereIn('crm_deals.id', $ids))
            ->when($data['deal_status'] ?? null, fn (Builder $q, string $status) => $q->where('status', $status))
            ->when($data['pipeline_id'] ?? null, fn (Builder $q, int $pipeline) => $q->where('pipeline_id', $pipeline))
            ->when($data['owner'] ?? null, fn (Builder $q, string $owner) => self::owner($q, 'owner_user_id', $owner, $user))
            ->with(['customer:id,name', 'company:id,name', 'pipeline:id,name', 'stage:id,name', 'owner:id,name'])
            ->orderBy('id')
            ->chunk(300, function ($deals) use ($out, $timezone) {
                foreach ($deals as $deal) {
                    CsvRow::write($out, [
                        $deal->id, $deal->title, $deal->customer?->name, $deal->company?->name, $deal->pipeline?->name,
                        $deal->stage?->name, $deal->status, self::major($deal->value_cents, $deal->currency), $deal->currency, $deal->expected_close_date?->format('Y-m-d'),
                        $deal->owner?->name, $deal->lost_reason,
                        self::time($deal->created_at, $timezone), self::time($deal->won_at, $timezone), self::time($deal->lost_at, $timezone),
                    ]);
                }
            });
    }

    private function tasks($out, User $user, array $data, string $timezone): void
    {
        CsvRow::write($out, ['id', 'title', 'priority', 'due_at', 'completed_at', 'assignee', 'created_by', 'about', 'customer', 'created_at']);

        CrmTask::query()->visibleTo($user)
            ->when($data['ids'] ?? null, fn (Builder $q, array $ids) => $q->whereIn('crm_tasks.id', $ids))
            ->with(['assignee:id,name', 'creator:id,name', 'customer:id,name', 'subject'])
            ->orderBy('id')
            ->chunk(300, function ($tasks) use ($out, $timezone) {
                foreach ($tasks as $task) {
                    $subject = $task->subject;

                    CsvRow::write($out, [
                        $task->id, $task->title, $task->priority?->value, self::time($task->due_at, $timezone),
                        self::time($task->completed_at, $timezone), $task->assignee?->name, $task->creator?->name,
                        $subject ? ($task->subject_type.': '.($subject->title ?? $subject->name ?? '')) : null,
                        $task->customer?->name, self::time($task->created_at, $timezone),
                    ]);
                }
            });
    }

    private static function owner(Builder $q, string $column, string $owner, User $user): void
    {
        match ($owner) {
            'none' => $q->whereNull($column),
            'me' => $q->where($column, $user->id),
            default => $q->where($column, (int) $owner),
        };
    }

    private static function time(mixed $value, string $timezone): ?string
    {
        return $value ? Carbon::parse($value)->setTimezone($timezone)->format('Y-m-d H:i') : null;
    }

    /**
     * Minor units as the amount a spreadsheet reads: 15000 cents of reais is
     * "150.00", and the rupiah, written without decimals, stays whole.
     */
    private static function major(?int $cents, ?string $currency): ?string
    {
        if ($cents === null) {
            return null;
        }

        $decimals = Money::decimals($currency);

        return number_format($cents / (10 ** $decimals), $decimals, '.', '');
    }

    private static function scalar(mixed $value): mixed
    {
        return is_bool($value) ? ($value ? 'yes' : 'no') : (is_scalar($value) ? $value : null);
    }
}

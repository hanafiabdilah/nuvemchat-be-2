<?php

namespace App\Services\Crm;

use App\Enums\Crm\CustomerStatus;
use App\Enums\Crm\LeadStatus;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Lead scoring: points the workspace gives for what it knows about a lead and
 * its customer (has an e-mail, came from Instagram, wrote this week, worth at
 * least 5,000.00…), summed and clamped to 0–100.
 *
 * Off by default — then the score is whatever people type. On, the score is
 * computed and never typed: on every save of the lead or its customer, after
 * the rules change, and hourly (`crm:score-leads`) for what time alone moves
 * ("wrote within 7 days" stops being true without anybody touching the lead).
 * `score_details` keeps which rules matched, so the number explains itself.
 */
final class LeadScoring
{
    public const MAX_RULES = 30;

    /** criterion => what its value is */
    public const CRITERIA = [
        'has_email' => 'none',
        'has_phone' => 'none',
        'has_company' => 'none',
        'source_is' => 'strings',
        'value_at_least' => 'money',
        'customer_status' => 'customer_statuses',
        'lead_status' => 'lead_statuses',
        'has_tag' => 'tags',
        'wrote_within_days' => 'days',
        'conversations_at_least' => 'count',
        'open_longer_than_days' => 'days',
        'custom_field_equals' => 'custom_field',
    ];

    /** @return array{enabled: bool, rules: list<array<string, mixed>>} */
    public static function config(Tenant $tenant): array
    {
        $config = $tenant->crm_lead_scoring ?? [];

        return [
            'enabled' => (bool) ($config['enabled'] ?? false),
            'rules' => array_values($config['rules'] ?? []),
        ];
    }

    public static function enabled(int $tenantId): bool
    {
        $tenant = Tenant::find($tenantId);

        return $tenant !== null && self::config($tenant)['enabled'];
    }

    /**
     * @param  array<string, mixed>  $input  {enabled, rules: [{criterion, value?, key?, points}]}
     * @return array{enabled: bool, rules: list<array<string, mixed>>}
     */
    public static function validate(int $tenantId, array $input): array
    {
        $data = validator($input, [
            'enabled' => ['required', 'boolean'],
            'rules' => ['present', 'array', 'max:'.self::MAX_RULES],
            'rules.*.criterion' => ['required', Rule::in(array_keys(self::CRITERIA))],
            'rules.*.points' => ['required', 'integer', 'min:-100', 'max:100', 'not_in:0'],
            'rules.*.value' => ['nullable'],
            'rules.*.key' => ['nullable', 'string', 'max:60'],
        ])->validate();

        $rules = [];

        foreach ($data['rules'] as $index => $rule) {
            $clean = ['criterion' => $rule['criterion'], 'points' => (int) $rule['points']];
            $value = $rule['value'] ?? null;
            $path = "rules.{$index}.value";
            $invalid = fn () => throw ValidationException::withMessages([$path => __('crm.scoring.invalid_rule')]);

            switch (self::CRITERIA[$rule['criterion']]) {
                case 'none':
                    break;
                case 'strings':
                    $list = array_values(array_filter(array_map(fn ($v) => is_scalar($v) ? trim((string) $v) : '', (array) $value)));
                    $list === [] && $invalid();
                    $clean['value'] = $list;
                    break;
                case 'money':
                case 'count':
                case 'days':
                    (! is_numeric($value) || (int) $value < 0) && $invalid();
                    $clean['value'] = (int) $value;
                    break;
                case 'customer_statuses':
                    $list = array_values(array_intersect((array) $value, CustomerStatus::values()));
                    $list === [] && $invalid();
                    $clean['value'] = $list;
                    break;
                case 'lead_statuses':
                    $list = array_values(array_intersect((array) $value, LeadStatus::values()));
                    $list === [] && $invalid();
                    $clean['value'] = $list;
                    break;
                case 'tags':
                    $ids = array_values(array_unique(array_map('intval', array_filter((array) $value, 'is_numeric'))));
                    ($ids === [] || \DB::table('tags')->where('tenant_id', $tenantId)->whereIn('id', $ids)->count() !== count($ids)) && $invalid();
                    $clean['value'] = $ids;
                    break;
                case 'custom_field':
                    $definition = CustomFields::definitions($tenantId, 'customer')->firstWhere('key', $rule['key'] ?? null);
                    $definition || throw ValidationException::withMessages(["rules.{$index}.key" => __('crm.scoring.invalid_rule')]);
                    $normalized = CustomFields::normalize($definition, $value);
                    $normalized === null && $invalid();
                    $clean['key'] = $definition->key;
                    $clean['value'] = $normalized;
                    break;
            }

            $rules[] = $clean;
        }

        return ['enabled' => (bool) $data['enabled'], 'rules' => $rules];
    }

    /**
     * The lead's score and the rules that made it.
     *
     * @param  list<array<string, mixed>>  $rules
     * @return array{score: int, details: list<array<string, mixed>>}
     */
    public static function evaluate(Lead $lead, array $rules): array
    {
        $customer = $lead->customer;
        $facts = new class($lead)
        {
            private ?array $conversation = null;

            private ?array $tags = null;

            public function __construct(private Lead $lead) {}

            /** @return array{count: int, last: ?Carbon} */
            public function conversations(): array
            {
                if ($this->conversation === null) {
                    $query = Conversation::query()->whereIn('contact_id', $this->lead->customer?->contacts()->select('id') ?? [0]);
                    $last = (clone $query)->max('last_message_at');
                    $this->conversation = ['count' => (clone $query)->count(), 'last' => $last ? Carbon::parse($last) : null];
                }

                return $this->conversation;
            }

            /** @return list<int> */
            public function tags(): array
            {
                return $this->tags ??= \DB::table('contact_tags')
                    ->join('contacts', 'contacts.id', '=', 'contact_tags.contact_id')
                    ->where('contacts.customer_id', $this->lead->customer_id)
                    ->distinct()->pluck('contact_tags.tag_id')->map(fn ($id) => (int) $id)->all();
            }
        };

        $total = 0;
        $details = [];

        foreach ($rules as $rule) {
            $value = $rule['value'] ?? null;

            $matched = match ($rule['criterion']) {
                'has_email' => filled($customer?->email),
                'has_phone' => filled($customer?->phone),
                'has_company' => $customer?->company_id !== null,
                'source_is' => in_array((string) $lead->source, $value, true),
                'value_at_least' => (int) $lead->value_cents >= (int) $value,
                'customer_status' => in_array($customer?->status?->value, $value, true),
                'lead_status' => in_array($lead->status?->value, $value, true),
                'has_tag' => array_intersect($value, $facts->tags()) !== [],
                'wrote_within_days' => ($last = $facts->conversations()['last']) !== null && $last->gte(now()->subDays((int) $value)),
                'conversations_at_least' => $facts->conversations()['count'] >= (int) $value,
                'open_longer_than_days' => $lead->status?->isOpen() && $lead->created_at?->lt(now()->subDays((int) $value)),
                'custom_field_equals' => ($customer?->custom_fields[$rule['key']] ?? null) === $value,
                default => false,
            };

            if ($matched) {
                $total += (int) $rule['points'];
                $details[] = array_filter([
                    'criterion' => $rule['criterion'],
                    'key' => $rule['key'] ?? null,
                    'value' => $value,
                    'points' => (int) $rule['points'],
                ], fn ($v) => $v !== null);
            }
        }

        return ['score' => max(0, min(100, $total)), 'details' => $details];
    }

    /** Recompute one lead when scoring is on; returns whether it changed. */
    public static function apply(Lead $lead, ?array $config = null): bool
    {
        $config ??= $lead->customer?->tenant ? self::config($lead->customer->tenant) : null;

        if (! $config || ! $config['enabled']) {
            return false;
        }

        $result = self::evaluate($lead, $config['rules']);
        $changed = $result['score'] !== (int) $lead->score || $result['details'] !== ($lead->score_details ?? []);

        // Quietly: a score moving is not somebody editing the lead, and the
        // timestamp is what boards sort "recently changed" by — and what the
        // "lead idle for N days" automation reads. The base query, because an
        // Eloquent update() stamps updated_at by itself.
        Lead::query()->whereKey($lead->id)->toBase()->update([
            'score' => $result['score'],
            'score_details' => json_encode($result['details']),
            'score_updated_at' => now(),
        ]);

        $lead->forceFill(['score' => $result['score'], 'score_details' => $result['details']]);

        return $changed;
    }

    /** Every open lead of the workspace (or of one customer), rescored. */
    public static function rescore(int $tenantId, ?int $customerId = null): int
    {
        $tenant = Tenant::find($tenantId);

        if (! $tenant) {
            return 0;
        }

        $config = self::config($tenant);

        if (! $config['enabled']) {
            return 0;
        }

        $changed = 0;
        $open = array_values(array_filter(LeadStatus::values(), fn ($status) => LeadStatus::from($status)->isOpen()));

        Lead::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('status', $open)
            ->when($customerId, fn ($q) => $q->where('customer_id', $customerId))
            ->with('customer')
            ->chunkById(200, function ($leads) use ($config, &$changed) {
                foreach ($leads as $lead) {
                    $changed += self::apply($lead, $config) ? 1 : 0;
                }
            });

        return $changed;
    }
}

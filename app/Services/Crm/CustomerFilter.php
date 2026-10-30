<?php

namespace App\Services\Crm;

use App\Enums\Connection\Channel;
use App\Enums\Crm\CustomerStatus;
use App\Enums\Crm\LeadStatus;
use App\Models\CrmSegment;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Which customers: one definition, read by the customer list, segments,
 * bulk actions, exports and the campaign recipient picker — so "the
 * customers in this segment" is the same set wherever it is asked for.
 *
 * Two inputs. List filters are the list page's quick filters (search,
 * status, channel, tags, owner, company, a segment). Segment rules are a
 * richer rule set ({field, operator, value}, matched all or any) saved as a
 * segment. Both always start from what the reader may see
 * (Customer::visibleTo).
 */
final class CustomerFilter
{
    public const MAX_RULES = 20;

    /** field => operators it accepts */
    public const FIELDS = [
        'status' => ['in', 'not_in'],
        'channel' => ['in', 'not_in'],
        'tags' => ['has_any', 'has_all', 'has_none'],
        'owner' => ['is', 'is_empty', 'is_not_empty'],
        'company' => ['is', 'is_empty', 'is_not_empty'],
        'source' => ['in', 'not_in'],
        'email' => ['is_empty', 'is_not_empty'],
        'phone' => ['is_empty', 'is_not_empty'],
        'created' => ['within_days', 'older_than_days'],
        'last_interaction' => ['within_days', 'older_than_days', 'never'],
        'open_lead' => ['exists', 'not_exists'],
        'open_deal' => ['exists', 'not_exists'],
        'won_deal' => ['exists', 'not_exists'],
        'lead_score' => ['at_least', 'below'],
        'custom_field' => ['equals', 'is_empty', 'is_not_empty'],
    ];

    /** Operators whose rule needs no value. */
    private const VALUELESS = ['is_empty', 'is_not_empty', 'never', 'exists', 'not_exists'];

    /** Validation for the list page's filters (and anything that takes them). */
    public static function listRules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'array'],
            'status.*' => [Rule::in(CustomerStatus::values())],
            'channel' => ['nullable', 'array'],
            'channel.*' => ['string', 'max:40'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['integer'],
            'owner' => ['nullable', 'string', 'max:20'],
            'company_id' => ['nullable', 'integer'],
            'segment_id' => ['nullable', 'integer'],
        ];
    }

    /** The customers this user may see, with the list filters applied. */
    public static function query(User $viewer, array $filters = []): Builder
    {
        return self::applyList(Customer::query()->visibleTo($viewer), $filters, $viewer);
    }

    /** @param  array<string, mixed>  $filters  validated with listRules() */
    public static function applyList(Builder $query, array $filters, User $viewer): Builder
    {
        $query
            ->when($filters['search'] ?? null, function (Builder $query, string $search) {
                $like = '%'.$search.'%';
                $digits = preg_replace('/\D+/', '', $search);

                $query->where(function (Builder $q) use ($like, $digits) {
                    $q->where('customers.name', 'like', $like)
                        ->orWhere('customers.email', 'like', $like)
                        ->orWhereHas('contacts', fn (Builder $c) => $c->where('external_id', 'like', $like)->orWhere('username', 'like', $like)->orWhere('name', 'like', $like));

                    if ($digits !== '' && strlen($digits) >= 4) {
                        $q->orWhere('customers.phone', 'like', '%'.$digits.'%');
                    }
                });
            })
            ->when($filters['status'] ?? null, fn (Builder $q, array $status) => $q->whereIn('customers.status', $status))
            ->when($filters['channel'] ?? null, fn (Builder $q, array $channels) => $q->whereHas('contacts', fn (Builder $c) => $c->whereIn('channel', $channels)))
            ->when($filters['tags'] ?? null, fn (Builder $q, array $tags) => $q->whereHas('contacts.tags', fn (Builder $t) => $t->whereIn('tags.id', $tags)))
            ->when($filters['company_id'] ?? null, fn (Builder $q, int $company) => $q->where('customers.company_id', $company))
            ->when($filters['owner'] ?? null, function (Builder $q, string $owner) use ($viewer) {
                match ($owner) {
                    'none' => $q->whereNull('customers.owner_user_id'),
                    'me' => $q->where('customers.owner_user_id', $viewer->id),
                    default => $q->where('customers.owner_user_id', (int) $owner),
                };
            });

        if (! empty($filters['segment_id'])) {
            $segment = CrmSegment::query()->where('tenant_id', $viewer->tenant_id)->find($filters['segment_id']);

            if (! $segment) {
                throw new HttpException(404, __('crm.segments.not_found'));
            }

            self::applyRules($query, $segment->match, $segment->rules ?? []);
        }

        return $query;
    }

    /**
     * A segment's rules, checked and normalised: unknown fields, operators
     * that do not fit, values of the wrong shape and references to another
     * workspace's tags, users or companies are refused, not dropped.
     *
     * @param  array<string, mixed>  $input  {match, rules}
     * @return array{match: string, rules: list<array<string, mixed>>}
     */
    public static function validate(int $tenantId, array $input): array
    {
        $data = validator($input, [
            'match' => ['required', Rule::in(['all', 'any'])],
            'rules' => ['present', 'array', 'max:'.self::MAX_RULES],
            'rules.*.field' => ['required', Rule::in(array_keys(self::FIELDS))],
            'rules.*.operator' => ['required', 'string', 'max:20'],
            'rules.*.value' => ['nullable'],
            'rules.*.key' => ['nullable', 'string', 'max:60'],
        ])->validate();

        $rules = [];

        foreach ($data['rules'] as $index => $rule) {
            $rules[] = self::validateRule($tenantId, $rule, $index);
        }

        return ['match' => $data['match'], 'rules' => $rules];
    }

    /**
     * @param  list<array<string, mixed>>  $rules  normalised by validate()
     */
    public static function applyRules(Builder $query, string $match, array $rules): Builder
    {
        if ($rules === []) {
            return $query;
        }

        return $query->where(function (Builder $group) use ($match, $rules) {
            foreach ($rules as $rule) {
                $method = $match === 'any' ? 'orWhere' : 'where';
                $group->{$method}(fn (Builder $q) => self::applyRule($q, $rule));
            }
        });
    }

    /** @param  array<string, mixed>  $rule */
    private static function validateRule(int $tenantId, array $rule, int $index): array
    {
        $field = $rule['field'];
        $operator = $rule['operator'];
        $path = "rules.{$index}";

        if (! in_array($operator, self::FIELDS[$field], true)) {
            throw ValidationException::withMessages(["{$path}.operator" => __('crm.segments.invalid_rule')]);
        }

        $value = $rule['value'] ?? null;
        $clean = ['field' => $field, 'operator' => $operator];

        if ($field === 'custom_field') {
            $definition = CustomFields::definitions($tenantId, 'customer')->firstWhere('key', $rule['key'] ?? null);

            if (! $definition) {
                throw ValidationException::withMessages(["{$path}.key" => __('crm.segments.invalid_rule')]);
            }

            $clean['key'] = $definition->key;

            if ($operator === 'equals') {
                $normalized = CustomFields::normalize($definition, $value);

                if ($normalized === null) {
                    throw ValidationException::withMessages(["{$path}.value" => __('crm.segments.invalid_rule')]);
                }

                $clean['value'] = $normalized;
            }

            return $clean;
        }

        if (in_array($operator, self::VALUELESS, true)) {
            return $clean;
        }

        $clean['value'] = match ($field) {
            'status' => self::listOf($value, CustomerStatus::values(), $path),
            'channel' => self::listOf($value, array_map(fn (Channel $c) => $c->value, Channel::cases()), $path),
            'source' => self::strings($value, $path),
            'tags' => self::ids($value, $path, 'tags', $tenantId),
            'owner' => self::ids([$value], $path, 'users', $tenantId)[0],
            'company' => self::ids([$value], $path, 'companies', $tenantId)[0],
            'created', 'last_interaction' => self::number($value, $path, 1, 3650),
            'lead_score' => self::number($value, $path, 0, 100),
            default => throw ValidationException::withMessages(["{$path}.value" => __('crm.segments.invalid_rule')]),
        };

        return $clean;
    }

    /** @param  array<string, mixed>  $rule */
    private static function applyRule(Builder $q, array $rule): void
    {
        $value = $rule['value'] ?? null;
        $openLeads = array_values(array_filter(LeadStatus::values(), fn ($status) => LeadStatus::from($status)->isOpen()));

        match ($rule['field']) {
            'status' => $q->{$rule['operator'] === 'in' ? 'whereIn' : 'whereNotIn'}('customers.status', $value),
            'source' => $rule['operator'] === 'in'
                ? $q->whereIn('customers.source', $value)
                : $q->where(fn (Builder $w) => $w->whereNull('customers.source')->orWhereNotIn('customers.source', $value)),
            'channel' => $rule['operator'] === 'in'
                ? $q->whereHas('contacts', fn (Builder $c) => $c->whereIn('channel', $value))
                : $q->whereDoesntHave('contacts', fn (Builder $c) => $c->whereIn('channel', $value)),
            'tags' => match ($rule['operator']) {
                'has_any' => $q->whereHas('contacts.tags', fn (Builder $t) => $t->whereIn('tags.id', $value)),
                'has_none' => $q->whereDoesntHave('contacts.tags', fn (Builder $t) => $t->whereIn('tags.id', $value)),
                default => collect($value)->each(fn ($tag) => $q->whereHas('contacts.tags', fn (Builder $t) => $t->where('tags.id', $tag))),
            },
            'owner' => self::nullable($q, 'customers.owner_user_id', $rule['operator'], $value),
            'company' => self::nullable($q, 'customers.company_id', $rule['operator'], $value),
            'email' => self::nullable($q, 'customers.email', $rule['operator'], null),
            'phone' => self::nullable($q, 'customers.phone', $rule['operator'], null),
            'created' => $rule['operator'] === 'within_days'
                ? $q->where('customers.created_at', '>=', now()->subDays($value))
                : $q->where('customers.created_at', '<', now()->subDays($value)),
            'last_interaction' => match ($rule['operator']) {
                'never' => $q->whereDoesntHave('conversations'),
                'within_days' => $q->whereHas('conversations', fn (Builder $c) => $c->where('conversations.last_message_at', '>=', now()->subDays($value))),
                default => $q->whereHas('conversations')
                    ->whereDoesntHave('conversations', fn (Builder $c) => $c->where('conversations.last_message_at', '>=', now()->subDays($value))),
            },
            'open_lead' => $q->{$rule['operator'] === 'exists' ? 'whereHas' : 'whereDoesntHave'}('leads', fn (Builder $l) => $l->whereIn('status', $openLeads)),
            'open_deal' => $q->{$rule['operator'] === 'exists' ? 'whereHas' : 'whereDoesntHave'}('deals', fn (Builder $d) => $d->where('status', 'open')),
            'won_deal' => $q->{$rule['operator'] === 'exists' ? 'whereHas' : 'whereDoesntHave'}('deals', fn (Builder $d) => $d->where('status', 'won')),
            'lead_score' => $rule['operator'] === 'at_least'
                ? $q->whereHas('leads', fn (Builder $l) => $l->whereIn('status', $openLeads)->where('score', '>=', $value))
                : $q->whereDoesntHave('leads', fn (Builder $l) => $l->whereIn('status', $openLeads)->where('score', '>=', $value)),
            'custom_field' => match ($rule['operator']) {
                'equals' => $q->where('customers.custom_fields->'.$rule['key'], $value),
                'is_empty' => $q->where(fn (Builder $w) => $w->whereNull('customers.custom_fields')->orWhereNull('customers.custom_fields->'.$rule['key'])),
                default => $q->whereNotNull('customers.custom_fields->'.$rule['key']),
            },
        };
    }

    private static function nullable(Builder $q, string $column, string $operator, mixed $value): void
    {
        match ($operator) {
            'is' => $q->where($column, $value),
            'is_empty' => $q->where(fn (Builder $w) => $w->whereNull($column)->orWhere($column, '')),
            default => $q->whereNotNull($column)->where($column, '!=', ''),
        };
    }

    /** @return list<string> */
    private static function listOf(mixed $value, array $allowed, string $path): array
    {
        $list = array_values(array_unique(array_map('strval', (array) $value)));

        if ($list === [] || array_diff($list, $allowed) !== []) {
            throw ValidationException::withMessages(["{$path}.value" => __('crm.segments.invalid_rule')]);
        }

        return $list;
    }

    /** @return list<string> */
    private static function strings(mixed $value, string $path): array
    {
        $list = array_values(array_unique(array_filter(array_map(fn ($v) => is_scalar($v) ? trim((string) $v) : '', (array) $value), fn ($v) => $v !== '' && mb_strlen($v) <= 40)));

        if ($list === []) {
            throw ValidationException::withMessages(["{$path}.value" => __('crm.segments.invalid_rule')]);
        }

        return $list;
    }

    /** @return list<int> ids that exist in this workspace */
    private static function ids(mixed $value, string $path, string $table, int $tenantId): array
    {
        $ids = array_values(array_unique(array_map('intval', array_filter((array) $value, fn ($v) => is_numeric($v)))));

        if ($ids === [] || \DB::table($table)->where('tenant_id', $tenantId)->whereIn('id', $ids)->count() !== count($ids)) {
            throw ValidationException::withMessages(["{$path}.value" => __('crm.segments.invalid_rule')]);
        }

        return $ids;
    }

    private static function number(mixed $value, string $path, int $min, int $max): int
    {
        if (! is_numeric($value) || (int) $value < $min || (int) $value > $max) {
            throw ValidationException::withMessages(["{$path}.value" => __('crm.segments.invalid_rule')]);
        }

        return (int) $value;
    }
}

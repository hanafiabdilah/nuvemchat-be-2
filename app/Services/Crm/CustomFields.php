<?php

namespace App\Services\Crm;

use App\Enums\Crm\CustomFieldType;
use App\Models\CrmCustomField;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * The workspace's own fields on customers and companies: the definitions, and
 * checking a set of values against them before it is stored.
 */
class CustomFields
{
    public const MAX_FIELDS_PER_ENTITY = 50;

    public const MAX_TEXT = 1000;

    /** @return Collection<int, CrmCustomField> */
    public static function definitions(int $tenantId, string $entity): Collection
    {
        return CrmCustomField::query()
            ->where('tenant_id', $tenantId)
            ->where('entity', $entity)
            ->orderBy('position')
            ->orderBy('id')
            ->get();
    }

    /**
     * Merge `$input` into `$current`, checked against the definitions.
     *
     * A key that is not defined is refused rather than dropped — a value
     * silently thrown away reads as saved. Null (or an empty string) clears a
     * field.
     *
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function apply(int $tenantId, string $entity, array $current, array $input): array
    {
        $definitions = self::definitions($tenantId, $entity)->keyBy('key');
        $errors = [];

        foreach ($input as $key => $value) {
            $definition = $definitions->get($key);

            if (! $definition) {
                $errors["custom_fields.{$key}"] = __('crm.custom_fields.unknown', ['key' => $key]);

                continue;
            }

            if ($value === null || $value === '') {
                unset($current[$key]);

                continue;
            }

            $normalized = self::normalize($definition, $value);

            if ($normalized === null) {
                $errors["custom_fields.{$key}"] = __('crm.custom_fields.invalid', ['field' => $definition->label]);

                continue;
            }

            $current[$key] = $normalized;
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $current;
    }

    /** The value in its stored shape, or null when it does not fit the type. */
    public static function normalize(CrmCustomField $field, mixed $value): mixed
    {
        return match ($field->type) {
            CustomFieldType::Text => is_scalar($value) && mb_strlen((string) $value) <= self::MAX_TEXT ? trim((string) $value) : null,
            CustomFieldType::Number => is_numeric($value) ? $value + 0 : null,
            CustomFieldType::Date => is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) && strtotime($value) !== false ? $value : null,
            CustomFieldType::Boolean => is_bool($value) ? $value : (in_array($value, [0, 1, '0', '1', 'true', 'false'], true) ? filter_var($value, FILTER_VALIDATE_BOOLEAN) : null),
            CustomFieldType::Url => is_string($value) && filter_var($value, FILTER_VALIDATE_URL) && preg_match('#^https?://#i', $value) ? $value : null,
            CustomFieldType::Select => is_string($value) && in_array($value, $field->options ?? [], true) ? $value : null,
        };
    }
}

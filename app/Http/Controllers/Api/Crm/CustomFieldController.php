<?php

namespace App\Http\Controllers\Api\Crm;

use App\Enums\Crm\CustomFieldType;
use App\Http\Controllers\Controller;
use App\Models\CrmCustomField;
use App\Services\Crm\CustomFields;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The workspace's own CRM fields. Reading them is part of reading a customer;
 * defining them is `crm-settings.manage`.
 *
 * Deleting a definition leaves the stored values where they are: they stop
 * being shown, and a field recreated with the same key brings them back —
 * nothing a person typed is destroyed by tidying the field list.
 */
class CustomFieldController extends Controller
{
    public function index(Request $request)
    {
        return response()->json([
            'data' => CrmCustomField::query()
                ->where('tenant_id', $request->user()->tenant_id)
                ->orderBy('entity')
                ->orderBy('position')
                ->orderBy('id')
                ->get()
                ->map(fn (CrmCustomField $field) => $this->present($field))
                ->values(),
        ]);
    }

    public function store(Request $request)
    {
        $tenantId = $request->user()->tenant_id;
        $data = $this->validated($request, creating: true);

        $key = Str::slug($data['key'] ?? $data['label'], '_') ?: 'field';

        if (CrmCustomField::where('tenant_id', $tenantId)->where('entity', $data['entity'])->where('key', $key)->exists()) {
            throw ValidationException::withMessages(['key' => __('crm.custom_fields.key_taken')]);
        }

        if (CrmCustomField::where('tenant_id', $tenantId)->where('entity', $data['entity'])->count() >= CustomFields::MAX_FIELDS_PER_ENTITY) {
            throw ValidationException::withMessages(['label' => __('crm.custom_fields.too_many', ['max' => CustomFields::MAX_FIELDS_PER_ENTITY])]);
        }

        $field = CrmCustomField::create([
            'tenant_id' => $tenantId,
            'entity' => $data['entity'],
            'key' => $key,
            'label' => $data['label'],
            'type' => $data['type'],
            'options' => $data['type'] === CustomFieldType::Select->value ? array_values(array_unique($data['options'] ?? [])) : null,
            'position' => (int) ($data['position'] ?? CrmCustomField::where('tenant_id', $tenantId)->where('entity', $data['entity'])->max('position') + 1),
        ]);

        return response()->json(['data' => $this->present($field)], 201);
    }

    /** The label, the choices and the order — never the key or the type, which stored values depend on. */
    public function update(Request $request, string $id)
    {
        $field = $this->field($request, $id);
        $data = $this->validated($request, creating: false);

        $field->update(array_filter([
            'label' => $data['label'] ?? null,
            'position' => $data['position'] ?? null,
            'options' => $field->type === CustomFieldType::Select && array_key_exists('options', $data)
                ? array_values(array_unique($data['options'] ?? []))
                : null,
        ], fn ($value) => $value !== null));

        return response()->json(['data' => $this->present($field->fresh())]);
    }

    public function destroy(Request $request, string $id)
    {
        $this->field($request, $id)->delete();

        return response()->json(['message' => __('crm.custom_fields.deleted')]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $creating): array
    {
        $data = $request->validate([
            'entity' => [$creating ? 'required' : 'prohibited', Rule::in(CrmCustomField::ENTITIES)],
            'type' => [$creating ? 'required' : 'prohibited', Rule::in(CustomFieldType::values())],
            'key' => [$creating ? 'nullable' : 'prohibited', 'string', 'max:64'],
            'label' => [$creating ? 'required' : 'sometimes', 'string', 'max:100'],
            'options' => ['nullable', 'array', 'max:50'],
            'options.*' => ['string', 'max:100'],
            'position' => ['nullable', 'integer', 'min:0', 'max:1000'],
        ]);

        if ($creating && $data['type'] === CustomFieldType::Select->value && empty($data['options'])) {
            throw ValidationException::withMessages(['options' => __('crm.custom_fields.options_required')]);
        }

        return $data;
    }

    private function field(Request $request, string $id): CrmCustomField
    {
        $field = CrmCustomField::where('tenant_id', $request->user()->tenant_id)->find($id);

        if (! $field) {
            throw new HttpException(404, __('crm.custom_fields.not_found'));
        }

        return $field;
    }

    /** @return array<string, mixed> */
    private function present(CrmCustomField $field): array
    {
        return [
            'id' => $field->id,
            'entity' => $field->entity,
            'key' => $field->key,
            'label' => $field->label,
            'type' => $field->type->value,
            'options' => $field->options ?? [],
            'position' => $field->position,
        ];
    }
}

<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateQuickMessageRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $quickMessage = $this->route('quick_message');

        // Must belong to the same tenant
        if ($quickMessage->tenant_id !== $this->user()->tenant_id) {
            return false;
        }

        // If it's tenant-level, only owner can update
        if ($quickMessage->isTenantLevel()) {
            return $this->user()->role === 'owner';
        }

        // If it's user-specific, only the owner of the message can update
        return $quickMessage->user_id === $this->user()->id;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $quickMessage = $this->route('quick_message');

        return [
            'shortcut' => [
                'sometimes',
                'required',
                'string',
                'max:50',
                Rule::unique('quick_messages')
                    ->where('tenant_id', $quickMessage->tenant_id)
                    ->where('user_id', $quickMessage->user_id)
                    ->ignore($quickMessage->id),
            ],
            'message' => ['sometimes', 'required', 'string', 'max:5000'],
        ];
    }
}

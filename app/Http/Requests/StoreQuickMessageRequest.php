<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreQuickMessageRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // If user_id is null (tenant-level), check if user is owner
        if ($this->input('user_id') === null) {
            return $this->user()->role === 'owner';
        }

        // Allow owner and agent to create user-specific messages
        return in_array($this->user()->role, ['owner', 'agent']);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'tenant_id' => ['required', 'exists:tenants,id'],
            'user_id' => ['nullable', 'exists:users,id'],
            'shortcut' => [
                'required',
                'string',
                'max:50',
                Rule::unique('quick_messages')
                    ->where('tenant_id', $this->user()->tenant_id)
                    ->where('user_id', $this->input('user_id')),
            ],
            'message' => ['required', 'string', 'max:5000'],
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        // If user_id is not provided, set it to the authenticated user's ID
        // unless the user is an owner creating a tenant-level message
        if (!$this->has('user_id') && $this->user()->role !== 'owner') {
            $this->merge([
                'user_id' => $this->user()->id,
            ]);
        }

        // Always set tenant_id to the authenticated user's tenant
        $this->merge([
            'tenant_id' => $this->user()->tenant_id,
        ]);
    }
}

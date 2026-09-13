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
            if (! $this->user()->hasRole('owner')) {
                return false;
            }
        } elseif ($quickMessage->user_id !== $this->user()->id) {
            // If it's user-specific, only the owner of the message can update
            return false;
        }

        // Changing who can use it (the "Availability" field — whole workspace
        // vs. only me) is the same decision as creating a tenant-level message
        // in the first place, so it stays owner-only: an agent editing their
        // own shortcut must not be able to widen it to the whole workspace by
        // sending a different user_id.
        if ($this->has('user_id')) {
            $requestedUserId = $this->input('user_id');
            $requestedUserId = $requestedUserId !== null ? (int) $requestedUserId : null;

            if ($requestedUserId !== $quickMessage->user_id && ! $this->user()->hasRole('owner')) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $quickMessage = $this->route('quick_message');

        // Uniqueness has to be checked against where the shortcut will end up
        // living, not where it lives today — otherwise moving a shortcut from
        // "only me" to "whole workspace" in the same request could collide
        // (or fail to collide) with the wrong set of rows.
        $targetUserId = $this->has('user_id')
            ? ($this->input('user_id') !== null ? (int) $this->input('user_id') : null)
            : $quickMessage->user_id;

        return [
            'shortcut' => [
                'sometimes',
                'required',
                'string',
                'max:50',
                Rule::unique('quick_messages')
                    ->where('tenant_id', $quickMessage->tenant_id)
                    ->where('user_id', $targetUserId)
                    ->ignore($quickMessage->id),
            ],
            'message' => ['sometimes', 'required', 'string', 'max:5000'],
            // Restricted to null (whole workspace) or the caller's own id —
            // the only two values the "Availability" toggle can send, and the
            // only ones the authorize() check above allows through anyway.
            'user_id' => ['sometimes', 'nullable', Rule::in([$this->user()->id])],
        ];
    }
}

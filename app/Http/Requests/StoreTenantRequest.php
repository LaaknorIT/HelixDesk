<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreTenantRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', 'alpha_dash', 'unique:tenants,slug'],
            'status' => ['nullable', 'string', 'in:active,readonly,disabled'],
            'billing_mode' => ['nullable', 'string', 'max:50'],
            'region' => ['nullable', 'string', 'max:50'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Tenant name is required.',
            'slug.alpha_dash' => 'Tenant slug may only contain letters, numbers, dashes, and underscores.',
            'slug.unique' => 'Tenant slug is already in use.',
            'status.in' => 'Tenant status must be active, readonly, or disabled.',
        ];
    }
}

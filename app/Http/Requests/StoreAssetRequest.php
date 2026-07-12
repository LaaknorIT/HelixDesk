<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if ($user === null || $user->tenant_id === null) {
            return false;
        }

        setPermissionsTeamId($user->tenant_id);

        try {
            return $user->hasPermissionTo('asset.create');
        } finally {
            setPermissionsTeamId(null);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $tenantId = $this->user()?->tenant_id;

        return [
            'name' => ['required', 'string', 'max:255'],
            'asset_type_id' => [
                'required',
                'integer',
                Rule::exists('asset_types', 'id')->where('tenant_id', $tenantId),
            ],
            'organization_id' => [
                'nullable',
                'integer',
                Rule::exists('organizations', 'id')->where('tenant_id', $tenantId),
            ],
            'asset_identifier' => ['nullable', 'string', 'max:255'],
            'serial_number' => ['nullable', 'string', 'max:255'],
            'purchase_date' => ['nullable', 'date'],
            'warranty_date' => ['nullable', 'date'],
            'lifecycle_status' => ['nullable', 'string', 'max:64'],
            'owner_user_id' => [
                'nullable',
                'integer',
                Rule::exists('users', 'id')->where('tenant_id', $tenantId),
            ],
        ];
    }
}

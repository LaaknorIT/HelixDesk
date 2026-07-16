<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSupplierRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $tenantId = $user?->operatingTenantId();

        if ($user === null || $tenantId === null || ! $user->canOperateInTenant($tenantId)) {
            return false;
        }

        setPermissionsTeamId($tenantId);

        try {
            return $user->hasPermissionTo('supplier.create');
        } finally {
            setPermissionsTeamId(null);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $tenantId = $this->user()?->operatingTenantId();

        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('suppliers', 'name')->where('tenant_id', $tenantId),
            ],
            'notes' => ['nullable', 'string', 'max:65535'],
        ];
    }
}

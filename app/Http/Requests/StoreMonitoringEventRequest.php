<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMonitoringEventRequest extends FormRequest
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
            return $user->hasPermissionTo('monitoring_event.create');
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
            'message' => ['required', 'string', 'max:65535'],
            'severity' => ['required', 'string', 'max:64'],
            'external_reference' => ['nullable', 'string', 'max:255'],
            'source_slug' => [
                'nullable',
                'string',
                'max:64',
                Rule::exists('monitoring_event_sources', 'slug')->where('tenant_id', $tenantId),
            ],
            'deduplication_key' => ['nullable', 'string', 'max:512'],
        ];
    }
}

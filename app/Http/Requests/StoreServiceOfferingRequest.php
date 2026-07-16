<?php

namespace App\Http\Requests;

use App\Enums\OrganizationType;
use App\Models\Organization;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreServiceOfferingRequest extends FormRequest
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
            return $user->hasPermissionTo('service_offering.create');
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
        $kindKeys = array_keys(config('helixdesk_service_offering.kinds', []));

        return [
            'organization_id' => [
                'required',
                'integer',
                Rule::exists('organizations', 'id')->where('tenant_id', $tenantId),
            ],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:65535'],
            'code' => ['nullable', 'string', 'max:255'],
            'kind' => ['required', 'string', Rule::in($kindKeys)],
            'attributes_json' => ['nullable', 'array'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            /** @var string $kind */
            $kind = $this->input('kind');
            /** @var array<string, mixed> $attrs */
            $attrs = $this->input('attributes_json', []);
            $required = config('helixdesk_service_offering.kinds.'.$kind.'.required_attributes', []);

            foreach ($required as $key) {
                if (! array_key_exists($key, $attrs) || $attrs[$key] === null || $attrs[$key] === '') {
                    $validator->errors()->add(
                        'attributes_json',
                        __('The :key attribute is required for this service kind.', ['key' => $key]),
                    );
                }
            }

            $organizationId = $this->input('organization_id');
            if ($organizationId !== null) {
                $organization = Organization::query()->find($organizationId);
                if ($organization === null || $organization->type !== OrganizationType::Customer) {
                    $validator->errors()->add(
                        'organization_id',
                        __('Service offerings can only be assigned to customer organizations.'),
                    );
                }
            }
        });
    }
}

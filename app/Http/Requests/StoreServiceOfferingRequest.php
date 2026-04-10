<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreServiceOfferingRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if ($user === null || $user->tenant_id === null) {
            return false;
        }

        setPermissionsTeamId($user->tenant_id);

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
        $tenantId = $this->user()?->tenant_id;
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
        });
    }
}

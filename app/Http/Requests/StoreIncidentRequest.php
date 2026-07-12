<?php

namespace App\Http\Requests;

use App\Models\Tenant;
use App\Support\HelixdeskIncidentAuthorization;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreIncidentRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        if ($user === null) {
            return false;
        }

        if ($user->type === 'end_user') {
            return true;
        }

        $queueId = $this->input('queue_id');

        if ($queueId === null) {
            $queueId = Tenant::query()->find($user->tenant_id)?->generalQueue()?->getKey();
        }

        if ($queueId === null) {
            return false;
        }

        return HelixdeskIncidentAuthorization::hasIncidentPermission($user, 'create', (int) $queueId);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $tenantId = $this->user()?->tenant_id;

        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:65535'],
            // Priority is SLA-driven later; default to "normal" when omitted.
            'priority' => ['nullable', 'string', 'max:50'],
            'queue_id' => ['nullable', 'integer'],
            'category_id' => ['nullable', 'integer'],
            'organization_id' => [
                'nullable',
                'integer',
                Rule::exists('organizations', 'id')->where('tenant_id', $tenantId),
            ],
            'reported_by_user_id' => ['nullable', 'integer'],
            'assigned_agent_id' => ['nullable', 'integer'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [];
    }
}

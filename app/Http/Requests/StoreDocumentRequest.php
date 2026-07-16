<?php

namespace App\Http\Requests;

use App\Models\Document;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDocumentRequest extends FormRequest
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
            return $user->hasPermissionTo('document.create');
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
            'doc_type' => ['required', 'string', Rule::in(['kb', 'helpdesk', 'it_doc'])],
            'title' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255'],
            'content' => ['nullable', 'string', 'max:16777215'],
            'status' => ['nullable', 'string', Rule::in(['draft', 'published'])],
            'visibility' => ['nullable', 'string', Rule::in(['public', 'authenticated', 'restricted'])],
            'owner_user_id' => [
                'nullable',
                'integer',
                Rule::exists('users', 'id')->where('tenant_id', $tenantId),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function validatedForCreate(): array
    {
        $data = $this->validated();
        $user = $this->user();
        $tenantId = $user->operatingTenantId();
        $title = $data['title'];
        $slugInput = $data['slug'] ?? null;
        $forSlug = ($slugInput !== null && $slugInput !== '') ? (string) $slugInput : $title;

        $data['slug'] = Document::uniqueSlugForTenant((int) $tenantId, $forSlug);
        $data['tenant_id'] = $tenantId;
        $data['status'] = $data['status'] ?? 'draft';
        $data['visibility'] = $data['visibility'] ?? 'authenticated';
        $data['owner_user_id'] = $data['owner_user_id'] ?? $user->id;

        return $data;
    }
}

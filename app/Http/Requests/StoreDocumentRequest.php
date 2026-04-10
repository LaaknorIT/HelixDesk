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

        if ($user === null || $user->tenant_id === null) {
            return false;
        }

        setPermissionsTeamId($user->tenant_id);

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
        $tenantId = $this->user()?->tenant_id;

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
        $tenantId = $user->tenant_id;
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

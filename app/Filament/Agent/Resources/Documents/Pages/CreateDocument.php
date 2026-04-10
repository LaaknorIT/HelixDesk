<?php

namespace App\Filament\Agent\Resources\Documents\Pages;

use App\Filament\Agent\Resources\Documents\DocumentResource;
use App\Models\Document;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreateDocument extends CreateRecord
{
    protected static string $resource = DocumentResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $tenant = Filament::getTenant();
        $user = Auth::user();

        $data['tenant_id'] = $tenant->getKey();
        $data['owner_user_id'] = $data['owner_user_id'] ?? $user?->id;

        $slugInput = $data['slug'] ?? null;
        $forSlug = ($slugInput !== null && $slugInput !== '') ? (string) $slugInput : (string) $data['title'];
        $data['slug'] = Document::uniqueSlugForTenant((int) $tenant->getKey(), $forSlug);

        return $data;
    }
}

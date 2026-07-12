<?php

namespace App\Filament\Agent\Resources\Organizations\Pages;

use App\Filament\Agent\Resources\Organizations\OrganizationResource;
use App\Models\Organization;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;

class CreateOrganization extends CreateRecord
{
    protected static string $resource = OrganizationResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['tenant_id'] = Filament::getTenant()->getKey();

        if (! isset($data['parent_id']) || $data['parent_id'] === '' || $data['parent_id'] === null) {
            $data['parent_id'] = Organization::defaultParentKey();
        }

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return OrganizationResource::getUrl('index');
    }
}

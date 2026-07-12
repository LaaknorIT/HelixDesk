<?php

namespace App\Filament\Agent\Resources\Organizations\Pages;

use App\Filament\Agent\Resources\Organizations\OrganizationResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditOrganization extends EditRecord
{
    protected static string $resource = OrganizationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return OrganizationResource::getUrl('index');
    }
}

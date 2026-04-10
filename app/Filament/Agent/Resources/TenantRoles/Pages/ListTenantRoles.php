<?php

namespace App\Filament\Agent\Resources\TenantRoles\Pages;

use App\Filament\Agent\Resources\TenantRoles\TenantRoleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTenantRoles extends ListRecords
{
    protected static string $resource = TenantRoleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}

<?php

namespace App\Filament\Agent\Resources\TenantRoles\Pages;

use App\Filament\Agent\Resources\TenantRoles\TenantRoleResource;
use App\Services\RolePermissionSync;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;

class CreateTenantRole extends CreateRecord
{
    protected static string $resource = TenantRoleResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $tenant = Filament::getTenant();
        abort_if($tenant === null, 404);

        return [
            'name' => $data['name'],
            'guard_name' => config('auth.defaults.guard', 'web'),
            'team_id' => $tenant->getKey(),
        ];
    }

    protected function afterCreate(): void
    {
        RolePermissionSync::sync($this->getRecord(), []);
    }
}

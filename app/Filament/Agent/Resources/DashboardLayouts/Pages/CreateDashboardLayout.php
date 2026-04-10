<?php

namespace App\Filament\Agent\Resources\DashboardLayouts\Pages;

use App\Filament\Agent\Resources\DashboardLayouts\DashboardLayoutResource;
use App\Models\DashboardLayout;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;

class CreateDashboardLayout extends CreateRecord
{
    protected static string $resource = DashboardLayoutResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['tenant_id'] = Filament::getTenant()?->getKey();

        if (($data['is_active'] ?? true) === true) {
            DashboardLayout::query()
                ->where('tenant_id', $data['tenant_id'])
                ->where('scope', $data['scope'])
                ->when(
                    $data['scope'] === DashboardLayout::SCOPE_ROLE_DEFAULT,
                    fn ($query) => $query->where('role_name', $data['role_name']),
                    fn ($query) => $query->where('user_id', $data['user_id']),
                )
                ->update(['is_active' => false]);
        }

        return $data;
    }
}

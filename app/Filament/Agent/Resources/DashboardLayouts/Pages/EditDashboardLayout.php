<?php

namespace App\Filament\Agent\Resources\DashboardLayouts\Pages;

use App\Filament\Agent\Resources\DashboardLayouts\DashboardLayoutResource;
use App\Models\DashboardLayout;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditDashboardLayout extends EditRecord
{
    protected static string $resource = DashboardLayoutResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (($data['is_active'] ?? false) === true) {
            DashboardLayout::query()
                ->where('tenant_id', $data['tenant_id'])
                ->where('scope', $data['scope'])
                ->whereKeyNot($this->record->getKey())
                ->when(
                    $data['scope'] === DashboardLayout::SCOPE_ROLE_DEFAULT,
                    fn ($query) => $query->where('role_name', $data['role_name']),
                    fn ($query) => $query->where('user_id', $data['user_id']),
                )
                ->update(['is_active' => false]);
        }

        return $data;
    }

    protected function authorizeAccess(): void
    {
        parent::authorizeAccess();

        abort_unless((int) $this->record->tenant_id === (int) auth()->user()?->tenant_id, 403);
    }
}

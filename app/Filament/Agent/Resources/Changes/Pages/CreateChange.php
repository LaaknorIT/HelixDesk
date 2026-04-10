<?php

namespace App\Filament\Agent\Resources\Changes\Pages;

use App\Filament\Agent\Resources\Changes\ChangeResource;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreateChange extends CreateRecord
{
    protected static string $resource = ChangeResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $tenant = Filament::getTenant();
        $user = Auth::user();

        $data['tenant_id'] = $tenant->getKey();
        $data['status'] = 'Draft';
        $data['requested_by_user_id'] = $user?->id;
        $data['approved_by_user_id'] = null;

        return $data;
    }
}

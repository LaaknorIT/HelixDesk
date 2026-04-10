<?php

namespace App\Filament\Agent\Resources\Problems\Pages;

use App\Filament\Agent\Resources\Problems\ProblemResource;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;

class CreateProblem extends CreateRecord
{
    protected static string $resource = ProblemResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $tenant = Filament::getTenant();

        $data['tenant_id'] = $tenant->getKey();

        return $data;
    }
}

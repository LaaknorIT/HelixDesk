<?php

namespace App\Filament\Agent\Resources\SoftwareDeployments\Pages;

use App\Filament\Agent\Resources\SoftwareDeployments\SoftwareDeploymentResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSoftwareDeployments extends ListRecords
{
    protected static string $resource = SoftwareDeploymentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}

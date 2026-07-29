<?php

namespace App\Filament\Agent\Resources\SoftwareDeployments\Pages;

use App\Filament\Agent\Resources\SoftwareDeployments\SoftwareDeploymentResource;
use App\Filament\Agent\Resources\SoftwareProducts\SoftwareProductResource;
use App\Models\SoftwareDeployment;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditSoftwareDeployment extends EditRecord
{
    protected static string $resource = SoftwareDeploymentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('partnerDocumentation')
                ->label('Partner / product documentation')
                ->icon('heroicon-o-book-open')
                ->url(function (): ?string {
                    /** @var SoftwareDeployment $record */
                    $record = $this->getRecord();

                    if ($record->software_product_id === null) {
                        return null;
                    }

                    return SoftwareProductResource::getUrl('edit', [
                        'record' => $record->software_product_id,
                    ]).'?activeRelationManager=1';
                })
                ->visible(fn (): bool => auth()->user()?->hasPermissionTo('software_product.read') ?? false),
            DeleteAction::make(),
        ];
    }
}

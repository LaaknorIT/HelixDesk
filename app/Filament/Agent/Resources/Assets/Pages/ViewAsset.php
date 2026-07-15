<?php

namespace App\Filament\Agent\Resources\Assets\Pages;

use App\Filament\Agent\Resources\Assets\AssetResource;
use App\Filament\Agent\Resources\Assets\Schemas\AssetInfolist;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Schema;

class ViewAsset extends ViewRecord
{
    protected static string $resource = AssetResource::class;

    public function infolist(Schema $schema): Schema
    {
        return AssetInfolist::configure($schema);
    }

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()
                ->visible(fn (): bool => AssetResource::canEdit($this->record)),
        ];
    }
}

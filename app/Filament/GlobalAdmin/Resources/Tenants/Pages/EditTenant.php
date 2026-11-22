<?php

namespace App\Filament\GlobalAdmin\Resources\Tenants\Pages;

use App\Filament\GlobalAdmin\Resources\Tenants\TenantResource;
use App\Models\Tenant;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\EditRecord;

class EditTenant extends EditRecord
{
    protected static string $resource = TenantResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->modalDescription(fn (Tenant $record): string => __('This deletes :name and everything in it: tickets, customers, assets and users. It cannot be undone.', ['name' => $record->name]))
                ->schema([
                    TextInput::make('confirmation')
                        ->label(fn (Tenant $record): string => __('Type ":name" to confirm', ['name' => $record->name]))
                        ->required()
                        ->in(fn (Tenant $record): array => [$record->name])
                        ->validationMessages([
                            'in' => __('The name does not match.'),
                        ]),
                ]),
        ];
    }
}

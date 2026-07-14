<?php

namespace App\Filament\Agent\Resources\Organizations\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;

final class OrganizationEntraIntegrationInlineSchema
{
    /**
     * @return list<TextInput|Toggle>
     */
    public static function fields(): array
    {
        return [
            TextInput::make('entra_azure_tenant_id')
                ->label(__('Directory (tenant) ID'))
                ->maxLength(64)
                ->required(),
            TextInput::make('entra_application_client_id')
                ->label(__('Application (client) ID'))
                ->maxLength(64)
                ->required(),
            TextInput::make('entra_client_secret')
                ->label(__('Client secret'))
                ->password()
                ->revealable()
                ->maxLength(512)
                ->required(),
            Toggle::make('entra_enabled')
                ->label(__('Enable integration'))
                ->default(true),
        ];
    }
}

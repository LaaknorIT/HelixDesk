<?php

namespace App\Filament\Agent\Resources\Organizations\Schemas;

use App\Support\MicrosoftEntraIntegrationCatalog;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Callout;
use Filament\Support\Icons\Heroicon;

final class OrganizationEntraIntegrationInlineSchema
{
    /**
     * @return list<Callout|Hidden|TextInput|Toggle>
     */
    public static function fields(): array
    {
        return [
            Callout::make(__('Required Microsoft Graph permissions for mail'))
                ->icon(Heroicon::OutlinedKey)
                ->description(MicrosoftEntraIntegrationCatalog::mailPermissionsSummaryHtml()),
            Hidden::make('entra_scope_mail')
                ->default(true)
                ->dehydrated(),
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

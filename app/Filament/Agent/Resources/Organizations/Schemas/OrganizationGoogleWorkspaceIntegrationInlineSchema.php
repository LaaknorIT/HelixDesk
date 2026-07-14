<?php

namespace App\Filament\Agent\Resources\Organizations\Schemas;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;

final class OrganizationGoogleWorkspaceIntegrationInlineSchema
{
    /**
     * @return list<TextInput|Textarea|Toggle>
     */
    public static function fields(): array
    {
        return [
            TextInput::make('gws_customer_domain')
                ->label(__('Workspace domain'))
                ->placeholder('acme.example')
                ->maxLength(255)
                ->required()
                ->helperText(__('Primary Google Workspace domain for this customer.')),
            TextInput::make('gws_service_account_client_email')
                ->label(__('Service account email'))
                ->email()
                ->maxLength(255)
                ->required(),
            Textarea::make('gws_service_account_private_key')
                ->label(__('Service account private key'))
                ->rows(4)
                ->required()
                ->helperText(__('Paste the JSON key or PEM private key from Google Cloud Console.')),
            TextInput::make('gws_domain_wide_delegation_client_id')
                ->label(__('Domain-wide delegation client ID'))
                ->maxLength(64)
                ->required()
                ->helperText(__('Numeric client ID from the service account; authorize Gmail scopes in Workspace Admin.')),
            Toggle::make('gws_enabled')
                ->label(__('Enable integration'))
                ->default(true),
        ];
    }
}

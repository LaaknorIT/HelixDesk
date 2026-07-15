<?php

namespace App\Filament\Agent\Resources\Organizations\Schemas;

use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class OrganizationGoogleWorkspaceSettingsSchema
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Google Workspace'))
                    ->description(__('Connect Gmail API mail for this customer organization.'))
                    ->schema(OrganizationGoogleWorkspaceIntegrationInlineSchema::fields())
                    ->columns(1),
            ]);
    }
}

<?php

namespace App\Filament\Agent\Resources\Organizations\Schemas;

use App\Filament\Agent\Resources\Organizations\Pages\EditOrganizationIntegration;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\UnorderedList;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Livewire\Component;

class OrganizationGoogleWorkspaceSettingsSchema
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Wizard::make([
                    Step::make(__('Overview'))
                        ->description(__('What HelixDesk does with Google Workspace.'))
                        ->icon(Heroicon::OutlinedInformationCircle)
                        ->schema([
                            Callout::make(__('Google Workspace mail'))
                                ->icon(Heroicon::OutlinedEnvelope)
                                ->info()
                                ->description(__('Connect Gmail API mail for this customer organization. HelixDesk reads support mailboxes, marks processed messages and sends replies through Gmail.')),
                            Text::make(__('Before you start, in Google Cloud and Workspace Admin:')),
                            UnorderedList::make([
                                Text::make(__('Create a service account in a Google Cloud project and download a JSON key.')),
                                Text::make(__('Enable the Gmail API for that project.')),
                                Text::make(__('In Workspace Admin → Security → API controls → Domain-wide delegation, authorize the service account client ID for the gmail.readonly, gmail.send and gmail.modify scopes.')),
                            ]),
                        ]),
                    Step::make(__('Connect'))
                        ->description(__('Service account details.'))
                        ->icon(Heroicon::OutlinedKey)
                        ->schema(OrganizationGoogleWorkspaceIntegrationInlineSchema::fields())
                        ->columns(1),
                ])
                    ->skippable(fn (Component $livewire): bool => $livewire instanceof EditOrganizationIntegration)
                    ->columnSpanFull(),
            ]);
    }
}

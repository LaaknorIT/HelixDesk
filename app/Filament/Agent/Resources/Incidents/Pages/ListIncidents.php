<?php

namespace App\Filament\Agent\Resources\Incidents\Pages;

use App\Filament\Agent\Resources\Incidents\IncidentResource;
use App\Services\IncidentWorkflowService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Auth;

class ListIncidents extends ListRecords
{
    protected static string $resource = IncidentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('createIncident')
                ->label('New incident')
                ->icon('heroicon-o-plus')
                ->modalHeading('Create incident')
                ->form([
                    TextInput::make('title')
                        ->label('Title')
                        ->required()
                        ->maxLength(255),
                    Textarea::make('description')
                        ->label('Description')
                        ->rows(4),
                    Select::make('priority')
                        ->label('Priority')
                        ->required()
                        ->options([
                            'Low' => 'Low',
                            'Medium' => 'Medium',
                            'High' => 'High',
                            'Critical' => 'Critical',
                        ]),
                ])
                ->action(function (array $data): void {
                    $user = Auth::user();

                    if ($user === null) {
                        return;
                    }

                    app(IncidentWorkflowService::class)->createIncident($data, $user);
                }),
        ];
    }
}

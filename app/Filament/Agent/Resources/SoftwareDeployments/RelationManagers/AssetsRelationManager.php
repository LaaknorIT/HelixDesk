<?php

namespace App\Filament\Agent\Resources\SoftwareDeployments\RelationManagers;

use Filament\Actions\AttachAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DetachAction;
use Filament\Actions\DetachBulkAction;
use Filament\Forms\Components\Select;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AssetsRelationManager extends RelationManager
{
    protected static string $relationship = 'assets';

    protected static ?string $title = 'Related assets';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('pivot.role')
                    ->label('Role')
                    ->badge(),
                TextColumn::make('organization.name')
                    ->label('Organization')
                    ->placeholder('—'),
            ])
            ->headerActions([
                AttachAction::make()
                    ->form(fn (AttachAction $action): array => [
                        $action->getRecordSelect(),
                        Select::make('role')
                            ->options([
                                'hosted_on' => 'Hosted on',
                                'related' => 'Related',
                                'identity_provider' => 'Identity provider',
                            ])
                            ->required()
                            ->default('related'),
                    ])
                    ->preloadRecordSelect()
                    ->visible(fn (): bool => auth()->user()?->hasPermissionTo('software_deployment.update') ?? false),
            ])
            ->recordActions([
                DetachAction::make()
                    ->visible(fn (): bool => auth()->user()?->hasPermissionTo('software_deployment.update') ?? false),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DetachBulkAction::make()
                        ->visible(fn (): bool => auth()->user()?->hasPermissionTo('software_deployment.update') ?? false),
                ]),
            ]);
    }
}

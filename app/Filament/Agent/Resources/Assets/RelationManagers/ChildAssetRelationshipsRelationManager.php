<?php

namespace App\Filament\Agent\Resources\Assets\RelationManagers;

use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ChildAssetRelationshipsRelationManager extends RelationManager
{
    protected static string $relationship = 'parentRelationships';

    protected static ?string $title = 'Downstream (this asset → related)';

    protected static bool $shouldSkipAuthorization = true;

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Hidden::make('tenant_id')
                ->default(fn (): int => $this->ownerRecord->tenant_id),
            Select::make('child_asset_id')
                ->label('Related asset')
                ->relationship(
                    'childAsset',
                    'name',
                    modifyQueryUsing: fn (Builder $query): Builder => $query->where(
                        'assets.id',
                        '!=',
                        $this->ownerRecord->getKey(),
                    ),
                )
                ->searchable()
                ->preload()
                ->required(),
            TextInput::make('relationship_type')
                ->label('Relationship type')
                ->required()
                ->maxLength(255)
                ->helperText('Examples: contains, depends_on, runs_on, connected_to'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('relationship_type')
            ->columns([
                TextColumn::make('childAsset.name')
                    ->label('Asset')
                    ->searchable(),
                TextColumn::make('childAsset.assetType.name')
                    ->label('Type'),
                TextColumn::make('relationship_type')
                    ->label('Relationship')
                    ->badge(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->visible(fn (): bool => auth()->user()?->hasPermissionTo('asset.update') ?? false),
            ])
            ->recordActions([
                EditAction::make()
                    ->visible(fn (): bool => auth()->user()?->hasPermissionTo('asset.update') ?? false),
                DeleteAction::make()
                    ->visible(fn (): bool => auth()->user()?->hasPermissionTo('asset.update') ?? false),
            ]);
    }
}

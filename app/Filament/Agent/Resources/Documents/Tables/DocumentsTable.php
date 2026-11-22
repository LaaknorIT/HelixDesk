<?php

namespace App\Filament\Agent\Resources\Documents\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class DocumentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('doc_type')
                    ->label(__('Type'))
                    ->badge()
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->sortable(),
                TextColumn::make('visibility')
                    ->badge()
                    ->toggleable(),
                TextColumn::make('owner.name')
                    ->label(__('Owner'))
                    ->placeholder(__('—')),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('doc_type')
                    ->options([
                        'kb' => __('Knowledge base'),
                        'helpdesk' => __('Helpdesk guide'),
                        'it_doc' => __('IT operations doc'),
                    ]),
                SelectFilter::make('status')
                    ->options([
                        'draft' => __('Draft'),
                        'published' => __('Published'),
                    ]),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}

<?php

namespace App\Filament\Agent\Resources\Documents\Schemas;

use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class DocumentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('doc_type')
                    ->label(__('Type'))
                    ->required()
                    ->options([
                        'kb' => __('Knowledge base'),
                        'helpdesk' => __('Helpdesk guide'),
                        'it_doc' => __('IT operations doc'),
                    ]),
                TextInput::make('title')
                    ->required()
                    ->maxLength(255),
                TextInput::make('slug')
                    ->label(__('URL slug'))
                    ->maxLength(255)
                    ->helperText(__('Leave blank on create to generate from the title.')),
                Textarea::make('content')
                    ->rows(12)
                    ->columnSpanFull(),
                Select::make('status')
                    ->options([
                        'draft' => __('Draft'),
                        'published' => __('Published'),
                    ])
                    ->required()
                    ->default('draft'),
                Select::make('visibility')
                    ->options([
                        'public' => __('Public'),
                        'authenticated' => __('Authenticated users'),
                        'restricted' => __('Restricted'),
                    ])
                    ->required()
                    ->default('authenticated'),
                Select::make('owner_user_id')
                    ->label(__('Owner'))
                    ->relationship(
                        name: 'owner',
                        titleAttribute: 'name',
                        modifyQueryUsing: fn (Builder $query): Builder => $query->where(
                            'tenant_id',
                            Filament::getTenant()->getKey()
                        ),
                    )
                    ->searchable()
                    ->preload(),
            ]);
    }
}

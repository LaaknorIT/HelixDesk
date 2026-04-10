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
                    ->label('Type')
                    ->required()
                    ->options([
                        'kb' => 'Knowledge base',
                        'helpdesk' => 'Helpdesk guide',
                        'it_doc' => 'IT operations doc',
                    ]),
                TextInput::make('title')
                    ->required()
                    ->maxLength(255),
                TextInput::make('slug')
                    ->label('URL slug')
                    ->maxLength(255)
                    ->helperText('Leave blank on create to generate from the title.'),
                Textarea::make('content')
                    ->rows(12)
                    ->columnSpanFull(),
                Select::make('status')
                    ->options([
                        'draft' => 'Draft',
                        'published' => 'Published',
                    ])
                    ->required()
                    ->default('draft'),
                Select::make('visibility')
                    ->options([
                        'public' => 'Public',
                        'authenticated' => 'Authenticated users',
                        'restricted' => 'Restricted',
                    ])
                    ->required()
                    ->default('authenticated'),
                Select::make('owner_user_id')
                    ->label('Owner')
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

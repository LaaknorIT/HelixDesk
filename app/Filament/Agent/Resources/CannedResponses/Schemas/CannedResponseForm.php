<?php

namespace App\Filament\Agent\Resources\CannedResponses\Schemas;

use App\Models\Queue;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class CannedResponseForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('title')
                    ->required()
                    ->maxLength(255),
                Select::make('queue_id')
                    ->label(__('Queue'))
                    ->placeholder(__('All queues'))
                    ->helperText(__('Leave empty to offer this response on every ticket.'))
                    ->options(fn (): array => Queue::query()
                        ->where('tenant_id', Filament::getTenant()?->getKey())
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all()),
                Textarea::make('body')
                    ->label(__('Text'))
                    ->required()
                    ->rows(8)
                    ->maxLength(20000)
                    ->helperText(__('Placeholders: {{requester.name}}, {{requester.first_name}}, {{ticket.id}}, {{ticket.title}}, {{agent.name}}, {{queue.name}}'))
                    ->columnSpanFull(),
            ]);
    }
}

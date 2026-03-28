<?php

namespace App\Filament\EndUser\Resources\Incidents\Pages;

use App\Filament\EndUser\Resources\Incidents\EndUserIncidentResource;
use App\Models\Incident;
use App\Services\IncidentWorkflowService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Pages\ViewRecord;

class ViewEndUserIncident extends ViewRecord
{
    protected static string $resource = EndUserIncidentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('reply')
                ->label('Reply')
                ->icon('heroicon-o-chat-bubble-left-right')
                ->form([
                    Textarea::make('body')
                        ->label('Message')
                        ->required()
                        ->rows(4),
                    Select::make('parent_message_id')
                        ->label('Reply to')
                        ->placeholder('Top-level reply')
                        ->options(function (): array {
                            /** @var Incident $record */
                            $record = $this->getRecord();

                            return $record->messages()
                                ->where('is_internal', false)
                                ->orderByDesc('created_at')
                                ->limit(50)
                                ->get()
                                ->mapWithKeys(fn ($message): array => [
                                    $message->getKey() => '#'.$message->getKey().' '.$message->body,
                                ])
                                ->all();
                        }),
                ])
                ->action(function (array $data): void {
                    $user = auth()->user();

                    if ($user === null) {
                        return;
                    }

                    app(IncidentWorkflowService::class)->addMessage(
                        $this->getRecord(),
                        $data['body'],
                        $user,
                        $data['parent_message_id'] ?? null,
                    );
                }),
            Action::make('close')
                ->label('Close')
                ->icon('heroicon-o-x-circle')
                ->requiresConfirmation()
                ->visible(function (): bool {
                    $user = auth()->user();

                    if ($user === null) {
                        return false;
                    }

                    return array_key_exists(
                        'Closed',
                        app(IncidentWorkflowService::class)->allowedTransitions($this->getRecord(), $user),
                    );
                })
                ->action(function (): void {
                    $user = auth()->user();

                    if ($user === null) {
                        return;
                    }

                    app(IncidentWorkflowService::class)->transition($this->getRecord(), 'Closed', $user);
                }),
        ];
    }
}

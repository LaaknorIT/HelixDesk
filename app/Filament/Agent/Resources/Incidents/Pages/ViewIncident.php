<?php

namespace App\Filament\Agent\Resources\Incidents\Pages;

use App\Filament\Agent\Resources\Incidents\IncidentResource;
use App\Models\Incident;
use App\Services\IncidentWorkflowService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Pages\ViewRecord;

class ViewIncident extends ViewRecord
{
    protected static string $resource = IncidentResource::class;

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
            Action::make('internalNote')
                ->label('Add internal note')
                ->icon('heroicon-o-pencil-square')
                ->form([
                    Textarea::make('body')
                        ->label('Internal note')
                        ->required()
                        ->rows(4),
                ])
                ->action(function (array $data): void {
                    $user = auth()->user();

                    if ($user === null) {
                        return;
                    }

                    app(IncidentWorkflowService::class)->addInternalNote($this->getRecord(), $data['body'], $user);
                }),
            Action::make('transition')
                ->label('Update status')
                ->icon('heroicon-o-arrow-path')
                ->form([
                    Select::make('to_status')
                        ->label('To status')
                        ->required()
                        ->options(function (): array {
                            $user = auth()->user();

                            if ($user === null) {
                                return [];
                            }

                            return app(IncidentWorkflowService::class)->allowedTransitions($this->getRecord(), $user);
                        }),
                ])
                ->action(function (array $data): void {
                    $user = auth()->user();

                    if ($user === null) {
                        return;
                    }

                    app(IncidentWorkflowService::class)->transition($this->getRecord(), $data['to_status'], $user);
                }),
            Action::make('priority')
                ->label('Update priority')
                ->icon('heroicon-o-flag')
                ->form([
                    Select::make('priority')
                        ->required()
                        ->options([
                            'normal' => 'Normal',
                            'high' => 'High',
                            'urgent' => 'Urgent',
                        ]),
                ])
                ->action(function (array $data): void {
                    $record = $this->getRecord();
                    $record->priority = $data['priority'];
                    $record->save();
                }),
        ];
    }
}

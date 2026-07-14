<?php

namespace App\Filament\EndUser\Resources\WorkItems\Pages;

use App\Filament\EndUser\Resources\WorkItems\EndUserWorkItemResource;
use App\Models\WorkItem;
use App\Services\WorkItemWorkflowService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Pages\ViewRecord;

class ViewEndUserWorkItem extends ViewRecord
{
    protected static string $resource = EndUserWorkItemResource::class;

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
                            /** @var WorkItem $record */
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

                    app(WorkItemWorkflowService::class)->addMessage(
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
                        app(WorkItemWorkflowService::class)->allowedTransitions($this->getRecord(), $user),
                    );
                })
                ->action(function (): void {
                    $user = auth()->user();

                    if ($user === null) {
                        return;
                    }

                    app(WorkItemWorkflowService::class)->transition($this->getRecord(), 'Closed', $user);
                }),
        ];
    }
}

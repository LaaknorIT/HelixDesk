<?php

namespace App\Filament\EndUser\Resources\WorkItems\Pages;

use App\Filament\EndUser\Resources\WorkItems\EndUserWorkItemResource;
use App\Models\User;
use App\Services\WorkItemWorkflowService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Auth;

class ViewEndUserWorkItem extends ViewRecord
{
    protected static string $resource = EndUserWorkItemResource::class;

    public function getTitle(): string|Htmlable
    {
        return '#'.$this->getRecord()->getKey().' · '.$this->getRecord()->title;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('reply')
                ->label(__('Reply'))
                ->icon(Heroicon::OutlinedChatBubbleLeftRight)
                ->modalHeading(__('Reply to the service desk'))
                ->modalSubmitActionLabel(__('Send reply'))
                ->schema([
                    Textarea::make('body')
                        ->label(__('Message'))
                        ->required()
                        ->rows(5),
                ])
                ->action(function (array $data): void {
                    $user = Auth::user();

                    if (! $user instanceof User) {
                        return;
                    }

                    app(WorkItemWorkflowService::class)->addMessage($this->getRecord(), $data['body'], $user);

                    Notification::make()
                        ->title(__('Reply sent'))
                        ->success()
                        ->send();

                    $this->getRecord()->refresh();
                }),
            Action::make('close')
                ->label(__('Mark as solved'))
                ->icon(Heroicon::OutlinedCheckCircle)
                ->color('gray')
                ->modalHeading(__('Is your issue solved?'))
                ->modalDescription(__('We will close the ticket. If the problem comes back, report a new issue and mention this ticket number.'))
                ->modalSubmitActionLabel(__('Yes, close the ticket'))
                ->schema([
                    Textarea::make('comment')
                        ->label(__('Comment (optional)'))
                        ->placeholder(__('For example: what fixed it'))
                        ->rows(3),
                ])
                ->visible(function (): bool {
                    $user = Auth::user();

                    if (! $user instanceof User) {
                        return false;
                    }

                    return array_key_exists(
                        'Closed',
                        app(WorkItemWorkflowService::class)->allowedTransitions($this->getRecord(), $user),
                    );
                })
                ->action(function (array $data): void {
                    $user = Auth::user();

                    if (! $user instanceof User) {
                        return;
                    }

                    $workflow = app(WorkItemWorkflowService::class);

                    if (filled($data['comment'] ?? null)) {
                        $workflow->addMessage($this->getRecord(), (string) $data['comment'], $user);
                    }

                    $workflow->transition($this->getRecord(), 'Closed', $user);

                    Notification::make()
                        ->title(__('Ticket closed'))
                        ->body(__('Thank you for letting us know.'))
                        ->success()
                        ->send();

                    $this->getRecord()->refresh();
                }),
        ];
    }
}

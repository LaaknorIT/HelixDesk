<?php

namespace App\Filament\Agent\Resources\Changes\Pages;

use App\Filament\Agent\Resources\Changes\ChangeResource;
use App\Models\Change;
use App\Services\ChangeWorkflowService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Select;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Auth;

class EditChange extends EditRecord
{
    protected static string $resource = ChangeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('transition')
                ->label('Update status')
                ->icon('heroicon-o-arrow-path')
                ->visible(fn (): bool => Auth::user()?->hasAnyPermission(['change.update', 'change.approve', 'change.admin']) === true)
                ->form([
                    Select::make('to_status')
                        ->label('To status')
                        ->required()
                        ->options(function (): array {
                            /** @var Change $record */
                            $record = $this->getRecord();
                            $user = Auth::user();

                            if ($user === null) {
                                return [];
                            }

                            return app(ChangeWorkflowService::class)->allowedTransitions($record, $user);
                        }),
                ])
                ->action(function (array $data): void {
                    $user = Auth::user();

                    if ($user === null) {
                        return;
                    }

                    app(ChangeWorkflowService::class)->transition(
                        $this->getRecord(),
                        $data['to_status'],
                        $user
                    );
                }),
            DeleteAction::make(),
        ];
    }
}

<?php

namespace App\Filament\Agent\Resources\MailAccounts\Actions;

use App\Filament\Agent\Resources\MailAccounts\MailAccountResource;
use App\Models\MailAccount;
use App\Services\Mail\MailAccountConnectionVerifier;
use App\Services\Mail\MailAccountPollService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

final class MailAccountOperationalActions
{
    public static function testConnection(): Action
    {
        return Action::make('testConnection')
            ->label('Test connection')
            ->icon(Heroicon::OutlinedSignal)
            ->color('gray')
            ->visible(fn (?MailAccount $record): bool => $record !== null && MailAccountResource::canEdit($record))
            ->action(function (MailAccount $record): void {
                $result = app(MailAccountConnectionVerifier::class)->verify($record);

                $notification = Notification::make()
                    ->title($result->ok ? 'Connection OK' : 'Connection failed')
                    ->body($result->message)
                    ->persistent();

                if ($result->ok) {
                    $notification->success();
                } else {
                    $notification->danger();
                }

                $notification->send();
            });
    }

    public static function checkEmail(): Action
    {
        return Action::make('checkEmail')
            ->label('Check email')
            ->icon(Heroicon::OutlinedInboxArrowDown)
            ->color('primary')
            ->requiresConfirmation()
            ->modalHeading('Check email now')
            ->modalDescription('Run inbound polling for this mail account immediately (same job as the scheduler).')
            ->visible(fn (?MailAccount $record): bool => $record !== null && MailAccountResource::canEdit($record))
            ->action(function (MailAccount $record): void {
                $result = app(MailAccountPollService::class)->poll($record->fresh() ?? $record);
                $record->refresh();

                $notification = Notification::make()
                    ->title($result->succeeded() ? 'Email check finished' : 'Email check failed')
                    ->body($result->summary())
                    ->persistent();

                if ($result->succeeded()) {
                    $notification->success();
                } else {
                    $notification->danger();
                }

                $notification->send();
            });
    }
}

<?php

namespace App\Filament\Agent\Pages;

use App\Enums\AgentNotificationChannel;
use App\Enums\WorkItemNotificationEvent;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

class NotificationSettings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBell;

    protected static ?string $title = 'Notification settings';

    protected static bool $shouldRegisterNavigation = false;

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->isTenantStaff();
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        /** @var User $user */
        $user = Auth::user();
        $state = [];

        foreach (AgentNotificationChannel::cases() as $channel) {
            $state[$channel->value] = array_values(array_filter(
                array_keys(WorkItemNotificationEvent::options()),
                fn (string $event): bool => $user->wantsWorkItemNotification(WorkItemNotificationEvent::from($event), $channel),
            ));
        }

        $this->form->fill($state);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                CheckboxList::make(AgentNotificationChannel::Mail->value)
                    ->label('Email me about')
                    ->options(WorkItemNotificationEvent::options())
                    ->bulkToggleable(),
                CheckboxList::make(AgentNotificationChannel::Database->value)
                    ->label('Show in HelixDesk')
                    ->helperText('In-app notifications appear under the bell icon.')
                    ->options(WorkItemNotificationEvent::options())
                    ->bulkToggleable(),
            ])
            ->statePath('data');
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                Form::make([EmbeddedSchema::make('form')])
                    ->id('form')
                    ->livewireSubmitHandler('save')
                    ->footer([
                        Actions::make([
                            Action::make('save')
                                ->label('Save')
                                ->submit('save'),
                        ]),
                    ]),
            ]);
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);

        /** @var User $user */
        $user = Auth::user();
        $state = $this->form->getState();
        $validEvents = array_keys(WorkItemNotificationEvent::options());
        $preferences = [];

        foreach (AgentNotificationChannel::cases() as $channel) {
            $preferences[$channel->value] = array_values(array_intersect($validEvents, $state[$channel->value] ?? []));
        }

        $user->forceFill(['notification_preferences' => $preferences])->save();

        Notification::make()
            ->title('Notification settings saved')
            ->success()
            ->send();
    }
}

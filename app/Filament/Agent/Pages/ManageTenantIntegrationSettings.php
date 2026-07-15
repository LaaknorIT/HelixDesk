<?php

namespace App\Filament\Agent\Pages;

use App\Support\TenantIntegrationSettings;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

class ManageTenantIntegrationSettings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static ?string $navigationLabel = 'Integration settings';

    protected static ?string $title = 'Integration settings';

    protected static string|UnitEnum|null $navigationGroup = 'Administration';

    protected static ?int $navigationSort = 53;

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        $user = Auth::user();

        if ($user === null || ! $user->isTenantStaff()) {
            return false;
        }

        return $user->hasPermissionTo('tenant.admin');
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $tenant = Filament::getTenant();
        abort_if($tenant === null, 404);

        $settings = TenantIntegrationSettings::forTenant($tenant);
        $this->form->fill([
            TenantIntegrationSettings::AUTO_RETIRE_ASSETS_WHEN_ALL_PROVIDERS_DROP => (bool) ($settings[TenantIntegrationSettings::AUTO_RETIRE_ASSETS_WHEN_ALL_PROVIDERS_DROP] ?? false),
            TenantIntegrationSettings::RETIREMENT_GRACE_DAYS => (int) ($settings[TenantIntegrationSettings::RETIREMENT_GRACE_DAYS] ?? 30),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Toggle::make(TenantIntegrationSettings::AUTO_RETIRE_ASSETS_WHEN_ALL_PROVIDERS_DROP)
                    ->label(__('Automatically retire assets when all providers drop them'))
                    ->helperText(__('When enabled, assets with no active external identifiers after the grace period are set to retired.')),
                TextInput::make(TenantIntegrationSettings::RETIREMENT_GRACE_DAYS)
                    ->label(__('Retirement grace period (days)'))
                    ->numeric()
                    ->minValue(1)
                    ->required()
                    ->helperText(__('Used only when automatic retirement is enabled.')),
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
                                ->label(__('Save'))
                                ->submit('save'),
                        ]),
                    ]),
            ]);
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);

        $tenant = Filament::getTenant();
        abort_if($tenant === null, 404);

        $state = $this->form->getState();
        TenantIntegrationSettings::update($tenant, $state);

        Notification::make()
            ->title(__('Integration settings saved'))
            ->success()
            ->send();
    }
}

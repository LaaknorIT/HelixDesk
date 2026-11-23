<?php

namespace App\Filament\Agent\Resources\Organizations\Pages;

use App\Filament\Agent\Resources\Organizations\Concerns\HandlesAction1IntegrationSave;
use App\Filament\Agent\Resources\Organizations\Concerns\HandlesMicrosoftEntraIntegrationSave;
use App\Filament\Agent\Resources\Organizations\OrganizationResource;
use App\Filament\Agent\Resources\Organizations\Schemas\OrganizationAction1SettingsSchema;
use App\Filament\Agent\Resources\Organizations\Schemas\OrganizationActiveDirectorySettingsSchema;
use App\Filament\Agent\Resources\Organizations\Schemas\OrganizationEntraSettingsSchema;
use App\Filament\Agent\Resources\Organizations\Schemas\OrganizationGoogleWorkspaceSettingsSchema;
use App\Models\OrganizationIntegration;
use App\Repositories\OrganizationIntegrationRepository;
use App\Services\ActiveDirectory\CollectorKeyService;
use App\Support\ActiveDirectoryCollectorScript;
use App\Support\IntegrationHealth\IntegrationConnectionChecker;
use App\Support\IntegrationHealth\IntegrationHealthInspector;
use App\Support\IntegrationHealth\IntegrationHealthPresenter;
use App\Support\IntegrationHealth\IntegrationHealthReport;
use App\Support\OrganizationIntegrationSettings;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

class EditOrganizationIntegration extends EditRecord
{
    use HandlesAction1IntegrationSave;
    use HandlesMicrosoftEntraIntegrationSave;

    protected static string $resource = OrganizationResource::class;

    public string $integrationId;

    public string $integrationType = OrganizationIntegrationSettings::TYPE_MICROSOFT_ENTRA;

    public function mount(int|string $record): void
    {
        $integration = request()->route('integration');
        abort_unless(is_string($integration) && $integration !== '', 404);
        $this->integrationId = $integration;

        $entry = OrganizationIntegrationSettings::findEntryForOrganization(
            $this->resolveRecord($record),
            $this->integrationId,
        );

        abort_if($entry === null, 404);

        // The type must be known before the form is built and filled, or the wrong setup form is shown.
        $this->integrationType = (string) ($entry['type'] ?? OrganizationIntegrationSettings::TYPE_MICROSOFT_ENTRA);

        parent::mount($record);
    }

    public function form(Schema $schema): Schema
    {
        return match ($this->integrationType) {
            OrganizationIntegrationSettings::TYPE_ACTION1 => OrganizationAction1SettingsSchema::configure($schema),
            OrganizationIntegrationSettings::TYPE_GOOGLE_WORKSPACE => OrganizationGoogleWorkspaceSettingsSchema::configure($schema),
            OrganizationIntegrationSettings::TYPE_ACTIVE_DIRECTORY => OrganizationActiveDirectorySettingsSchema::configure($schema),
            default => OrganizationEntraSettingsSchema::configure($schema),
        };
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Status'))
                    ->icon(Heroicon::OutlinedHeart)
                    ->collapsible()
                    ->schema(fn (): array => IntegrationHealthPresenter::callouts($this->healthReport())),
                $this->getFormContentComponent(),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('check_connection')
                ->label(__('Check connection'))
                ->icon(Heroicon::OutlinedSignal)
                ->color('gray')
                ->visible(fn (): bool => ($integration = $this->integrationModel()) !== null && IntegrationConnectionChecker::supports($integration))
                ->action(function (): void {
                    $integration = $this->integrationModel();
                    if ($integration === null) {
                        return;
                    }

                    $result = IntegrationConnectionChecker::check($integration);

                    Notification::make()
                        ->title($result['ok'] ? __('Connection works') : __('Connection failed'))
                        ->body($result['message'])
                        ->{$result['ok'] ? 'success' : 'danger'}()
                        ->send();
                }),
            Action::make('collector_key')
                ->label(fn (): string => app(CollectorKeyService::class)->hasKey($this->integrationModel())
                    ? __('Replace collector key')
                    : __('Create collector key'))
                ->icon(Heroicon::OutlinedKey)
                ->color('gray')
                ->visible(fn (): bool => $this->integrationType === OrganizationIntegrationSettings::TYPE_ACTIVE_DIRECTORY)
                ->requiresConfirmation(fn (): bool => app(CollectorKeyService::class)->hasKey($this->integrationModel()))
                ->modalDescription(__('The current key stops working right away. Update the collector with the new key.'))
                ->action(function (): void {
                    $integration = $this->integrationModel();
                    if ($integration === null) {
                        return;
                    }

                    $key = app(CollectorKeyService::class)->issue($integration);

                    Notification::make()
                        ->title(__('Collector key created'))
                        ->body(__('Copy it now, it is shown only once: :key', ['key' => $key]))
                        ->success()
                        ->persistent()
                        ->send();
                }),
            Action::make('download_collector')
                ->label(__('Download collector'))
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->visible(fn (): bool => $this->integrationType === OrganizationIntegrationSettings::TYPE_ACTIVE_DIRECTORY)
                ->action(fn () => response()->streamDownload(
                    fn () => print (ActiveDirectoryCollectorScript::render($this->integrationModel())),
                    ActiveDirectoryCollectorScript::FILE_NAME,
                    ['Content-Type' => 'text/plain; charset=UTF-8'],
                )),
        ];
    }

    private function integrationModel(): ?OrganizationIntegration
    {
        return app(OrganizationIntegrationRepository::class)->findModel($this->record, $this->integrationId);
    }

    private function healthReport(): IntegrationHealthReport
    {
        $integration = $this->integrationModel();
        abort_if($integration === null, 404);

        return IntegrationHealthInspector::inspect($integration);
    }

    public function getRelationManagers(): array
    {
        return [];
    }

    public function getTitle(): string|Htmlable
    {
        return __('Edit integration: :name', ['name' => $this->getRecord()->name]);
    }

    public function getBreadcrumb(): string
    {
        return __('Edit');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data = parent::mutateFormDataBeforeFill($data);

        $entry = OrganizationIntegrationSettings::findEntryForOrganization(
            $this->record,
            $this->integrationId,
        );

        abort_if($entry === null, 404);

        $configFields = match ($this->integrationType) {
            OrganizationIntegrationSettings::TYPE_ACTION1 => OrganizationIntegrationSettings::action1ConfigToFormFields($entry['config']),
            OrganizationIntegrationSettings::TYPE_GOOGLE_WORKSPACE => OrganizationIntegrationSettings::googleWorkspaceConfigToFormFields($entry['config']),
            OrganizationIntegrationSettings::TYPE_ACTIVE_DIRECTORY => OrganizationIntegrationSettings::activeDirectoryConfigToFormFields($entry['config']),
            default => OrganizationIntegrationSettings::entraConfigToFormFields($entry['config']),
        };

        return [
            ...$data,
            ...$configFields,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        if ($this->integrationType === OrganizationIntegrationSettings::TYPE_ACTION1) {
            $this->validateAction1FormData($data);

            app(OrganizationIntegrationRepository::class)->updateAction1FromForm(
                $this->record,
                $this->integrationId,
                $data,
            );

            $this->savedAction1IntegrationId = $this->integrationId;

            return [];
        }

        if ($this->integrationType === OrganizationIntegrationSettings::TYPE_ACTIVE_DIRECTORY) {
            app(OrganizationIntegrationRepository::class)->updateActiveDirectoryFromForm(
                $this->record,
                $this->integrationId,
                $data,
            );

            return [];
        }

        if ($this->integrationType === OrganizationIntegrationSettings::TYPE_GOOGLE_WORKSPACE) {
            app(OrganizationIntegrationRepository::class)->updateGoogleWorkspaceFromForm(
                $this->record,
                $this->integrationId,
                $data,
            );

            return [];
        }

        $this->validateMicrosoftEntraFormData($data);

        app(OrganizationIntegrationRepository::class)->updateMicrosoftEntraFromForm(
            $this->record,
            $this->integrationId,
            $data,
        );

        $this->savedMicrosoftEntraIntegrationId = $this->integrationId;

        return [];
    }

    protected function afterSave(): void
    {
        if ($this->integrationType === OrganizationIntegrationSettings::TYPE_ACTION1) {
            $this->queueAction1SyncIfReady();

            return;
        }

        if (in_array($this->integrationType, [OrganizationIntegrationSettings::TYPE_GOOGLE_WORKSPACE, OrganizationIntegrationSettings::TYPE_ACTIVE_DIRECTORY], true)) {
            return;
        }

        $this->queueMicrosoftEntraSyncIfReady();
    }

    protected function getRedirectUrl(): ?string
    {
        return static::getResource()::getUrl('settings', ['record' => $this->getRecord()]);
    }
}

<?php

namespace App\Filament\Agent\Resources\Organizations\Pages;

use App\Filament\Agent\Resources\Organizations\Concerns\HandlesAction1IntegrationSave;
use App\Filament\Agent\Resources\Organizations\Concerns\HandlesMicrosoftEntraIntegrationSave;
use App\Filament\Agent\Resources\Organizations\OrganizationResource;
use App\Filament\Agent\Resources\Organizations\Schemas\OrganizationAction1SettingsSchema;
use App\Filament\Agent\Resources\Organizations\Schemas\OrganizationActiveDirectorySettingsSchema;
use App\Filament\Agent\Resources\Organizations\Schemas\OrganizationEntraSettingsSchema;
use App\Filament\Agent\Resources\Organizations\Schemas\OrganizationGoogleWorkspaceSettingsSchema;
use App\Repositories\OrganizationIntegrationRepository;
use App\Support\OrganizationIntegrationSettings;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;

class CreateOrganizationIntegration extends EditRecord
{
    use HandlesAction1IntegrationSave;
    use HandlesMicrosoftEntraIntegrationSave;

    protected static string $resource = OrganizationResource::class;

    public string $integrationType = OrganizationIntegrationSettings::TYPE_MICROSOFT_ENTRA;

    protected ?string $savedActiveDirectoryIntegrationId = null;

    public function mount(int|string $record): void
    {
        $type = request()->query('type');
        if (is_string($type) && in_array($type, OrganizationIntegrationSettings::registeredTypes(), true)) {
            $this->integrationType = $type;
        }

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

    public function getRelationManagers(): array
    {
        return [];
    }

    public function getTitle(): string|Htmlable
    {
        return __('New integration: :name', ['name' => $this->getRecord()->name]);
    }

    public function getBreadcrumb(): string
    {
        return __('New');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data = parent::mutateFormDataBeforeFill($data);

        $blankFields = match ($this->integrationType) {
            OrganizationIntegrationSettings::TYPE_ACTION1 => OrganizationIntegrationSettings::blankAction1FormFields(),
            OrganizationIntegrationSettings::TYPE_GOOGLE_WORKSPACE => OrganizationIntegrationSettings::blankGoogleWorkspaceFormFields(),
            OrganizationIntegrationSettings::TYPE_ACTIVE_DIRECTORY => OrganizationIntegrationSettings::blankActiveDirectoryFormFields(),
            default => OrganizationIntegrationSettings::blankEntraFormFields(),
        };

        return [
            ...$data,
            ...$blankFields,
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

            $integration = app(OrganizationIntegrationRepository::class)->createAction1FromForm(
                $this->record,
                $data,
            );

            $this->savedAction1IntegrationId = (string) $integration->getKey();

            return [];
        }

        if ($this->integrationType === OrganizationIntegrationSettings::TYPE_ACTIVE_DIRECTORY) {
            $integration = app(OrganizationIntegrationRepository::class)->createActiveDirectoryFromForm(
                $this->record,
                $data,
            );

            $this->savedActiveDirectoryIntegrationId = (string) $integration->getKey();

            return [];
        }

        if ($this->integrationType === OrganizationIntegrationSettings::TYPE_GOOGLE_WORKSPACE) {
            app(OrganizationIntegrationRepository::class)->createGoogleWorkspaceFromForm(
                $this->record,
                $data,
            );

            return [];
        }

        $this->validateMicrosoftEntraFormData($data);

        $integration = app(OrganizationIntegrationRepository::class)->createMicrosoftEntraFromForm(
            $this->record,
            $data,
        );

        $this->savedMicrosoftEntraIntegrationId = (string) $integration->getKey();

        return [];
    }

    protected function afterSave(): void
    {
        if ($this->integrationType === OrganizationIntegrationSettings::TYPE_ACTION1) {
            $this->queueAction1SyncIfReady();

            return;
        }

        if ($this->integrationType === OrganizationIntegrationSettings::TYPE_GOOGLE_WORKSPACE) {
            return;
        }

        if ($this->integrationType === OrganizationIntegrationSettings::TYPE_ACTIVE_DIRECTORY) {
            return;
        }

        $this->queueMicrosoftEntraSyncIfReady();
    }

    protected function getSavedNotification(): ?Notification
    {
        if ($this->integrationType === OrganizationIntegrationSettings::TYPE_ACTIVE_DIRECTORY) {
            return Notification::make()
                ->success()
                ->title(__('Active Directory integration created'))
                ->body(__('Next, create the collector key and download the collector script on this page.'));
        }

        return parent::getSavedNotification();
    }

    protected function getRedirectUrl(): ?string
    {
        if ($this->integrationType === OrganizationIntegrationSettings::TYPE_ACTIVE_DIRECTORY && $this->savedActiveDirectoryIntegrationId !== null) {
            return static::getResource()::getUrl('settings.integrations.edit', [
                'record' => $this->getRecord(),
                'integration' => $this->savedActiveDirectoryIntegrationId,
            ]);
        }

        return static::getResource()::getUrl('settings', ['record' => $this->getRecord()]);
    }
}

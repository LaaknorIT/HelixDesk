<?php

namespace App\Filament\Agent\Resources\Organizations\Pages;

use App\Filament\Agent\Resources\Organizations\Concerns\HandlesAction1IntegrationSave;
use App\Filament\Agent\Resources\Organizations\Concerns\HandlesMicrosoftEntraIntegrationSave;
use App\Filament\Agent\Resources\Organizations\OrganizationResource;
use App\Filament\Agent\Resources\Organizations\Schemas\OrganizationAction1SettingsSchema;
use App\Filament\Agent\Resources\Organizations\Schemas\OrganizationEntraSettingsSchema;
use App\Filament\Agent\Resources\Organizations\Schemas\OrganizationGoogleWorkspaceSettingsSchema;
use App\Repositories\OrganizationIntegrationRepository;
use App\Support\OrganizationIntegrationSettings;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Schema;
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

        parent::mount($record);

        $entry = OrganizationIntegrationSettings::findEntryForOrganization(
            $this->record,
            $this->integrationId,
        );

        abort_if($entry === null, 404);

        $this->integrationType = (string) ($entry['type'] ?? OrganizationIntegrationSettings::TYPE_MICROSOFT_ENTRA);
    }

    public function form(Schema $schema): Schema
    {
        return match ($this->integrationType) {
            OrganizationIntegrationSettings::TYPE_ACTION1 => OrganizationAction1SettingsSchema::configure($schema),
            OrganizationIntegrationSettings::TYPE_GOOGLE_WORKSPACE => OrganizationGoogleWorkspaceSettingsSchema::configure($schema),
            default => OrganizationEntraSettingsSchema::configure($schema),
        };
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

        $this->queueMicrosoftEntraSyncIfReady();
    }

    protected function getRedirectUrl(): ?string
    {
        return static::getResource()::getUrl('settings', ['record' => $this->getRecord()]);
    }
}

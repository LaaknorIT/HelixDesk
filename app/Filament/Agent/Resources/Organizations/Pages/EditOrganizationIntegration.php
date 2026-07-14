<?php

namespace App\Filament\Agent\Resources\Organizations\Pages;

use App\Filament\Agent\Resources\Organizations\Concerns\HandlesMicrosoftEntraIntegrationSave;
use App\Filament\Agent\Resources\Organizations\OrganizationResource;
use App\Filament\Agent\Resources\Organizations\Schemas\OrganizationEntraSettingsSchema;
use App\Support\OrganizationIntegrationSettings;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;

class EditOrganizationIntegration extends EditRecord
{
    use HandlesMicrosoftEntraIntegrationSave;

    protected static string $resource = OrganizationResource::class;

    public string $integrationId;

    public function mount(int|string $record): void
    {
        $integration = request()->route('integration');
        abort_unless(is_string($integration) && $integration !== '', 404);
        $this->integrationId = $integration;

        parent::mount($record);
    }

    public function form(Schema $schema): Schema
    {
        return OrganizationEntraSettingsSchema::configure($schema);
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

        $entry = OrganizationIntegrationSettings::findEntry(
            is_array($this->record->organizational_settings) ? $this->record->organizational_settings : [],
            $this->integrationId,
        );

        abort_if($entry === null, 404);

        return [
            ...$data,
            ...OrganizationIntegrationSettings::entraConfigToFormFields($entry['config']),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->validateMicrosoftEntraFormData($data);

        $merged = OrganizationIntegrationSettings::replaceMicrosoftEntraIntegration(
            $this->record->organizational_settings,
            $this->integrationId,
            $data,
        );

        $this->savedMicrosoftEntraIntegrationId = $this->integrationId;

        return ['organizational_settings' => $merged];
    }

    protected function afterSave(): void
    {
        $this->queueMicrosoftEntraSyncIfReady();
    }

    protected function getRedirectUrl(): ?string
    {
        return static::getResource()::getUrl('settings', ['record' => $this->getRecord()]);
    }
}

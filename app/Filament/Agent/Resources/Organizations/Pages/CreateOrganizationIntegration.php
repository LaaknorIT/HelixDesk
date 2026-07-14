<?php

namespace App\Filament\Agent\Resources\Organizations\Pages;

use App\Filament\Agent\Resources\Organizations\Concerns\HandlesMicrosoftEntraIntegrationSave;
use App\Filament\Agent\Resources\Organizations\OrganizationResource;
use App\Filament\Agent\Resources\Organizations\Schemas\OrganizationEntraSettingsSchema;
use App\Support\OrganizationIntegrationSettings;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;

class CreateOrganizationIntegration extends EditRecord
{
    use HandlesMicrosoftEntraIntegrationSave;

    protected static string $resource = OrganizationResource::class;

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

        return [
            ...$data,
            ...OrganizationIntegrationSettings::blankEntraFormFields(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->validateMicrosoftEntraFormData($data);

        $created = OrganizationIntegrationSettings::createMicrosoftEntraIntegration(
            $this->record->organizational_settings,
            $data,
        );

        $this->savedMicrosoftEntraIntegrationId = $created['integration_id'];

        return ['organizational_settings' => $created['organizational_settings']];
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

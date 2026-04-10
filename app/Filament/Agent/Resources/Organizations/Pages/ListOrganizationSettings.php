<?php

namespace App\Filament\Agent\Resources\Organizations\Pages;

use App\Filament\Agent\Resources\Organizations\OrganizationResource;
use App\Support\OrganizationIntegrationSettings;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\RenderHook;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Str;

class ListOrganizationSettings extends Page implements HasTable
{
    use InteractsWithRecord;
    use InteractsWithTable;

    protected static string $resource = OrganizationResource::class;

    protected static ?string $navigationLabel = 'Settings';

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        abort_unless(static::getResource()::canEdit($this->getRecord()), 403);
    }

    public function getTitle(): string|Htmlable
    {
        return __('Organization settings: :name', ['name' => $this->getRecord()->name]);
    }

    public function getBreadcrumb(): ?string
    {
        return __('Settings');
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_BEFORE),
                EmbeddedTable::make(),
                RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_AFTER),
            ]);
    }

    protected function makeTable(): Table
    {
        return Table::make($this)
            ->records(fn (): array => $this->integrationTableRecords())
            ->columns([
                TextColumn::make('type_label')
                    ->label(__('Integration'))
                    ->searchable(false),
                IconColumn::make('enabled')
                    ->label(__('Enabled'))
                    ->boolean(),
                TextColumn::make('summary')
                    ->label(__('Details'))
                    ->wrap(),
            ])
            ->headerActions([
                Action::make('new')
                    ->label(__('New'))
                    ->icon(Heroicon::OutlinedPlus)
                    ->url(static::getResource()::getUrl('settings.integrations.create', [
                        'record' => $this->getRecord(),
                    ])),
            ])
            ->recordActions([
                Action::make('edit')
                    ->label(__('Edit'))
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->url(fn (array $record): string => static::getResource()::getUrl('settings.integrations.edit', [
                        'record' => $this->getRecord(),
                        'integration' => $record['id'],
                    ])),
            ])
            ->emptyStateHeading(__('No integrations configured'))
            ->emptyStateDescription(__('Create an integration to connect external services to this organization.'))
            ->emptyStateIcon(Heroicon::OutlinedCog6Tooth)
            ->paginated(false);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function integrationTableRecords(): array
    {
        $rows = [];
        foreach (OrganizationIntegrationSettings::listEntries($this->getRecord()->organizational_settings) as $entry) {
            $id = $entry['id'];
            $config = $entry['config'];
            $rows[] = [
                '__key' => $id,
                'id' => $id,
                'type' => $entry['type'],
                'type_label' => OrganizationIntegrationSettings::typeLabel($entry['type']),
                'enabled' => (bool) ($config['enabled'] ?? false),
                'summary' => $this->integrationSummary($entry['type'], $config),
            ];
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function integrationSummary(string $type, array $config): string
    {
        if ($type === OrganizationIntegrationSettings::TYPE_MICROSOFT_ENTRA) {
            $tenant = trim((string) ($config['azure_tenant_id'] ?? ''));
            $client = trim((string) ($config['application_client_id'] ?? ''));

            $parts = [];
            if ($tenant !== '') {
                $parts[] = __('Tenant: :id', ['id' => Str::limit($tenant, 36)]);
            }
            if ($client !== '') {
                $parts[] = __('App: :id', ['id' => Str::limit($client, 36)]);
            }

            return $parts === [] ? __('Identifiers not set') : implode(' · ', $parts);
        }

        return '';
    }

    /**
     * @return array<string>
     */
    public function getPageClasses(): array
    {
        return [
            'fi-resource-list-organization-settings-page',
            'fi-resource-'.str_replace('/', '-', $this->getResource()::getSlug(Filament::getCurrentOrDefaultPanel())),
        ];
    }
}

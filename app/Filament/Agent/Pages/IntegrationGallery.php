<?php

namespace App\Filament\Agent\Pages;

use App\Filament\Agent\Resources\Organizations\OrganizationResource;
use App\Models\OrganizationIntegration;
use App\Support\IntegrationCatalog;
use App\Support\OrganizationIntegrationOptions;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\UnorderedList;
use Filament\Schemas\Schema;
use Filament\Support\Enums\TextSize;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;

/**
 * A searchable catalog of everything HelixDesk can connect to. Pick a customer, pick an integration,
 * and the guided setup takes it from there.
 */
class IntegrationGallery extends Page
{
    protected static ?string $slug = 'integrations/catalog';

    protected static bool $shouldRegisterNavigation = false;

    #[Url(as: 'organization')]
    public ?int $organizationId = null;

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return Integrations::canAccess();
    }

    public function getTitle(): string
    {
        return __('Add an integration');
    }

    public function getSubheading(): ?string
    {
        return __('Choose the customer organization, then pick the service you want to connect. Each integration has a guided setup.');
    }

    public function getBreadcrumbs(): array
    {
        return [
            Integrations::getUrl() => __('Integrations'),
            __('Catalog'),
        ];
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $organizationOptions = OrganizationIntegrationOptions::organizationsForCurrentTenant();
        $organizationId = array_key_exists((int) $this->organizationId, $organizationOptions) ? (int) $this->organizationId : null;

        $this->form->fill([
            'organization_id' => $organizationId,
            'search' => '',
            'category' => '',
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->schema([
                        Grid::make(['default' => 1, 'md' => 2])
                            ->schema([
                                Select::make('organization_id')
                                    ->label(__('Customer organization'))
                                    ->options(fn (): array => OrganizationIntegrationOptions::organizationsForCurrentTenant())
                                    ->searchable()
                                    ->live()
                                    ->afterStateUpdated(fn (?int $state) => $this->organizationId = $state)
                                    ->placeholder(__('Select the organization to connect')),
                                TextInput::make('search')
                                    ->label(__('Search'))
                                    ->placeholder(__('Search by name, vendor or capability'))
                                    ->prefixIcon(Heroicon::OutlinedMagnifyingGlass)
                                    ->live(debounce: 300),
                            ]),
                        ToggleButtons::make('category')
                            ->hiddenLabel()
                            ->options(['' => __('All')] + IntegrationCatalog::categories())
                            ->inline()
                            ->grouped()
                            ->live(),
                    ]),
            ])
            ->statePath('data');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            EmbeddedSchema::make('form'),
            Grid::make(['default' => 1, 'md' => 2, 'xl' => 3])
                ->schema(fn (): array => $this->catalogCards()),
        ]);
    }

    /**
     * @return list<Section|Text>
     */
    private function catalogCards(): array
    {
        $definitions = IntegrationCatalog::search(
            (string) ($this->data['search'] ?? ''),
            (string) ($this->data['category'] ?? ''),
        );

        if ($definitions === []) {
            return [
                Text::make(__('No integrations match your search.'))->color('gray'),
            ];
        }

        $connectedCounts = $this->connectedCounts();
        $organizationId = $this->selectedOrganizationId();
        $connectedHere = $organizationId !== null ? $this->connectedTypesFor($organizationId) : [];
        $categories = IntegrationCatalog::categories();

        $cards = [];
        foreach ($definitions as $definition) {
            $type = $definition['type'];
            $alreadyConnected = in_array($type, $connectedHere, true);
            $connectedCount = $connectedCounts[$type] ?? 0;

            $cards[] = Section::make($definition['name'])
                ->description($definition['summary'])
                ->icon($definition['icon'])
                ->afterHeader([
                    Text::make(__('~:minutes min', ['minutes' => $definition['setup_minutes']]))
                        ->badge()
                        ->color('gray'),
                ])
                ->schema([
                    Text::make(implode(' · ', array_map(
                        fn (string $category): string => $categories[$category] ?? $category,
                        $definition['categories'],
                    )))->size(TextSize::Small)->color('gray'),
                    Text::make(__('What it can do'))->weight('semibold'),
                    UnorderedList::make(array_map(
                        fn (string $capability): Text => Text::make($capability),
                        array_values($definition['capabilities']),
                    ))->size(TextSize::Small),
                    Text::make(__('You will need'))->weight('semibold'),
                    UnorderedList::make(array_map(
                        fn (string $requirement): Text => Text::make($requirement),
                        $definition['requirements'],
                    ))->size(TextSize::Small),
                    Text::make($connectedCount > 0
                        ? trans_choice('{1} Connected for 1 organization|[2,*] Connected for :count organizations', $connectedCount)
                        : __('Not connected yet'))
                        ->badge()
                        ->color($connectedCount > 0 ? 'success' : 'gray'),
                ])
                ->footer([
                    Action::make('connect_'.$type)
                        ->label($alreadyConnected ? __('Connect another') : __('Connect'))
                        ->icon(Heroicon::OutlinedArrowRight)
                        ->iconPosition('after')
                        ->disabled($organizationId === null)
                        ->tooltip($organizationId === null ? __('Select a customer organization first') : null)
                        ->url($organizationId !== null ? OrganizationResource::getUrl('settings.integrations.create', [
                            'record' => $organizationId,
                            'type' => $type,
                        ]) : null),
                    Action::make('docs_'.$type)
                        ->label(__('Vendor docs'))
                        ->link()
                        ->color('gray')
                        ->visible($definition['docs_url'] !== null)
                        ->url($definition['docs_url'], shouldOpenInNewTab: true),
                ]);
        }

        return $cards;
    }

    private function selectedOrganizationId(): ?int
    {
        $id = $this->data['organization_id'] ?? null;

        return filled($id) ? (int) $id : null;
    }

    /**
     * @return array<string, int> type => number of organizations
     */
    private function connectedCounts(): array
    {
        $tenant = Filament::getTenant();
        if ($tenant === null) {
            return [];
        }

        return OrganizationIntegration::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->getKey())
            ->selectRaw('type, count(distinct organization_id) as organizations')
            ->groupBy('type')
            ->pluck('organizations', 'type')
            ->map(fn (mixed $count): int => (int) $count)
            ->all();
    }

    /**
     * @return list<string>
     */
    private function connectedTypesFor(int $organizationId): array
    {
        $tenant = Filament::getTenant();
        if ($tenant === null) {
            return [];
        }

        return OrganizationIntegration::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->getKey())
            ->where('organization_id', $organizationId)
            ->pluck('type')
            ->unique()
            ->values()
            ->all();
    }
}

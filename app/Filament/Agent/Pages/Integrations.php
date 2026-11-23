<?php

namespace App\Filament\Agent\Pages;

use App\Enums\IntegrationHealthStatus;
use App\Filament\Agent\Resources\Organizations\OrganizationResource;
use App\Models\Organization;
use App\Models\OrganizationIntegration;
use App\Services\OrganizationIntegrationMover;
use App\Support\IntegrationCatalog;
use App\Support\IntegrationHealth\IntegrationConnectionChecker;
use App\Support\IntegrationHealth\IntegrationHealthInspector;
use App\Support\IntegrationHealth\IntegrationHealthPresenter;
use App\Support\IntegrationHealth\IntegrationHealthReport;
use App\Support\IntegrationType;
use App\Support\OrganizationIntegrationOptions;
use App\Support\OrganizationIntegrationSettings;
use App\Support\OrganizationIntegrationSyncQueue;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * One place to see every integration across all customer organizations, whether it is healthy,
 * and exactly what is wrong when it is not.
 */
class Integrations extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPuzzlePiece;

    protected static string|UnitEnum|null $navigationGroup = 'Administration';

    protected static ?int $navigationSort = 52;

    protected static ?string $slug = 'integrations';

    /**
     * @var array<string, mixed>|null
     */
    #[Url(as: 'filters')]
    public ?array $tableFilters = null;

    /**
     * @var array<string, IntegrationHealthReport>|null
     */
    private ?array $reports = null;

    public static function getNavigationLabel(): string
    {
        return __('Integrations');
    }

    public function getTitle(): string
    {
        return __('Integrations');
    }

    public function getSubheading(): ?string
    {
        return __('Connections to external services for all customer organizations, with their sync status.');
    }

    public static function canAccess(): bool
    {
        $user = Auth::user();

        if ($user === null || ! $user->isTenantStaff()) {
            return false;
        }

        return $user->hasPermissionTo('organization.update')
            || $user->hasPermissionTo('tenant.admin');
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            EmbeddedTable::make(),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('add')
                ->label(__('Add integration'))
                ->icon(Heroicon::OutlinedPlus)
                ->url(IntegrationGallery::getUrl()),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (array $filters, ?string $search): Collection => $this->tableRecords($filters, $search))
            ->searchable()
            ->columns([
                TextColumn::make('organization')
                    ->label(__('Organization'))
                    ->weight('medium'),
                TextColumn::make('type_label')
                    ->label(__('Integration'))
                    ->icon(fn (array $record): ?Heroicon => IntegrationCatalog::find($record['type'])['icon'] ?? null),
                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => IntegrationHealthStatus::from($state)->label())
                    ->color(fn (string $state): string => IntegrationHealthStatus::from($state)->color())
                    ->icon(fn (string $state): Heroicon => IntegrationHealthStatus::from($state)->icon()),
                TextColumn::make('issue')
                    ->label(__('What needs attention'))
                    ->placeholder('—')
                    ->description(fn (array $record): ?string => $record['issue_detail'])
                    ->wrap(),
                TextColumn::make('last_sync')
                    ->label(__('Last successful sync'))
                    ->placeholder(__('Never')),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('Status'))
                    ->options(IntegrationHealthStatus::options()),
                SelectFilter::make('type')
                    ->label(__('Integration'))
                    ->options(OrganizationIntegrationSettings::creatableTypeLabels()),
            ])
            ->recordActions([
                Action::make('details')
                    ->label(__('Details'))
                    ->icon(Heroicon::OutlinedEye)
                    ->modalHeading(fn (array $record): string => $record['type_label'].' · '.$record['organization'])
                    ->schema(fn (array $record): array => IntegrationHealthPresenter::callouts($this->reportFor($record['id'])))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel(__('Close'))
                    ->extraModalFooterActions(fn (array $record): array => [
                        Action::make('edit_from_details')
                            ->label(__('Open settings'))
                            ->url($this->editUrl($record)),
                    ]),
                ActionGroup::make([
                    Action::make('check')
                        ->label(__('Check connection'))
                        ->icon(Heroicon::OutlinedSignal)
                        ->visible(fn (array $record): bool => in_array($record['type'], [IntegrationType::MICROSOFT_ENTRA, IntegrationType::ACTION1], true))
                        ->action(function (array $record): void {
                            $integration = $this->findIntegration($record['id']);
                            if ($integration === null || ! IntegrationConnectionChecker::supports($integration)) {
                                return;
                            }

                            $result = IntegrationConnectionChecker::check($integration);
                            $this->reports = null;

                            Notification::make()
                                ->title($result['ok'] ? __('Connection works') : __('Connection failed'))
                                ->body($result['message'])
                                ->{$result['ok'] ? 'success' : 'danger'}()
                                ->send();
                        }),
                    Action::make('run')
                        ->label(__('Run sync'))
                        ->icon(Heroicon::OutlinedPlay)
                        ->visible(fn (array $record): bool => in_array($record['type'], OrganizationIntegrationSettings::assetSyncTypes(), true))
                        ->action(function (array $record): void {
                            $integration = $this->findIntegration($record['id']);
                            $organization = $integration?->organization;
                            if ($integration === null || ! $organization instanceof Organization) {
                                return;
                            }

                            $config = $integration->configWithCredentials();
                            $config['enabled'] = $integration->enabled;
                            $result = OrganizationIntegrationSyncQueue::dispatch($organization, (string) $integration->getKey(), (string) $integration->type, $config);

                            if ($result['queued']) {
                                Notification::make()
                                    ->title(__('Sync queued'))
                                    ->body(__('The integration sync has been queued and will run shortly.'))
                                    ->success()
                                    ->send();

                                return;
                            }

                            Notification::make()
                                ->title(__('Sync not started'))
                                ->body(OrganizationIntegrationSyncQueue::skipReasonMessage((string) $result['skip_reason']))
                                ->warning()
                                ->send();
                        }),
                    Action::make('move')
                        ->label(__('Move to another organization'))
                        ->icon(Heroicon::OutlinedArrowsRightLeft)
                        ->modalHeading(fn (array $record): string => __('Move :integration', ['integration' => $record['type_label'].' · '.$record['organization']]))
                        ->modalDescription(__('Devices above the new organization move down to it. Devices that only this integration placed in the current organization follow it. Devices never move up, and devices another integration placed stay where they are.'))
                        ->schema(fn (array $record): array => [
                            Select::make('organization_id')
                                ->label(__('New organization'))
                                ->options(fn (): array => collect(OrganizationIntegrationOptions::organizationPathsForCurrentTenant())
                                    ->except([$record['organization_id']])
                                    ->all())
                                ->searchable()
                                ->required(),
                        ])
                        ->modalSubmitActionLabel(__('Move integration'))
                        ->action(function (array $record, array $data): void {
                            $integration = $this->findIntegration($record['id']);
                            $tenant = Filament::getTenant();
                            $target = Organization::query()
                                ->where('tenant_id', $tenant?->getKey())
                                ->find((int) ($data['organization_id'] ?? 0));

                            if ($integration === null || $target === null) {
                                return;
                            }

                            try {
                                $result = app(OrganizationIntegrationMover::class)->move($integration, $target);
                            } catch (ValidationException $exception) {
                                Notification::make()
                                    ->title(__('Integration not moved'))
                                    ->body(collect($exception->errors())->flatten()->first())
                                    ->danger()
                                    ->send();

                                return;
                            }

                            $this->reports = null;

                            Notification::make()
                                ->title(__('Integration moved to :organization', ['organization' => $target->name]))
                                ->body(trans_choice('{0} No devices needed to move.|{1} 1 device moved with it.|[2,*] :count devices moved with it.', $result['moved_devices']))
                                ->success()
                                ->send();
                        }),
                    Action::make('edit')
                        ->label(__('Settings'))
                        ->icon(Heroicon::OutlinedCog6Tooth)
                        ->url(fn (array $record): string => $this->editUrl($record)),
                ]),
            ])
            ->emptyStateHeading(__('No integrations yet'))
            ->emptyStateDescription(__('Connect identity providers, endpoint management and mail services to bring customer data into HelixDesk.'))
            ->emptyStateIcon(Heroicon::OutlinedPuzzlePiece)
            ->emptyStateActions([
                Action::make('add_empty')
                    ->label(__('Browse integrations'))
                    ->url(IntegrationGallery::getUrl()),
            ])
            ->paginated(false);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    private function tableRecords(array $filters, ?string $search): Collection
    {
        $statusFilter = $filters['status']['value'] ?? null;
        $typeFilter = $filters['type']['value'] ?? null;
        $needle = Str::lower(trim((string) $search));
        $severityOrder = [
            IntegrationHealthStatus::Error->value => 0,
            IntegrationHealthStatus::Warning->value => 1,
            IntegrationHealthStatus::Healthy->value => 2,
            IntegrationHealthStatus::Disabled->value => 3,
        ];

        return collect($this->reports())
            ->map(function (IntegrationHealthReport $report): array {
                $integration = $report->integration;
                $issue = $report->firstIssue();

                return [
                    '__key' => (string) $integration->getKey(),
                    'id' => (string) $integration->getKey(),
                    'organization_id' => (int) $integration->organization_id,
                    'organization' => (string) ($integration->organization?->name ?? '—'),
                    'type' => (string) $integration->type,
                    'type_label' => IntegrationType::label((string) $integration->type),
                    'status' => $report->status->value,
                    'issue' => IntegrationHealthPresenter::shortDescription($report),
                    'issue_detail' => $issue !== null ? Str::limit($issue->detail, 120) : null,
                    'last_sync' => $integration->last_sync_at?->diffForHumans(),
                ];
            })
            ->filter(fn (array $row): bool => blank($statusFilter) || $row['status'] === $statusFilter)
            ->filter(fn (array $row): bool => blank($typeFilter) || $row['type'] === $typeFilter)
            ->filter(fn (array $row): bool => $needle === '' || Str::contains(
                Str::lower($row['organization'].' '.$row['type_label'].' '.$row['issue']),
                $needle,
            ))
            ->sortBy([
                fn (array $a, array $b): int => $severityOrder[$a['status']] <=> $severityOrder[$b['status']],
                fn (array $a, array $b): int => strcmp($a['organization'], $b['organization']),
            ])
            ->values();
    }

    /**
     * @return array<string, IntegrationHealthReport>
     */
    private function reports(): array
    {
        if ($this->reports !== null) {
            return $this->reports;
        }

        $tenant = Filament::getTenant();
        if ($tenant === null) {
            return $this->reports = [];
        }

        $integrations = OrganizationIntegration::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->getKey())
            ->with('organization')
            ->get();

        $this->reports = [];
        foreach (IntegrationHealthInspector::inspectMany($integrations) as $report) {
            $this->reports[(string) $report->integration->getKey()] = $report;
        }

        return $this->reports;
    }

    private function reportFor(string $integrationId): IntegrationHealthReport
    {
        $report = $this->reports()[$integrationId] ?? null;
        abort_if($report === null, 404);

        return $report;
    }

    private function findIntegration(string $integrationId): ?OrganizationIntegration
    {
        return $this->reports()[$integrationId]->integration ?? null;
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function editUrl(array $record): string
    {
        return OrganizationResource::getUrl('settings.integrations.edit', [
            'record' => $record['organization_id'],
            'integration' => $record['id'],
        ]);
    }
}

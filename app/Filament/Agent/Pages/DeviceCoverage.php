<?php

namespace App\Filament\Agent\Pages;

use App\Enums\DeviceClass;
use App\Filament\Agent\Resources\Assets\AssetResource;
use App\Services\DeviceCoverageService;
use App\Support\DeviceCoverage\CoverageRules;
use App\Support\DeviceCoverage\DeviceCoverageExport;
use App\Support\DeviceCoverage\DeviceCoverageReport;
use App\Support\OrganizationIntegrationOptions;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Enums\TextSize;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Url;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

/**
 * Which devices each source (directory, device management, RMM, endpoint security) sees, and where coverage
 * is missing. Columns and rules come from the device source registry, so new integrations show up by themselves.
 */
class DeviceCoverage extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Infrastructure';

    protected static ?int $navigationSort = 37;

    protected static ?string $slug = 'device-coverage';

    /**
     * @var array<string, mixed>|null
     */
    #[Url(as: 'filters')]
    public ?array $tableFilters = null;

    private ?DeviceCoverageReport $report = null;

    public static function getNavigationLabel(): string
    {
        return __('Device coverage');
    }

    public function getTitle(): string
    {
        return __('Device coverage');
    }

    public function getSubheading(): ?string
    {
        return __('Which devices each integration sees, and devices that are missing where they should be.');
    }

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user !== null && $user->isTenantStaff() && $user->hasPermissionTo('asset.read');
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(['default' => 1, 'md' => 2, 'xl' => 4])
                ->schema(fn (): array => $this->summaryCards()),
            EmbeddedTable::make(),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('export')
                ->label(__('Export CSV'))
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->action(fn (): StreamedResponse => $this->exportCsv()),
        ];
    }

    public function table(Table $table): Table
    {
        $report = $this->report();

        $columns = [
            TextColumn::make('name')
                ->label(__('Device'))
                ->weight('medium')
                ->url(fn (array $record): ?string => $record['asset_id'] !== null ? AssetResource::getUrl('view', ['record' => $record['asset_id']]) : null)
                ->description(fn (array $record): ?string => $record['probable_match'] ? __('Matched by hostname') : null),
            TextColumn::make('organization')
                ->label(__('Organization'))
                ->toggleable(),
            TextColumn::make('device_class')
                ->label(__('Class'))
                ->formatStateUsing(fn (string $state): string => DeviceClass::from($state)->label())
                ->description(fn (array $record): ?string => $record['operating_system']),
        ];

        foreach ($report->sources as $key => $label) {
            $columns[] = TextColumn::make('source_'.$key)
                ->label($label)
                ->state(fn (array $record): string => $record['cells'][$key] ?? DeviceCoverageReport::CELL_NOT_ENABLED)
                ->badge()
                ->formatStateUsing(fn (string $state): string => self::cellLabel($state))
                ->color(fn (string $state): string => self::cellColor($state))
                ->tooltip(fn (array $record): ?string => isset($record['last_activity'][$key])
                    ? __('Last active :ago', ['ago' => Carbon::parse($record['last_activity'][$key])->diffForHumans()])
                    : null);
        }

        $columns[] = TextColumn::make('finding_labels')
            ->label(__('Findings'))
            ->state(fn (array $record): array => array_map(
                fn (string $ruleKey): string => CoverageRules::find($ruleKey)->name ?? $ruleKey,
                $record['findings'],
            ))
            ->listWithLineBreaks()
            ->placeholder(__('None'))
            ->wrap();

        return $table
            ->records(fn (array $filters, ?string $search): Collection => $this->tableRecords($filters, $search))
            ->searchable()
            ->columns($columns)
            ->filters([
                SelectFilter::make('organization')
                    ->label(__('Organization'))
                    ->options(fn (): array => OrganizationIntegrationOptions::organizationsForCurrentTenant()),
                SelectFilter::make('rule')
                    ->label(__('Finding'))
                    ->options(fn (): array => collect($this->report()->ruleSummaries)
                        ->mapWithKeys(fn (array $summary): array => [$summary['rule']->key => $summary['rule']->name])
                        ->all()),
                SelectFilter::make('device_class')
                    ->label(__('Class'))
                    ->options(collect(DeviceClass::cases())->mapWithKeys(fn (DeviceClass $class): array => [$class->value => $class->label()])->all()),
                Filter::make('only_findings')
                    ->label(__('Only devices with findings'))
                    ->toggle(),
            ])
            ->emptyStateHeading(__('No devices to compare yet'))
            ->emptyStateDescription(__('Devices appear here after integrations with device inventory (for example Intune or an RMM) have synced.'))
            ->emptyStateIcon(Heroicon::OutlinedComputerDesktop)
            ->defaultPaginationPageOption(25);
    }

    public static function cellLabel(string $state): string
    {
        return match ($state) {
            DeviceCoverageReport::CELL_ACTIVE => __('Active'),
            DeviceCoverageReport::CELL_STALE => __('Stale'),
            DeviceCoverageReport::CELL_DISABLED => __('Disabled'),
            DeviceCoverageReport::CELL_MISSING => __('Missing'),
            DeviceCoverageReport::CELL_ABSENT => '–',
            default => __('Not connected'),
        };
    }

    public static function cellColor(string $state): string
    {
        return match ($state) {
            DeviceCoverageReport::CELL_ACTIVE => 'success',
            DeviceCoverageReport::CELL_STALE => 'warning',
            DeviceCoverageReport::CELL_MISSING => 'danger',
            default => 'gray',
        };
    }

    /**
     * @return list<Section|Text>
     */
    private function summaryCards(): array
    {
        $summaries = $this->report()->ruleSummaries;

        if ($summaries === []) {
            return [];
        }

        $cards = [];
        foreach ($summaries as $summary) {
            $inScope = $summary['in_scope'];
            $covered = $inScope - $summary['missing'];
            $percent = $inScope > 0 ? (int) floor($covered / $inScope * 100) : 100;

            $cards[] = Section::make($summary['rule']->name)
                ->compact()
                ->schema([
                    Text::make($inScope > 0 ? $percent.' %' : '—')
                        ->size(TextSize::Large)
                        ->weight('bold')
                        ->color($summary['missing'] > 0 ? 'danger' : 'success'),
                    Text::make($summary['missing'] > 0
                        ? trans_choice('{1} 1 of :total devices is missing|[2,*] :count of :total devices are missing', $summary['missing'], ['total' => $inScope])
                        : __('All :total devices covered', ['total' => $inScope]))
                        ->size(TextSize::Small)
                        ->color('gray'),
                ]);
        }

        return $cards;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    private function tableRecords(array $filters, ?string $search): Collection
    {
        $organization = $filters['organization']['value'] ?? null;
        $rule = $filters['rule']['value'] ?? null;
        $deviceClass = $filters['device_class']['value'] ?? null;
        $onlyFindings = (bool) ($filters['only_findings']['isActive'] ?? false);
        $needle = Str::lower(trim((string) $search));

        return collect($this->report()->rows)
            ->filter(fn (array $row): bool => blank($organization) || (int) $row['organization_id'] === (int) $organization)
            ->filter(fn (array $row): bool => blank($rule) || in_array($rule, $row['findings'], true))
            ->filter(fn (array $row): bool => blank($deviceClass) || $row['device_class'] === $deviceClass)
            ->filter(fn (array $row): bool => ! $onlyFindings || $row['findings'] !== [])
            ->filter(fn (array $row): bool => $needle === '' || Str::contains(
                Str::lower($row['name'].' '.$row['organization'].' '.$row['operating_system']),
                $needle,
            ))
            ->map(fn (array $row): array => ['__key' => $row['key'], ...$row])
            ->values();
    }

    private function report(): DeviceCoverageReport
    {
        if ($this->report !== null) {
            return $this->report;
        }

        $tenant = Filament::getTenant();

        return $this->report = $tenant === null
            ? new DeviceCoverageReport([], [], [])
            : app(DeviceCoverageService::class)->build((int) $tenant->getKey());
    }

    private function exportCsv(): StreamedResponse
    {
        $report = $this->report();
        $rows = $this->tableRecords($this->tableFilters ?? [], $this->tableSearch ?? null)->all();

        return response()->streamDownload(
            function () use ($report, $rows): void {
                echo DeviceCoverageExport::toCsv($report, $rows);
            },
            'device-coverage-'.now()->format('Y-m-d').'.csv',
            ['Content-Type' => 'text/csv; charset=UTF-8'],
        );
    }
}

<?php

namespace App\Filament\Agent\Resources\Assets\Pages;

use App\Filament\Agent\Resources\Assets\AssetResource;
use App\Filament\Agent\Resources\Assets\Schemas\AssetInfolist;
use App\Models\Asset;
use App\Services\AssetMergeService;
use App\Support\AssetIdentityNormalizer;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class ViewAsset extends ViewRecord
{
    protected static string $resource = AssetResource::class;

    public function infolist(Schema $schema): Schema
    {
        return AssetInfolist::configure($schema);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('mergeDuplicate')
                ->label(__('Merge duplicate'))
                ->icon(Heroicon::OutlinedArrowsPointingIn)
                ->color('gray')
                ->visible(fn (): bool => AssetResource::canEdit($this->record) && AssetResource::canDelete($this->record))
                ->modalDescription(__('Everything linked to the duplicate (integrations, work items, software, documents) moves to this asset, and the duplicate is deleted.'))
                ->schema([
                    Select::make('duplicate_asset_id')
                        ->label(__('Duplicate asset'))
                        ->helperText(__('Assets with the same name are listed first. Type to search for others.'))
                        ->options(fn (): array => $this->sameNameAssetOptions())
                        ->getSearchResultsUsing(fn (string $search): array => Asset::query()
                            ->whereKeyNot($this->record->getKey())
                            ->where(fn ($query) => $query
                                ->where('name', 'like', '%'.$search.'%')
                                ->orWhere('primary_hostname', 'like', '%'.$search.'%')
                                ->orWhere('serial_number', 'like', '%'.$search.'%'))
                            ->orderBy('name')
                            ->limit(50)
                            ->get()
                            ->mapWithKeys(fn (Asset $asset): array => [$asset->getKey() => $this->assetOptionLabel($asset)])
                            ->all())
                        ->getOptionLabelUsing(fn ($value): ?string => ($asset = Asset::query()->find($value)) !== null ? $this->assetOptionLabel($asset) : null)
                        ->searchable()
                        ->required(),
                ])
                ->action(function (array $data): void {
                    /** @var Asset $keep */
                    $keep = $this->record;
                    $duplicate = Asset::query()->findOrFail((int) $data['duplicate_asset_id']);
                    $mergeService = app(AssetMergeService::class);

                    $blocker = $mergeService->mergeBlocker($keep, $duplicate);
                    if ($blocker !== null) {
                        Notification::make()
                            ->title(__('The assets were not merged'))
                            ->body($blocker)
                            ->danger()
                            ->send();

                        return;
                    }

                    $this->record = $mergeService->merge($keep, $duplicate);

                    Notification::make()
                        ->title(__('Assets merged'))
                        ->body(__(':name was merged into this asset.', ['name' => $duplicate->name]))
                        ->success()
                        ->send();

                    $this->redirect(AssetResource::getUrl('view', ['record' => $this->record]));
                }),
            EditAction::make()
                ->visible(fn (): bool => AssetResource::canEdit($this->record)),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function sameNameAssetOptions(): array
    {
        $hostname = AssetIdentityNormalizer::normalizeHostname($this->record->primary_hostname)
            ?? AssetIdentityNormalizer::normalizeHostname($this->record->name);

        if ($hostname === null) {
            return [];
        }

        return Asset::query()
            ->whereKeyNot($this->record->getKey())
            ->where(fn ($query) => $query
                ->whereRaw('LOWER(name) = ?', [$hostname])
                ->orWhereRaw('LOWER(primary_hostname) = ?', [$hostname])
                ->orWhereRaw('LOWER(primary_hostname) LIKE ?', [$hostname.'.%']))
            ->orderBy('id')
            ->limit(50)
            ->get()
            ->mapWithKeys(fn (Asset $asset): array => [$asset->getKey() => $this->assetOptionLabel($asset)])
            ->all();
    }

    private function assetOptionLabel(Asset $asset): string
    {
        return trim(sprintf(
            '%s (#%d%s%s)',
            $asset->name,
            $asset->getKey(),
            $asset->operating_system !== null ? ', '.$asset->operating_system : '',
            $asset->organization?->name !== null ? ', '.$asset->organization->name : '',
        ));
    }
}

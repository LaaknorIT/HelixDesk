<?php

namespace App\Filament\Agent\Resources\AssetIdentityAmbiguities\Pages;

use App\Enums\AssetIdentityAmbiguityStatus;
use App\Filament\Agent\Resources\AssetIdentityAmbiguities\AssetIdentityAmbiguityResource;
use App\Filament\Agent\Resources\AssetIdentityAmbiguities\Schemas\AssetIdentityAmbiguityInfolist;
use App\Filament\Agent\Resources\Assets\AssetResource;
use App\Models\Asset;
use App\Models\AssetIdentityAmbiguity;
use App\Services\AssetIdentityAmbiguityResolutionService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;

class ViewAssetIdentityAmbiguity extends ViewRecord
{
    protected static string $resource = AssetIdentityAmbiguityResource::class;

    public function infolist(Schema $schema): Schema
    {
        return AssetIdentityAmbiguityInfolist::configure($schema);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('linkToAsset')
                ->label('Link to asset')
                ->icon('heroicon-o-link')
                ->visible(fn (): bool => $this->canResolve() && $this->record->status === AssetIdentityAmbiguityStatus::Open)
                ->schema([
                    Select::make('asset_id')
                        ->label('Candidate asset')
                        ->options(fn (): array => Asset::query()
                            ->whereIn('id', $this->record->candidate_asset_ids ?? [])
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all())
                        ->required()
                        ->searchable(),
                ])
                ->action(function (array $data): void {
                    /** @var AssetIdentityAmbiguity $ambiguity */
                    $ambiguity = $this->record;
                    $asset = Asset::query()->findOrFail((int) $data['asset_id']);

                    app(AssetIdentityAmbiguityResolutionService::class)->linkToAsset($ambiguity, $asset);

                    Notification::make()
                        ->title('Ambiguity resolved')
                        ->body('Provider identifiers were linked to the selected asset.')
                        ->success()
                        ->send();

                    $this->redirect(AssetResource::getUrl('view', ['record' => $asset]));
                }),
            Action::make('createNewAsset')
                ->label('Create new asset')
                ->icon('heroicon-o-plus-circle')
                ->visible(fn (): bool => $this->canResolve() && $this->record->status === AssetIdentityAmbiguityStatus::Open)
                ->requiresConfirmation()
                ->action(function (): void {
                    /** @var AssetIdentityAmbiguity $ambiguity */
                    $ambiguity = $this->record;

                    $asset = app(AssetIdentityAmbiguityResolutionService::class)->createNewAsset($ambiguity);

                    Notification::make()
                        ->title('New asset created')
                        ->body('A new asset was created from the provider snapshot.')
                        ->success()
                        ->send();

                    $this->redirect(AssetResource::getUrl('view', ['record' => $asset]));
                }),
            Action::make('dismiss')
                ->label('Dismiss')
                ->icon('heroicon-o-x-circle')
                ->color('gray')
                ->visible(fn (): bool => $this->canResolve() && $this->record->status === AssetIdentityAmbiguityStatus::Open)
                ->requiresConfirmation()
                ->action(function (): void {
                    /** @var AssetIdentityAmbiguity $ambiguity */
                    $ambiguity = $this->record;

                    app(AssetIdentityAmbiguityResolutionService::class)->dismiss($ambiguity);

                    Notification::make()
                        ->title('Ambiguity dismissed')
                        ->success()
                        ->send();

                    $this->redirect(AssetIdentityAmbiguityResource::getUrl('index'));
                }),
        ];
    }

    private function canResolve(): bool
    {
        return Auth::user()?->hasPermissionTo('asset.update') ?? false;
    }
}

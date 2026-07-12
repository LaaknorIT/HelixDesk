<?php

namespace App\Filament\Agent\Resources\Organizations\Pages;

use App\Filament\Agent\Resources\Organizations\OrganizationResource;
use App\Models\Organization;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;
use SolutionForest\FilamentTree\Actions\Action as TreeAction;
use SolutionForest\FilamentTree\Actions\DeleteAction;
use SolutionForest\FilamentTree\Actions\EditAction;
use SolutionForest\FilamentTree\Resources\Pages\TreePage;

class OrganizationTree extends TreePage
{
    protected static string $resource = OrganizationResource::class;

    protected static int $maxDepth = 999;

    public static function getNavigationLabel(): string
    {
        return (string) config('helixdesk.organizations.navigation_label', 'Customers');
    }

    public function getTitle(): string
    {
        return static::getNavigationLabel();
    }

    public function getTreeRecordTitle(?Model $record = null): string
    {
        if (! $record instanceof Organization) {
            return '';
        }

        return sprintf('%s (%s)', $record->name, $record->type->label());
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label(__('New customer'))
                ->visible(fn (): bool => OrganizationResource::canCreate()),
            Action::make('tableView')
                ->label(__('Table view'))
                ->icon('heroicon-o-table-cells')
                ->url(fn (): string => OrganizationResource::getUrl('list')),
        ];
    }

    protected function getTreeToolbarActions(): array
    {
        return [
            $this->getCreateAction(),
        ];
    }

    protected function getTreeActions(): array
    {
        return [
            $this->getEditAction(),
            TreeAction::make('manage')
                ->label(__('Manage'))
                ->icon('heroicon-o-arrow-top-right-on-square')
                ->url(fn (Organization $record): string => OrganizationResource::getUrl('edit', ['record' => $record]))
                ->openUrlInNewTab(false)
                ->visible(fn (Organization $record): bool => OrganizationResource::canEdit($record)),
            $this->getDeleteAction(),
        ];
    }

    protected function configureCreateAction(CreateAction $action): CreateAction
    {
        return parent::configureCreateAction($action)
            ->slideOver()
            ->modalHeading(__('New customer'))
            ->mutateFormDataUsing(function (array $data): array {
                $data['tenant_id'] = Filament::getTenant()->getKey();

                if (! isset($data['parent_id']) || $data['parent_id'] === '' || $data['parent_id'] === null) {
                    $data['parent_id'] = Organization::defaultParentKey();
                }

                return $data;
            });
    }

    protected function configureEditAction(EditAction $action): EditAction
    {
        return parent::configureEditAction($action)
            ->slideOver()
            ->modalHeading(__('Edit customer'));
    }

    protected function configureDeleteAction(DeleteAction $action): DeleteAction
    {
        return parent::configureDeleteAction($action)
            ->requiresConfirmation()
            ->modalHeading(__('Delete customer'))
            ->modalDescription(__('This will permanently delete this organization and all of its child nodes.'));
    }
}

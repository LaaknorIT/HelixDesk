<?php

namespace App\Filament\Agent\Actions;

use App\Models\ServiceOffering;
use Filament\Actions\AttachAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;

final class AttachOfferingAssetAction extends AttachAction
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->schema(function (): array {
            $livewire = $this->getLivewire();
            if (! is_object($livewire) || ! method_exists($livewire, 'getOwnerRecord')) {
                return [$this->getRecordSelect()];
            }

            $owner = $livewire->getOwnerRecord();
            $roles = [];

            if ($owner instanceof ServiceOffering) {
                $roles = config('helixdesk_service_offering.asset_roles_by_kind.'.$owner->kind, []);
            }

            $roleField = $roles !== []
                ? Select::make('role')
                    ->label('Realization role')
                    ->options(array_combine($roles, $roles))
                    ->required()
                : TextInput::make('role')
                    ->label('Realization role')
                    ->required()
                    ->maxLength(255);

            return [
                $this->getRecordSelect(),
                $roleField,
            ];
        });
    }
}

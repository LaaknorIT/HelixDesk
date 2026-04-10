<?php

namespace App\Filament\Agent\Actions;

use Filament\Actions\AttachAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;

final class AttachOfferingSupplierAction extends AttachAction
{
    protected function setUp(): void
    {
        parent::setUp();

        $roles = config('helixdesk_service_offering.supplier_roles', []);

        $this->schema(fn (): array => [
            $this->getRecordSelect(),
            Select::make('role')
                ->label('Supplier role')
                ->options(array_combine($roles, $roles))
                ->required(),
            Textarea::make('notes')
                ->default(null),
        ]);
    }
}

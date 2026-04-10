<?php

namespace App\Filament\Agent\Actions;

use Filament\Actions\AttachAction;
use Filament\Forms\Components\TextInput;

final class AttachServiceOfferingToAssetAction extends AttachAction
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->schema(fn (): array => [
            $this->getRecordSelect(),
            TextInput::make('role')
                ->label('Realization role')
                ->required()
                ->maxLength(255)
                ->helperText(__('Use roles appropriate for the offering kind (e.g. primary_access, financial_application).')),
        ]);
    }
}

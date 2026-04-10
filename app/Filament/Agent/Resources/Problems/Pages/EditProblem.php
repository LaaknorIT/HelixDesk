<?php

namespace App\Filament\Agent\Resources\Problems\Pages;

use App\Filament\Agent\Resources\Problems\ProblemResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditProblem extends EditRecord
{
    protected static string $resource = ProblemResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}

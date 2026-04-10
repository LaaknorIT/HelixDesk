<?php

namespace App\Filament\Agent\Resources\Problems\Pages;

use App\Filament\Agent\Resources\Problems\ProblemResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListProblems extends ListRecords
{
    protected static string $resource = ProblemResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}

<?php

namespace App\Filament\Agent\Resources\WorkItemStatuses\Pages;

use App\Filament\Agent\Resources\WorkItemStatuses\WorkItemStatusResource;
use Filament\Resources\Pages\EditRecord;

class EditWorkItemStatus extends EditRecord
{
    protected static string $resource = WorkItemStatusResource::class;
}

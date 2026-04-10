<?php

namespace App\Filament\Agent\Resources\MonitoringEvents\Pages;

use App\Filament\Agent\Resources\MonitoringEvents\MonitoringEventResource;
use Filament\Resources\Pages\ListRecords;

class ListMonitoringEvents extends ListRecords
{
    protected static string $resource = MonitoringEventResource::class;
}

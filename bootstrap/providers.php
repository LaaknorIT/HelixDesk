<?php

use App\Providers\AppServiceProvider;
use App\Providers\Filament\AgentPanelProvider;
use App\Providers\Filament\EndUserPanelProvider;
use App\Providers\Filament\GlobalAdminPanelProvider;

return [
    AppServiceProvider::class,
    GlobalAdminPanelProvider::class,
    AgentPanelProvider::class,
    EndUserPanelProvider::class,
];

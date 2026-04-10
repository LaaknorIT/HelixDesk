<?php

use App\Providers\AppServiceProvider;
use App\Providers\Filament\AgentPanelProvider;
use App\Providers\Filament\EndUserPanelProvider;
use App\Providers\Filament\GlobalAdminPanelProvider;
use App\Providers\HorizonServiceProvider;

return [
    AppServiceProvider::class,
    GlobalAdminPanelProvider::class,
    AgentPanelProvider::class,
    EndUserPanelProvider::class,
    HorizonServiceProvider::class,
];

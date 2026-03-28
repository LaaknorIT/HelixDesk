<?php

namespace App\Filament\GlobalAdmin\Resources\Tenants\Pages;

use App\Filament\GlobalAdmin\Resources\Tenants\TenantResource;
use Filament\Resources\Pages\CreateRecord;

class CreateTenant extends CreateRecord
{
    protected static string $resource = TenantResource::class;
}

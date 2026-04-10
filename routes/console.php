<?php

use App\Jobs\OrganizationSettingsImport;
use App\Support\OrganizationIntegrationSettings;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::job(OrganizationSettingsImport::class)
    ->everyFifteenMinutes()
    ->when(fn (): bool => OrganizationIntegrationSettings::hasMicrosoftEntraIntegrationDueForImport())
    ->withoutOverlapping();

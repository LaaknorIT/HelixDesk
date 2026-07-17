<?php

namespace App\Filament\Agent\Resources\AutomationRules\Pages;

use App\Filament\Agent\Resources\AutomationRules\AutomationRuleResource;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;

class CreateAutomationRule extends CreateRecord
{
    protected static string $resource = AutomationRuleResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['tenant_id'] = Filament::getTenant()->getKey();
        $data['is_system'] = false;

        return $data;
    }
}

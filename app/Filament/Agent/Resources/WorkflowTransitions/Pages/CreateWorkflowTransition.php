<?php

namespace App\Filament\Agent\Resources\WorkflowTransitions\Pages;

use App\Filament\Agent\Resources\WorkflowTransitions\WorkflowTransitionResource;
use App\Models\WorkflowDefinition;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;

class CreateWorkflowTransition extends CreateRecord
{
    protected static string $resource = WorkflowTransitionResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $tenantId = Filament::getTenant()->getKey();
        $data['tenant_id'] = $tenantId;

        $definition = WorkflowDefinition::query()->firstOrCreate(
            [
                'tenant_id' => $tenantId,
                'entity_type' => 'work_item',
                'is_default' => true,
            ],
            ['name' => 'Default Work Item Workflow']
        );

        $data['workflow_definition_id'] = $definition->getKey();

        return $data;
    }
}

<?php

namespace App\Filament\Agent\Resources\SoftwareDeployments\Pages;

use App\Filament\Agent\Resources\SoftwareDeployments\SoftwareDeploymentResource;
use App\Models\SoftwareProduct;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;

class CreateSoftwareDeployment extends CreateRecord
{
    protected static string $resource = SoftwareDeploymentResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['tenant_id'] = Filament::getTenant()->getKey();

        if (blank($data['name'] ?? null) && filled($data['software_product_id'] ?? null)) {
            $product = SoftwareProduct::query()->find($data['software_product_id']);
            if ($product !== null) {
                $data['name'] = $product->name;
            }
        }

        return $data;
    }
}

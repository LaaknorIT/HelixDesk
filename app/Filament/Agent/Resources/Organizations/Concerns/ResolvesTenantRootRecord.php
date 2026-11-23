<?php

namespace App\Filament\Agent\Resources\Organizations\Concerns;

use App\Models\Organization;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;

/**
 * Lets the organization integration pages run for the tenant root (/organizations/root/settings/...), where
 * integrations with no organization live. The root is an unsaved Organization::tenantRoot() for the current tenant.
 */
trait ResolvesTenantRootRecord
{
    protected function resolveRecord(int|string $key): Model
    {
        if ((string) $key === Organization::TENANT_ROOT_ROUTE_KEY) {
            return $this->tenantRootRecord();
        }

        return parent::resolveRecord($key);
    }

    protected function resolveRecordFromLivewire(Model $model): Model
    {
        // Livewire sends an unsaved model back as an empty one; the only unsaved record these pages hold is the root.
        if (! $model->exists) {
            return $this->tenantRootRecord();
        }

        return parent::resolveRecordFromLivewire($model);
    }

    private function tenantRootRecord(): Organization
    {
        $tenant = Filament::getTenant();
        abort_if($tenant === null, 404);

        return Organization::tenantRoot((int) $tenant->getKey());
    }
}

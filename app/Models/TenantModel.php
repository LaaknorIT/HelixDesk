<?php

namespace App\Models;

use App\Services\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Base model for tenant-scoped entities.
 *
 * Any model extending this class must have a `tenant_id` column.
 */
abstract class TenantModel extends Model
{
    protected static function booted(): void
    {
        static::addGlobalScope('tenant', function (Builder $builder): void {
            $tenantId = app(TenantContext::class)->tenantId();

            if ($tenantId === null) {
                return;
            }

            $builder->where($builder->getModel()->getTable().'.tenant_id', $tenantId);
        });
    }
}

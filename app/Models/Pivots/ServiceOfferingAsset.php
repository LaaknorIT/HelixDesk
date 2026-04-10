<?php

namespace App\Models\Pivots;

use Illuminate\Database\Eloquent\Relations\Pivot;

class ServiceOfferingAsset extends Pivot
{
    protected $table = 'service_offering_asset';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'service_offering_id',
        'asset_id',
        'role',
    ];
}

<?php

namespace App\Models\Pivots;

use Illuminate\Database\Eloquent\Relations\Pivot;

class SoftwareDeploymentAsset extends Pivot
{
    protected $table = 'software_deployment_asset';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'software_deployment_id',
        'asset_id',
        'role',
    ];
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssetRelationship extends TenantModel
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'parent_asset_id',
        'child_asset_id',
        'relationship_type',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function parentAsset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'parent_asset_id');
    }

    public function childAsset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'child_asset_id');
    }
}

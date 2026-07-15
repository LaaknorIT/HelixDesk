<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssetAttributeProvenance extends TenantModel
{
    use HasFactory;

    protected $table = 'asset_attribute_provenance';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'asset_id',
        'attribute',
        'provider',
        'organization_integration_id',
        'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'synced_at' => 'datetime',
        ];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function organizationIntegration(): BelongsTo
    {
        return $this->belongsTo(OrganizationIntegration::class);
    }
}

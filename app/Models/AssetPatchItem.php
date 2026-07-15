<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssetPatchItem extends TenantModel
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'asset_patch_status_id',
        'asset_id',
        'organization_integration_id',
        'provider',
        'kb_id',
        'cve_id',
        'title',
        'severity',
        'approval_status',
        'last_seen_at',
        'metadata_json',
    ];

    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
            'metadata_json' => 'array',
        ];
    }

    public function assetPatchStatus(): BelongsTo
    {
        return $this->belongsTo(AssetPatchStatus::class);
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

<?php

namespace App\Models;

use App\Enums\AssetIdentityAmbiguityStatus;
use Database\Factories\AssetIdentityAmbiguityFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssetIdentityAmbiguity extends TenantModel
{
    /** @use HasFactory<AssetIdentityAmbiguityFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'organization_id',
        'organization_integration_id',
        'provider',
        'candidate_asset_ids',
        'snapshot_json',
        'status',
        'resolved_asset_id',
    ];

    protected function casts(): array
    {
        return [
            'candidate_asset_ids' => 'array',
            'snapshot_json' => 'array',
            'status' => AssetIdentityAmbiguityStatus::class,
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function organizationIntegration(): BelongsTo
    {
        return $this->belongsTo(OrganizationIntegration::class);
    }

    public function resolvedAsset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'resolved_asset_id');
    }
}

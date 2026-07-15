<?php

namespace App\Models;

use App\Enums\AssetPatchComplianceStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AssetPatchStatus extends TenantModel
{
    use HasFactory;

    protected $table = 'asset_patch_status';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'asset_id',
        'organization_integration_id',
        'provider',
        'missing_critical_count',
        'missing_other_count',
        'last_assessed_at',
        'compliance_status',
    ];

    protected function casts(): array
    {
        return [
            'missing_critical_count' => 'integer',
            'missing_other_count' => 'integer',
            'last_assessed_at' => 'datetime',
            'compliance_status' => AssetPatchComplianceStatus::class,
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

    public function patchItems(): HasMany
    {
        return $this->hasMany(AssetPatchItem::class);
    }
}

<?php

namespace App\Models;

use App\Enums\AssetSoftwareSource;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssetSoftwareInstallation extends TenantModel
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'asset_id',
        'organization_integration_id',
        'provider',
        'external_id',
        'name',
        'version',
        'publisher',
        'install_date',
        'source',
        'last_seen_at',
        'metadata_json',
    ];

    protected function casts(): array
    {
        return [
            'source' => AssetSoftwareSource::class,
            'install_date' => 'date',
            'last_seen_at' => 'datetime',
            'metadata_json' => 'array',
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

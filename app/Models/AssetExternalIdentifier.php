<?php

namespace App\Models;

use App\Enums\AssetExternalIdentifierType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssetExternalIdentifier extends TenantModel
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'asset_id',
        'organization_id',
        'organization_integration_id',
        'provider',
        'identifier_type',
        'external_id',
        'is_primary',
        'last_seen_at',
        'metadata_json',
    ];

    protected function casts(): array
    {
        return [
            'identifier_type' => AssetExternalIdentifierType::class,
            'is_primary' => 'boolean',
            'last_seen_at' => 'datetime',
            'metadata_json' => 'array',
        ];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function organizationIntegration(): BelongsTo
    {
        return $this->belongsTo(OrganizationIntegration::class);
    }
}

<?php

namespace App\Models;

use App\Models\Pivots\ServiceOfferingAsset;
use Database\Factories\AssetFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

class Asset extends TenantModel
{
    /** @use HasFactory<AssetFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'organization_id',
        'department_id',
        'asset_type_id',
        'name',
        'asset_identifier',
        'serial_number',
        'purchase_date',
        'warranty_date',
        'lifecycle_status',
        'owner_user_id',
    ];

    protected function casts(): array
    {
        return [
            'purchase_date' => 'date',
            'warranty_date' => 'date',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function assetType(): BelongsTo
    {
        return $this->belongsTo(AssetType::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function incidents(): BelongsToMany
    {
        return $this->belongsToMany(Incident::class, 'asset_incident');
    }

    public function parentRelationships(): HasMany
    {
        return $this->hasMany(AssetRelationship::class, 'parent_asset_id');
    }

    public function childRelationships(): HasMany
    {
        return $this->hasMany(AssetRelationship::class, 'child_asset_id');
    }

    public function serviceOfferings(): BelongsToMany
    {
        return $this->belongsToMany(ServiceOffering::class, 'service_offering_asset')
            ->using(ServiceOfferingAsset::class)
            ->withPivot(['role'])
            ->withTimestamps();
    }

    public function documents(): MorphToMany
    {
        return $this->morphToMany(Document::class, 'linkable', 'document_links')
            ->using(DocumentLink::class)
            ->withTimestamps();
    }
}

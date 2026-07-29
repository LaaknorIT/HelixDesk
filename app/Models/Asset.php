<?php

namespace App\Models;

use App\Enums\AssetIdentityResolutionStatus;
use App\Models\Pivots\ServiceOfferingAsset;
use App\Models\Pivots\SoftwareDeploymentAsset;
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
        'asset_type_id',
        'name',
        'asset_identifier',
        'serial_number',
        'primary_hostname',
        'operating_system',
        'purchase_date',
        'warranty_date',
        'lifecycle_status',
        'identity_resolution_status',
        'last_inventory_sync_at',
        'owner_user_id',
    ];

    protected function casts(): array
    {
        return [
            'purchase_date' => 'date',
            'warranty_date' => 'date',
            'identity_resolution_status' => AssetIdentityResolutionStatus::class,
            'last_inventory_sync_at' => 'datetime',
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

    public function workItems(): BelongsToMany
    {
        return $this->belongsToMany(WorkItem::class, 'asset_work_item');
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

    public function softwareDeployments(): BelongsToMany
    {
        return $this->belongsToMany(SoftwareDeployment::class, 'software_deployment_asset')
            ->using(SoftwareDeploymentAsset::class)
            ->withPivot(['role'])
            ->withTimestamps();
    }

    public function documents(): MorphToMany
    {
        return $this->morphToMany(Document::class, 'linkable', 'document_links')
            ->using(DocumentLink::class)
            ->withTimestamps();
    }

    public function externalIdentifiers(): HasMany
    {
        return $this->hasMany(AssetExternalIdentifier::class);
    }

    public function softwareInstallations(): HasMany
    {
        return $this->hasMany(AssetSoftwareInstallation::class);
    }

    public function patchStatuses(): HasMany
    {
        return $this->hasMany(AssetPatchStatus::class);
    }

    public function patchItems(): HasMany
    {
        return $this->hasMany(AssetPatchItem::class);
    }

    public function attributeProvenance(): HasMany
    {
        return $this->hasMany(AssetAttributeProvenance::class);
    }

    public function identityAmbiguities(): HasMany
    {
        return $this->hasMany(AssetIdentityAmbiguity::class, 'resolved_asset_id');
    }

    public function monitoringEvents(): BelongsToMany
    {
        return $this->belongsToMany(MonitoringEvent::class, 'monitoring_event_asset')
            ->withTimestamps();
    }
}

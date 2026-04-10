<?php

namespace App\Models;

use App\Models\Pivots\ServiceOfferingAsset;
use App\Models\Pivots\ServiceOfferingSupplier;
use Database\Factories\ServiceOfferingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

class ServiceOffering extends TenantModel
{
    /** @use HasFactory<ServiceOfferingFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'organization_id',
        'name',
        'description',
        'code',
        'kind',
        'attributes_json',
    ];

    protected function casts(): array
    {
        return [
            'attributes_json' => 'array',
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

    public function suppliers(): BelongsToMany
    {
        return $this->belongsToMany(Supplier::class, 'service_offering_supplier')
            ->using(ServiceOfferingSupplier::class)
            ->withPivot(['role', 'notes'])
            ->withTimestamps();
    }

    public function realizationAssets(): BelongsToMany
    {
        return $this->belongsToMany(Asset::class, 'service_offering_asset')
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

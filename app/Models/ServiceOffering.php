<?php

namespace App\Models;

use App\Models\Pivots\ServiceOfferingAsset;
use App\Models\Pivots\ServiceOfferingSupplier;
use Database\Factories\ServiceOfferingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Validation\ValidationException;

class ServiceOffering extends TenantModel
{
    /** @use HasFactory<ServiceOfferingFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
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

    public function organizations(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class, 'organization_service_offering')
            ->withTimestamps();
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

    public function softwareDeployments(): BelongsToMany
    {
        return $this->belongsToMany(SoftwareDeployment::class, 'service_offering_software_deployment')
            ->withTimestamps();
    }

    public function documents(): MorphToMany
    {
        return $this->morphToMany(Document::class, 'linkable', 'document_links')
            ->using(DocumentLink::class)
            ->withTimestamps();
    }

    /**
     * @param  list<int|string>  $organizationIds
     */
    public function syncOrganizations(array $organizationIds): void
    {
        $organizations = Organization::query()
            ->whereIn('id', $organizationIds)
            ->get();

        if ($organizations->count() !== count(array_unique(array_map('intval', $organizationIds)))) {
            throw ValidationException::withMessages([
                'organization_ids' => __('One or more organizations could not be found.'),
            ]);
        }

        foreach ($organizations as $organization) {
            if ((int) $organization->tenant_id !== (int) $this->tenant_id) {
                throw ValidationException::withMessages([
                    'organization_ids' => __('Organizations must belong to the same tenant as the service offering.'),
                ]);
            }

            if (! $organization->acceptsServiceOfferings()) {
                throw ValidationException::withMessages([
                    'organization_ids' => __('Service offerings can only be assigned to customer or group organizations.'),
                ]);
            }
        }

        $this->organizations()->sync($organizationIds);
    }
}

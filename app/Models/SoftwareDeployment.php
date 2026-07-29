<?php

namespace App\Models;

use App\Enums\SoftwareDeploymentEnvironment;
use App\Enums\SoftwareDeploymentStatus;
use App\Models\Pivots\SoftwareDeploymentAsset;
use Database\Factories\SoftwareDeploymentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Validation\ValidationException;

class SoftwareDeployment extends TenantModel
{
    /** @use HasFactory<SoftwareDeploymentFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'software_product_id',
        'organization_id',
        'name',
        'environment',
        'status',
        'external_tenant_id',
        'admin_url',
        'notes',
        'attributes_json',
    ];

    protected function casts(): array
    {
        return [
            'environment' => SoftwareDeploymentEnvironment::class,
            'status' => SoftwareDeploymentStatus::class,
            'attributes_json' => 'array',
        ];
    }

    protected static function booted(): void
    {
        parent::booted();

        static::saving(function (SoftwareDeployment $deployment): void {
            $product = $deployment->softwareProduct;
            $organization = $deployment->organization;

            if ($product !== null && (int) $product->tenant_id !== (int) $deployment->tenant_id) {
                throw ValidationException::withMessages([
                    'software_product_id' => __('The software product must belong to the same tenant.'),
                ]);
            }

            if ($organization !== null && (int) $organization->tenant_id !== (int) $deployment->tenant_id) {
                throw ValidationException::withMessages([
                    'organization_id' => __('The organization must belong to the same tenant.'),
                ]);
            }
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function softwareProduct(): BelongsTo
    {
        return $this->belongsTo(SoftwareProduct::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function serviceOfferings(): BelongsToMany
    {
        return $this->belongsToMany(ServiceOffering::class, 'service_offering_software_deployment')
            ->withTimestamps();
    }

    public function licenses(): HasMany
    {
        return $this->hasMany(SoftwareLicense::class);
    }

    public function assets(): BelongsToMany
    {
        return $this->belongsToMany(Asset::class, 'software_deployment_asset')
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
}

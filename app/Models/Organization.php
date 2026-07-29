<?php

namespace App\Models;

use App\Enums\OrganizationType;
use App\Support\OrganizationHierarchy;
use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use SolutionForest\FilamentTree\Concern\ModelTree;

class Organization extends TenantModel
{
    /** @use HasFactory<OrganizationFactory> */
    use HasFactory;

    use ModelTree;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'type',
        'parent_id',
        'name',
        'code',
        'is_billable',
        'order',
        'external_reference',
        'organizational_settings',
    ];

    protected function casts(): array
    {
        return [
            'type' => OrganizationType::class,
            'is_billable' => 'boolean',
            'order' => 'integer',
            'parent_id' => 'integer',
            'organizational_settings' => 'array',
        ];
    }

    protected static function booted(): void
    {
        parent::booted();

        static::saving(function (Organization $organization): void {
            OrganizationHierarchy::assertValidParent($organization);
        });
    }

    public function determineTitleColumnName(): string
    {
        return 'name';
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class);
    }

    public function serviceOfferings(): BelongsToMany
    {
        return $this->belongsToMany(ServiceOffering::class, 'organization_service_offering')
            ->withTimestamps();
    }

    public function softwareDeployments(): HasMany
    {
        return $this->hasMany(SoftwareDeployment::class);
    }

    public function acceptsServiceOfferings(): bool
    {
        return $this->type->acceptsServiceOfferings();
    }

    public function organizationIntegrations(): HasMany
    {
        return $this->hasMany(OrganizationIntegration::class);
    }
}

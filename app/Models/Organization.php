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

    private bool $isTenantRoot = false;

    /** Route key that stands for the tenant root in organization URLs (/organizations/root/settings). */
    public const TENANT_ROOT_ROUTE_KEY = 'root';

    /**
     * The tenant itself, above every organization, as an unsaved organization with no ID. Integrations attached
     * here have no organization_id and see devices in the whole tenant; devices they create have no organization
     * until a more specific integration finds them. It is never saved.
     */
    public static function tenantRoot(int $tenantId): self
    {
        $root = new self;
        $root->isTenantRoot = true;
        $root->forceFill([
            'tenant_id' => $tenantId,
            'type' => OrganizationType::Group,
            'name' => __('All organizations (root level)'),
            'is_billable' => false,
        ]);

        return $root;
    }

    public function isTenantRoot(): bool
    {
        return $this->isTenantRoot;
    }

    /**
     * The organization an integration is attached to, or the tenant root when it has none.
     */
    public static function forIntegrationScope(?int $organizationId, int $tenantId): ?self
    {
        if ($organizationId === null) {
            return self::tenantRoot($tenantId);
        }

        return self::query()->withoutGlobalScopes()->where('tenant_id', $tenantId)->find($organizationId);
    }

    public function getRouteKey(): mixed
    {
        return $this->isTenantRoot ? self::TENANT_ROOT_ROUTE_KEY : parent::getRouteKey();
    }

    public function routeKeyForIntegrations(): int|string
    {
        return $this->isTenantRoot() ? self::TENANT_ROOT_ROUTE_KEY : (int) $this->getKey();
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

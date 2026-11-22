<?php

namespace App\Models;

use App\Repositories\OrganizationIntegrationRepository;
use App\Support\IntegrationType;
use Database\Factories\OrganizationIntegrationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class OrganizationIntegration extends TenantModel
{
    /** @use HasFactory<OrganizationIntegrationFactory> */
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'id',
        'tenant_id',
        'organization_id',
        'type',
        'enabled',
        'config',
        'credentials',
        'api_base_url',
        'last_sync_at',
        'last_sync_error',
        'last_sync_failed_at',
        'health_metadata',
        'sync_interval_hours',
        'scope_key',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'config' => 'array',
            'credentials' => 'encrypted:array',
            'last_sync_at' => 'datetime',
            'last_sync_failed_at' => 'datetime',
            'health_metadata' => 'array',
            'sync_interval_hours' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        parent::booted();

        static::creating(function (OrganizationIntegration $integration): void {
            $key = $integration->getKey();
            if ($key === null || $key === '') {
                $integration->id = (string) Str::uuid();
            }
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
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

    public function identityAmbiguities(): HasMany
    {
        return $this->hasMany(AssetIdentityAmbiguity::class);
    }

    public function isMicrosoftEntra(): bool
    {
        return $this->type === IntegrationType::MICROSOFT_ENTRA;
    }

    public function isGoogleWorkspace(): bool
    {
        return $this->type === IntegrationType::GOOGLE_WORKSPACE;
    }

    public function isAction1(): bool
    {
        return $this->type === IntegrationType::ACTION1;
    }

    /**
     * @return array<string, mixed>
     */
    public function mergedConfig(): array
    {
        return is_array($this->config) ? $this->config : [];
    }

    /**
     * @return array<string, mixed>
     */
    public function mergedCredentials(): array
    {
        return is_array($this->credentials) ? $this->credentials : [];
    }

    /**
     * Config merged with decrypted credentials, as the sync services and readiness checks expect it.
     *
     * @return array<string, mixed>
     */
    public function configWithCredentials(): array
    {
        return array_merge(
            $this->mergedConfig(),
            OrganizationIntegrationRepository::credentialsIntoConfig((string) $this->type, $this->mergedCredentials()),
        );
    }

    /**
     * Health data recorded by sync runs and connection checks (granted permissions, scope warnings, ...).
     *
     * @return array<string, mixed>
     */
    public function healthMetadata(): array
    {
        return is_array($this->health_metadata) ? $this->health_metadata : [];
    }

    /**
     * @param  array<string, mixed>  $patch
     */
    public function mergeHealthMetadata(array $patch): void
    {
        $this->health_metadata = array_merge($this->healthMetadata(), $patch);
        $this->save();
    }

    public static function resolveScopeKey(string $type, array $config): string
    {
        if ($type === IntegrationType::ACTION1) {
            $groupId = trim((string) ($config['action1_group_id'] ?? ''));

            return $groupId !== '' ? $groupId : '';
        }

        return '';
    }
}

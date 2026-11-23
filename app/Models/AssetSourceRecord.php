<?php

namespace App\Models;

use App\Enums\DeviceClass;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One device as one device source (Intune, Action1, Active Directory, …) reports it, whether or not it is linked
 * to an asset. Feeds the device coverage report.
 */
class AssetSourceRecord extends TenantModel
{
    public const DEFAULT_ACTIVE_THRESHOLD_DAYS = 30;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_STALE = 'stale';

    public const STATUS_DISABLED = 'disabled';

    public const STATUS_GONE = 'gone';

    /** Seen by the source, but not managed or protected by it (see DeviceSourceRecordData::$discoveredOnly). */
    public const STATUS_DISCOVERED = 'discovered';

    public const METADATA_DISCOVERED_ONLY = 'discovered_only';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'organization_id',
        'organization_integration_id',
        'source',
        'provider_record_id',
        'asset_id',
        'display_name',
        'hostname',
        'fqdn',
        'serial_number',
        'mac_addresses',
        'operating_system',
        'device_class',
        'enabled',
        'last_activity_at',
        'first_seen_at',
        'last_reported_at',
        'gone_at',
        'metadata_json',
    ];

    protected function casts(): array
    {
        return [
            'mac_addresses' => 'array',
            'device_class' => DeviceClass::class,
            'enabled' => 'boolean',
            'last_activity_at' => 'datetime',
            'first_seen_at' => 'datetime',
            'last_reported_at' => 'datetime',
            'gone_at' => 'datetime',
            'metadata_json' => 'array',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
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

    public function isDiscoveredOnly(): bool
    {
        return ($this->metadata_json[self::METADATA_DISCOVERED_ONLY] ?? false) === true;
    }

    /**
     * Still reported, managed by the source, not disabled, and active within the threshold.
     */
    public function isActive(?int $thresholdDays = null, ?Carbon $now = null): bool
    {
        if ($this->gone_at !== null || $this->enabled === false || $this->last_activity_at === null || $this->isDiscoveredOnly()) {
            return false;
        }

        $now ??= now();

        return $this->last_activity_at->greaterThanOrEqualTo(
            $now->copy()->subDays($thresholdDays ?? self::DEFAULT_ACTIVE_THRESHOLD_DAYS),
        );
    }

    public function status(?int $thresholdDays = null): string
    {
        return match (true) {
            $this->gone_at !== null => self::STATUS_GONE,
            $this->isDiscoveredOnly() => self::STATUS_DISCOVERED,
            $this->enabled === false => self::STATUS_DISABLED,
            $this->isActive($thresholdDays) => self::STATUS_ACTIVE,
            default => self::STATUS_STALE,
        };
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            self::STATUS_ACTIVE => __('Active'),
            self::STATUS_STALE => __('Stale'),
            self::STATUS_DISABLED => __('Disabled'),
            self::STATUS_GONE => __('No longer reported'),
            self::STATUS_DISCOVERED => __('Discovered, not onboarded'),
            default => $status,
        };
    }

    /**
     * @param  Builder<AssetSourceRecord>  $query
     * @return Builder<AssetSourceRecord>
     */
    public function scopeActive(Builder $query, ?int $thresholdDays = null): Builder
    {
        return $query
            ->whereNull('gone_at')
            ->where(fn (Builder $q) => $q->whereNull('enabled')->orWhere('enabled', true))
            ->where(fn (Builder $q) => $q->whereNull('metadata_json->'.self::METADATA_DISCOVERED_ONLY)
                ->orWhere('metadata_json->'.self::METADATA_DISCOVERED_ONLY, false))
            ->where('last_activity_at', '>=', now()->subDays($thresholdDays ?? self::DEFAULT_ACTIVE_THRESHOLD_DAYS));
    }
}

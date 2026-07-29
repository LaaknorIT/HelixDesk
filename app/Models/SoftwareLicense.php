<?php

namespace App\Models;

use App\Enums\SoftwareLicenseBillingPeriod;
use App\Enums\SoftwareLicenseMetric;
use App\Enums\SoftwareLicenseType;
use Database\Factories\SoftwareLicenseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

class SoftwareLicense extends TenantModel
{
    /** @use HasFactory<SoftwareLicenseFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'software_deployment_id',
        'name',
        'sku',
        'product_code',
        'license_type',
        'metric',
        'seat_count',
        'seats_used',
        'starts_on',
        'expires_on',
        'auto_renew',
        'cost_amount',
        'cost_currency',
        'billing_period',
        'vendor_contract_reference',
        'purchase_order_reference',
        'notes',
        'attributes_json',
    ];

    protected function casts(): array
    {
        return [
            'license_type' => SoftwareLicenseType::class,
            'metric' => SoftwareLicenseMetric::class,
            'billing_period' => SoftwareLicenseBillingPeriod::class,
            'seat_count' => 'integer',
            'seats_used' => 'integer',
            'starts_on' => 'date',
            'expires_on' => 'date',
            'auto_renew' => 'boolean',
            'cost_amount' => 'decimal:2',
            'attributes_json' => 'array',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function softwareDeployment(): BelongsTo
    {
        return $this->belongsTo(SoftwareDeployment::class);
    }

    public function documents(): MorphToMany
    {
        return $this->morphToMany(Document::class, 'linkable', 'document_links')
            ->using(DocumentLink::class)
            ->withTimestamps();
    }
}

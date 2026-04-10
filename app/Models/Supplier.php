<?php

namespace App\Models;

use App\Models\Pivots\ServiceOfferingSupplier;
use Database\Factories\SupplierFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

class Supplier extends TenantModel
{
    /** @use HasFactory<SupplierFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'name',
        'notes',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function serviceOfferings(): BelongsToMany
    {
        return $this->belongsToMany(ServiceOffering::class, 'service_offering_supplier')
            ->using(ServiceOfferingSupplier::class)
            ->withPivot(['role', 'notes'])
            ->withTimestamps();
    }

    public function documents(): MorphToMany
    {
        return $this->morphToMany(Document::class, 'linkable', 'document_links')
            ->using(DocumentLink::class)
            ->withTimestamps();
    }
}

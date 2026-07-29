<?php

namespace App\Models;

use Database\Factories\SoftwareProductFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Validation\ValidationException;

class SoftwareProduct extends TenantModel
{
    /** @use HasFactory<SoftwareProductFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'name',
        'vendor_name',
        'supplier_id',
        'category',
        'description',
        'partner_notes',
        'external_reference',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        parent::booted();

        static::saving(function (SoftwareProduct $product): void {
            $exists = static::query()
                ->where('tenant_id', $product->tenant_id)
                ->whereRaw('LOWER(name) = ?', [mb_strtolower($product->name)])
                ->when($product->exists, fn ($q) => $q->whereKeyNot($product->getKey()))
                ->exists();

            if ($exists) {
                throw ValidationException::withMessages([
                    'name' => __('A software product with this name already exists for the tenant.'),
                ]);
            }
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function deployments(): HasMany
    {
        return $this->hasMany(SoftwareDeployment::class);
    }

    public function documents(): MorphToMany
    {
        return $this->morphToMany(Document::class, 'linkable', 'document_links')
            ->using(DocumentLink::class)
            ->withTimestamps();
    }
}

<?php

namespace App\Models;

use Database\Factories\DocumentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Str;

class Document extends TenantModel
{
    /** @use HasFactory<DocumentFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'doc_type',
        'title',
        'slug',
        'content',
        'status',
        'visibility',
        'owner_user_id',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function workItems(): MorphToMany
    {
        return $this->morphedByMany(WorkItem::class, 'linkable', 'document_links')
            ->using(DocumentLink::class)
            ->withTimestamps();
    }

    public function serviceOfferings(): MorphToMany
    {
        return $this->morphedByMany(ServiceOffering::class, 'linkable', 'document_links')
            ->using(DocumentLink::class)
            ->withTimestamps();
    }

    public function suppliers(): MorphToMany
    {
        return $this->morphedByMany(Supplier::class, 'linkable', 'document_links')
            ->using(DocumentLink::class)
            ->withTimestamps();
    }

    public function assets(): MorphToMany
    {
        return $this->morphedByMany(Asset::class, 'linkable', 'document_links')
            ->using(DocumentLink::class)
            ->withTimestamps();
    }

    public static function uniqueSlugForTenant(int|string $tenantId, string $title, ?int $ignoreId = null): string
    {
        $base = Str::slug($title);
        if ($base === '') {
            $base = 'document';
        }

        $slug = $base;
        $suffix = 1;

        while (static::query()
            ->where('tenant_id', $tenantId)
            ->where('slug', $slug)
            ->when($ignoreId !== null, fn ($q) => $q->whereKeyNot($ignoreId))
            ->exists()) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }
}

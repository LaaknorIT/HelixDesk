<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphPivot;
use Illuminate\Validation\ValidationException;

class DocumentLink extends MorphPivot
{
    protected $table = 'document_links';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'document_id',
        'linkable_id',
        'linkable_type',
    ];

    protected static function booted(): void
    {
        static::creating(function (DocumentLink $link): void {
            if ($link->tenant_id !== null) {
                return;
            }

            if ($link->document_id === null) {
                return;
            }

            $tenantId = Document::query()
                ->withoutGlobalScopes()
                ->whereKey($link->document_id)
                ->value('tenant_id');

            $link->tenant_id = $tenantId;
        });

        static::creating(function (DocumentLink $link): void {
            if ($link->document_id === null || $link->linkable_type === null || $link->linkable_id === null) {
                return;
            }

            $docTenant = Document::query()
                ->withoutGlobalScopes()
                ->whereKey($link->document_id)
                ->value('tenant_id');

            if ($docTenant === null) {
                return;
            }

            /** @var class-string<Model> $class */
            $class = $link->linkable_type;

            if (! is_subclass_of($class, Model::class)) {
                return;
            }

            $linkable = $class::query()
                ->withoutGlobalScopes()
                ->whereKey($link->linkable_id)
                ->first();

            if ($linkable === null) {
                return;
            }

            if (! isset($linkable->tenant_id)) {
                return;
            }

            if ((int) $linkable->tenant_id !== (int) $docTenant) {
                throw ValidationException::withMessages([
                    'linkable' => __('Document and linked record must belong to the same tenant.'),
                ]);
            }
        });
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\MorphPivot;

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
    }
}

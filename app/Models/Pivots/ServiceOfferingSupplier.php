<?php

namespace App\Models\Pivots;

use Illuminate\Database\Eloquent\Relations\Pivot;

class ServiceOfferingSupplier extends Pivot
{
    protected $table = 'service_offering_supplier';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'service_offering_id',
        'supplier_id',
        'role',
        'notes',
    ];
}

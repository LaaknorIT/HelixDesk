<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One full inventory snapshot pushed by an on-premises collector (for example the Active Directory collector).
 * Records the run did not report are only marked gone when the run completes with the expected count.
 */
class CollectorRun extends TenantModel
{
    use HasUuids;

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_INCOMPLETE = 'incomplete';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'organization_integration_id',
        'status',
        'received_count',
        'skipped_count',
        'expected_count',
        'batch_sequences',
        'collector_version',
        'collector_host',
        'started_at',
        'completed_at',
        'error',
    ];

    protected function casts(): array
    {
        return [
            'received_count' => 'integer',
            'skipped_count' => 'integer',
            'expected_count' => 'integer',
            'batch_sequences' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function organizationIntegration(): BelongsTo
    {
        return $this->belongsTo(OrganizationIntegration::class);
    }
}

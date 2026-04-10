<?php

namespace App\Models;

use Database\Factories\DashboardLayoutFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

class DashboardLayout extends TenantModel
{
    /** @use HasFactory<DashboardLayoutFactory> */
    use HasFactory;

    public const SCOPE_ROLE_DEFAULT = 'role_default';

    public const SCOPE_USER_OVERRIDE = 'user_override';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'scope',
        'role_name',
        'user_id',
        'layout_json',
        'locked_widgets',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'layout_json' => 'array',
            'locked_widgets' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function resolveEffectiveForUser(User $user): ?self
    {
        $userOverride = static::query()
            ->where('tenant_id', $user->tenant_id)
            ->where('scope', self::SCOPE_USER_OVERRIDE)
            ->where('user_id', $user->getKey())
            ->where('is_active', true)
            ->latest('id')
            ->first();

        if ($userOverride !== null) {
            return $userOverride;
        }

        setPermissionsTeamId($user->tenant_id);

        try {
            /** @var Collection<int, string> $roleNames */
            $roleNames = $user->roles()->pluck('name');
        } finally {
            setPermissionsTeamId(null);
        }

        if ($roleNames->isEmpty()) {
            return null;
        }

        $tenantAdminRole = config('helixdesk_permissions.tenant_roles.tenant_admin.name');
        $agentRole = config('helixdesk_permissions.tenant_roles.agent.name');

        return static::query()
            ->where('tenant_id', $user->tenant_id)
            ->where('scope', self::SCOPE_ROLE_DEFAULT)
            ->whereIn('role_name', $roleNames->all())
            ->where('is_active', true)
            ->orderByRaw('CASE role_name WHEN ? THEN 0 WHEN ? THEN 1 ELSE 2 END', [$tenantAdminRole, $agentRole])
            ->latest('id')
            ->first();
    }
}

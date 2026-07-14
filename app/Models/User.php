<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasTenants;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Contracts\Permission;
use Spatie\Permission\Traits\HasRoles;

use function Illuminate\Support\enum_value;

class User extends Authenticatable implements FilamentUser, HasTenants
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'organization_id',
        'entra_object_id',
        'name',
        'email',
        'password',
        'type',
        'last_mfa_at',
        'is_admin',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_mfa_at' => 'datetime',
            'is_admin' => 'boolean',
            'password' => 'hashed',
        ];
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return match ($panel->getId()) {
            'global-admin' => (bool) $this->is_admin,
            'agent' => $this->isTenantStaff() || ($this->is_admin && $this->hasAnyTenantAdminAssignment()),
            'end-user' => $this->tenant_id !== null && $this->type === 'end_user',
            default => false,
        };
    }

    /**
     * Back-office users for a tenant (agents, tenant admins). Permissions come from Spatie roles, not from `type`.
     */
    public function isTenantStaff(): bool
    {
        return $this->tenant_id !== null && $this->type === 'agent';
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function dashboardLayouts(): HasMany
    {
        return $this->hasMany(DashboardLayout::class);
    }

    public function isTenantAdminFor(Tenant|int $tenant): bool
    {
        $tenantId = is_int($tenant) ? $tenant : (int) $tenant->getKey();

        if ($tenantId <= 0) {
            return false;
        }

        $roleName = config('helixdesk_permissions.tenant_roles.tenant_admin.name');

        return DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_type', $this->getMorphClass())
            ->where('model_has_roles.model_id', $this->getKey())
            ->where('model_has_roles.team_id', $tenantId)
            ->where('roles.name', $roleName)
            ->exists();
    }

    public function hasAnyTenantAdminAssignment(): bool
    {
        $roleName = config('helixdesk_permissions.tenant_roles.tenant_admin.name');

        return DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_type', $this->getMorphClass())
            ->where('model_has_roles.model_id', $this->getKey())
            ->where('roles.name', $roleName)
            ->exists();
    }

    public function canAccessTenant(Model $tenant): bool
    {
        if ($this->tenant_id !== null && (int) $this->tenant_id === (int) $tenant->getKey()) {
            return true;
        }

        return $this->is_admin && $this->isTenantAdminFor($tenant);
    }

    public function getTenants(Panel $panel): array|Collection
    {
        $tenants = collect();

        if ($this->tenant !== null) {
            $tenants->push($this->tenant);
        }

        if ($this->is_admin) {
            foreach ($this->tenantAdminAssignmentTenants() as $assignedTenant) {
                if (! $tenants->contains(fn (Tenant $existing): bool => (int) $existing->getKey() === (int) $assignedTenant->getKey())) {
                    $tenants->push($assignedTenant);
                }
            }
        }

        return $tenants;
    }

    /**
     * @return Collection<int, Tenant>
     */
    public function tenantAdminAssignmentTenants(): Collection
    {
        $roleName = config('helixdesk_permissions.tenant_roles.tenant_admin.name');

        $teamIds = DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_type', $this->getMorphClass())
            ->where('model_has_roles.model_id', $this->getKey())
            ->where('roles.name', $roleName)
            ->pluck('model_has_roles.team_id')
            ->unique()
            ->values();

        if ($teamIds->isEmpty()) {
            return collect();
        }

        return Tenant::query()
            ->whereIn('id', $teamIds)
            ->get();
    }

    /**
     * Permissions are stored as strings on `role_permissions` (no `permissions` table).
     *
     * @param  string|int|\BackedEnum|Permission|null  $permission
     */
    public function hasPermissionTo($permission, $guardName = null): bool
    {
        $guardName ??= $this->getDefaultGuardName();
        $permission = enum_value($permission);

        if ($permission instanceof Permission) {
            $permission = $permission->name;
        }

        if (! is_string($permission)) {
            return false;
        }

        $teamId = getPermissionsTeamId() ?? $this->tenant_id;

        if ($teamId === null) {
            return false;
        }

        return $this->helixdeskPermissionNamesForTeam((int) $teamId, $guardName)->contains($permission);
    }

    /**
     * @param  string|int|\BackedEnum|Permission|null  $permission
     */
    public function checkPermissionTo($permission, $guardName = null): bool
    {
        return $this->hasPermissionTo($permission, $guardName);
    }

    /**
     * @return Collection<int, string>
     */
    protected function helixdeskPermissionNamesForTeam(int $teamId, string $guardName): Collection
    {
        return DB::table('role_permissions')
            ->join('model_has_roles', 'model_has_roles.role_id', '=', 'role_permissions.role_id')
            ->where('model_has_roles.model_type', $this->getMorphClass())
            ->where('model_has_roles.model_id', $this->getKey())
            ->where('model_has_roles.team_id', $teamId)
            ->where('role_permissions.guard_name', $guardName)
            ->pluck('role_permissions.permission')
            ->unique()
            ->values();
    }
}

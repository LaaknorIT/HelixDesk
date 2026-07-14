<?php

namespace App\Services\MicrosoftEntra;

use App\Enums\OrganizationType;
use App\Models\Asset;
use App\Models\AssetType;
use App\Models\MonitoringEvent;
use App\Models\MonitoringEventSource;
use App\Models\Organization;
use App\Models\User;
use App\Services\TenantContext;
use App\Support\EntraSyncTarget;
use App\Support\OrganizationIntegrationSettings;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

final class MicrosoftEntraOrganizationSyncService
{
    public function __construct(
        private readonly TenantContext $tenantContext,
    ) {}

    public function sync(EntraSyncTarget $target): void
    {
        $client = MicrosoftGraphHttpClient::fromTarget($target);
        $client->authenticate();

        $this->tenantContext->setTenantId($target->tenantId);
        setPermissionsTeamId($target->tenantId);

        try {
            $organization = Organization::query()->find($target->organizationId);
            if ($organization === null) {
                throw new \RuntimeException('Organization not found: '.$target->organizationId);
            }

            $scopes = $target->config['scopes'] ?? [];
            $scopes = is_array($scopes) ? $scopes : [];

            if (($scopes['users'] ?? false) || ($scopes['departments'] ?? false)) {
                $this->syncDirectoryUsers(
                    $client,
                    $target,
                    $organization,
                    importUsers: (bool) ($scopes['users'] ?? false),
                    linkDepartments: (bool) ($scopes['departments'] ?? false),
                );
            }

            if (($scopes['computers'] ?? false)) {
                $this->syncManagedDevices($client, $target, $organization);
            }

            if (($scopes['security_work_items'] ?? false)) {
                $this->syncSecurityIncidents($client, $target, $organization);
            }

            OrganizationIntegrationSettings::patchMicrosoftEntraIntegrationConfig(
                $organization,
                $target->integrationId,
                [
                    'last_entra_sync_at' => now()->toIso8601String(),
                    'last_entra_sync_error' => null,
                ]
            );
        } finally {
            $this->tenantContext->setTenantId(null);
            setPermissionsTeamId(null);
        }
    }

    public function recordFailure(EntraSyncTarget $target, Throwable $exception): void
    {
        $this->tenantContext->setTenantId($target->tenantId);
        setPermissionsTeamId($target->tenantId);

        try {
            $organization = Organization::query()->find($target->organizationId);
            if ($organization === null) {
                return;
            }

            OrganizationIntegrationSettings::patchMicrosoftEntraIntegrationConfig(
                $organization,
                $target->integrationId,
                [
                    'last_entra_sync_error' => Str::limit($exception->getMessage(), 2000),
                ]
            );
        } finally {
            $this->tenantContext->setTenantId(null);
            setPermissionsTeamId(null);
        }
    }

    private function syncDirectoryUsers(
        MicrosoftGraphHttpClient $client,
        EntraSyncTarget $target,
        Organization $organization,
        bool $importUsers,
        bool $linkDepartments,
    ): void {
        foreach ($client->paginateCollection('users', [
            '$select' => 'id,userPrincipalName,mail,displayName,department,userType,accountEnabled',
            '$filter' => "userType eq 'Member'",
            '$top' => 999,
        ]) as $u) {
            if ($this->shouldExcludeDirectoryUser($u)) {
                continue;
            }

            $graphId = (string) ($u['id'] ?? '');
            if ($graphId === '') {
                continue;
            }

            $email = $this->resolveUserEmail($u);
            if ($email === null) {
                continue;
            }

            $scopeOrganizationId = null;
            $deptName = trim((string) ($u['department'] ?? ''));
            if ($linkDepartments && $deptName !== '') {
                $departmentOrganization = Organization::query()->firstOrCreate(
                    [
                        'tenant_id' => $target->tenantId,
                        'parent_id' => $organization->getKey(),
                        'type' => OrganizationType::Department->value,
                        'name' => $deptName,
                    ],
                    [
                        'code' => null,
                        'external_reference' => null,
                        'is_billable' => false,
                        'order' => 0,
                    ]
                );
                $scopeOrganizationId = (int) $departmentOrganization->getKey();
            }

            if (! $importUsers) {
                continue;
            }

            $displayName = trim((string) ($u['displayName'] ?? ''));
            if ($displayName === '') {
                $displayName = $email;
            }

            $userOrganizationId = $scopeOrganizationId ?? $organization->getKey();

            try {
                $user = User::query()
                    ->where('tenant_id', $target->tenantId)
                    ->where('entra_object_id', $graphId)
                    ->first();

                if ($user === null) {
                    User::query()->create([
                        'tenant_id' => $target->tenantId,
                        'entra_object_id' => $graphId,
                        'name' => $displayName,
                        'email' => $email,
                        'organization_id' => $userOrganizationId,
                        'type' => 'end_user',
                        'password' => Hash::make(Str::password(32)),
                    ]);
                } else {
                    $user->update([
                        'name' => $displayName,
                        'email' => $email,
                        'organization_id' => $userOrganizationId,
                        'type' => 'end_user',
                    ]);
                }
            } catch (UniqueConstraintViolationException) {
                Log::warning('Microsoft Entra user skipped: email already exists for another record.', [
                    'tenant_id' => $target->tenantId,
                    'entra_object_id' => $graphId,
                    'email' => $email,
                ]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $u
     */
    private function shouldExcludeDirectoryUser(array $u): bool
    {
        if (($u['accountEnabled'] ?? true) === false) {
            return true;
        }

        $dn = strtolower((string) ($u['displayName'] ?? ''));
        if (str_contains($dn, 'shared mailbox')) {
            return true;
        }

        $upn = strtolower((string) ($u['userPrincipalName'] ?? ''));
        if (str_contains($upn, '-room@') || str_contains($upn, '.room@')) {
            return true;
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $u
     */
    private function resolveUserEmail(array $u): ?string
    {
        $mail = trim((string) ($u['mail'] ?? ''));
        if ($mail !== '' && filter_var($mail, FILTER_VALIDATE_EMAIL)) {
            return $mail;
        }

        $upn = trim((string) ($u['userPrincipalName'] ?? ''));
        if ($upn !== '' && filter_var($upn, FILTER_VALIDATE_EMAIL)) {
            return $upn;
        }

        return null;
    }

    private function syncManagedDevices(
        MicrosoftGraphHttpClient $client,
        EntraSyncTarget $target,
        Organization $organization,
    ): void {
        $assetType = AssetType::query()->firstOrCreate(
            [
                'tenant_id' => $target->tenantId,
                'name' => 'Computer',
            ],
            []
        );

        foreach ($client->paginateCollection('deviceManagement/managedDevices', [
            '$select' => 'id,deviceName,serialNumber,emailAddress,userPrincipalName,azureADDeviceId,operatingSystem',
            '$top' => 100,
        ]) as $device) {
            $identifier = trim((string) ($device['azureADDeviceId'] ?? ''));
            if ($identifier === '') {
                $identifier = (string) ($device['id'] ?? '');
            }
            if ($identifier === '') {
                continue;
            }

            $upn = trim((string) ($device['userPrincipalName'] ?? ''));
            $mail = trim((string) ($device['emailAddress'] ?? ''));
            $owner = null;
            if ($upn !== '') {
                $owner = User::query()->where('tenant_id', $target->tenantId)->where('email', $upn)->first();
            }
            if ($owner === null && $mail !== '') {
                $owner = User::query()->where('tenant_id', $target->tenantId)->where('email', $mail)->first();
            }

            $name = trim((string) ($device['deviceName'] ?? ''));
            if ($name === '') {
                $name = $identifier;
            }

            $asset = Asset::query()
                ->where('tenant_id', $target->tenantId)
                ->where('organization_id', $organization->getKey())
                ->where('asset_identifier', $identifier)
                ->first();

            if ($asset === null) {
                Asset::query()->create([
                    'tenant_id' => $target->tenantId,
                    'organization_id' => $organization->getKey(),
                    'asset_type_id' => $assetType->getKey(),
                    'name' => $name,
                    'asset_identifier' => $identifier,
                    'serial_number' => trim((string) ($device['serialNumber'] ?? '')) ?: null,
                    'lifecycle_status' => 'in_use',
                    'owner_user_id' => $owner?->getKey(),
                ]);
            } else {
                $asset->update([
                    'asset_type_id' => $assetType->getKey(),
                    'name' => $name,
                    'serial_number' => trim((string) ($device['serialNumber'] ?? '')) ?: null,
                    'owner_user_id' => $owner?->getKey(),
                ]);
            }
        }
    }

    private function syncSecurityIncidents(
        MicrosoftGraphHttpClient $client,
        EntraSyncTarget $target,
        Organization $organization,
    ): void {
        $source = $this->monitoringEventSource($target, $organization);

        foreach ($client->paginateCollection('security/work_items', [
            '$top' => 50,
        ]) as $workItem) {
            $extId = (string) ($workItem['id'] ?? '');
            if ($extId === '') {
                continue;
            }

            $graphStatus = strtolower((string) ($workItem['status'] ?? 'active'));
            $closed = $this->graphIncidentIsClosed($graphStatus);
            $ourStatus = $closed ? 'closed' : 'open';

            $severity = $this->normalizeSeverity($workItem['severity'] ?? 'medium');
            $title = (string) ($workItem['displayName'] ?? 'Security incident');

            $existing = MonitoringEvent::query()
                ->where('tenant_id', $target->tenantId)
                ->where('monitoring_event_source_id', $source->getKey())
                ->where('external_reference', $extId)
                ->first();

            $now = now();
            if ($existing === null) {
                MonitoringEvent::query()->create([
                    'tenant_id' => $target->tenantId,
                    'monitoring_event_source_id' => $source->getKey(),
                    'external_reference' => $extId,
                    'severity' => $severity,
                    'message' => $title,
                    'status' => $ourStatus,
                    'deduplication_key' => 'microsoft-graph-security:'.$target->tenantId.':'.$extId,
                    'occurrence_count' => 1,
                    'first_seen_at' => $now,
                    'last_seen_at' => $now,
                    'resolved_at' => $closed ? $now : null,
                ]);

                continue;
            }

            $existing->severity = $severity;
            $existing->message = $title;
            $existing->status = $ourStatus;
            $existing->last_seen_at = $now;
            if ($closed) {
                $existing->resolved_at = $existing->resolved_at ?? $now;
            } else {
                $existing->resolved_at = null;
            }
            $existing->save();
        }
    }

    private function monitoringEventSource(EntraSyncTarget $target, Organization $organization): MonitoringEventSource
    {
        return MonitoringEventSource::query()->firstOrCreate(
            [
                'tenant_id' => $target->tenantId,
                'slug' => 'microsoft-graph-security-org-'.$organization->getKey(),
            ],
            [
                'name' => 'Microsoft Graph security ('.$organization->name.')',
                'provider' => 'microsoft_entra',
                'configuration_json' => [
                    'organization_id' => $organization->getKey(),
                ],
            ]
        );
    }

    private function graphIncidentIsClosed(string $graphStatus): bool
    {
        return in_array($graphStatus, ['resolved', 'closed', 'redirected'], true);
    }

    private function normalizeSeverity(mixed $severity): string
    {
        $s = is_string($severity) ? strtolower(trim($severity)) : 'medium';

        return match ($s) {
            'informational', 'info' => 'low',
            'med' => 'medium',
            default => $s !== '' ? $s : 'medium',
        };
    }
}

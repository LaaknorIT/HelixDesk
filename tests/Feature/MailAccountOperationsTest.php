<?php

namespace Tests\Feature;

use App\Filament\Agent\Resources\MailAccounts\Pages\EditMailAccount;
use App\Filament\Agent\Resources\MailAccounts\Pages\ListMailAccounts;
use App\Models\MailAccount;
use App\Models\Organization;
use App\Models\OrganizationIntegration;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Mail\MailAccountConnectionVerifier;
use App\Services\Mail\MailAccountConnectionVerifyResult;
use App\Services\Mail\MailAccountPollResult;
use App\Services\Mail\MailAccountPollService;
use App\Support\OrganizationIntegrationSettings;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

class MailAccountOperationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_admin_can_run_test_connection_and_check_email_from_edit_page(): void
    {
        [$tenant, $admin, $account] = $this->tenantAdminWithGraphAccount();

        $verifier = Mockery::mock(MailAccountConnectionVerifier::class);
        $verifier->shouldReceive('verify')
            ->once()
            ->andReturn(new MailAccountConnectionVerifyResult(
                ok: true,
                message: 'Microsoft Graph access succeeded.',
            ));
        $this->app->instance(MailAccountConnectionVerifier::class, $verifier);

        $poller = Mockery::mock(MailAccountPollService::class);
        $poller->shouldReceive('poll')
            ->once()
            ->andReturn(new MailAccountPollResult(fetched: 0, ingested: 0));
        $this->app->instance(MailAccountPollService::class, $poller);

        $this->actingAs($admin);
        Filament::setCurrentPanel('agent');
        Filament::setTenant($tenant, isQuiet: true);
        setPermissionsTeamId($tenant->id);

        try {
            Livewire::test(EditMailAccount::class, [
                'tenant' => $tenant,
                'record' => $account->getKey(),
            ])
                ->assertOk()
                ->callAction('testConnection')
                ->assertNotified('Connection OK')
                ->callAction('checkEmail')
                ->assertNotified('Email check finished');
        } finally {
            setPermissionsTeamId(null);
        }
    }

    public function test_tenant_admin_can_run_check_email_from_list_table(): void
    {
        [$tenant, $admin, $account] = $this->tenantAdminWithGraphAccount();

        $poller = Mockery::mock(MailAccountPollService::class);
        $poller->shouldReceive('poll')
            ->once()
            ->andReturn(new MailAccountPollResult(fetched: 1, ingested: 1));
        $this->app->instance(MailAccountPollService::class, $poller);

        $this->actingAs($admin);
        Filament::setCurrentPanel('agent');
        Filament::setTenant($tenant, isQuiet: true);
        setPermissionsTeamId($tenant->id);

        try {
            Livewire::test(ListMailAccounts::class, [
                'tenant' => $tenant,
            ])
                ->assertOk()
                ->assertCanSeeTableRecords([$account])
                ->callAction(TestAction::make('checkEmail')->table($account))
                ->assertNotified('Email check finished');
        } finally {
            setPermissionsTeamId(null);
        }
    }

    /**
     * @return array{0: Tenant, 1: User, 2: MailAccount}
     */
    private function tenantAdminWithGraphAccount(): array
    {
        $tenant = Tenant::factory()->create();
        $organization = Organization::factory()->create(['tenant_id' => $tenant->id]);
        $integration = OrganizationIntegration::factory()->create([
            'id' => 'entra-ops',
            'tenant_id' => $tenant->id,
            'organization_id' => $organization->id,
            'type' => OrganizationIntegrationSettings::TYPE_MICROSOFT_ENTRA,
            'config' => [
                'enabled' => true,
                'azure_tenant_id' => 'tenant-guid',
                'application_client_id' => 'client-guid',
            ],
            'credentials' => ['client_secret' => 'secret'],
        ]);

        $account = MailAccount::factory()->microsoftGraph()->create([
            'tenant_id' => $tenant->id,
            'organization_id' => $organization->id,
            'name' => 'Shared mailbox',
            'integration_type' => OrganizationIntegrationSettings::TYPE_MICROSOFT_ENTRA,
            'integration_id' => (string) $integration->getKey(),
        ]);

        $admin = User::factory()->create([
            'tenant_id' => $tenant->id,
            'type' => 'agent',
        ]);

        setPermissionsTeamId($tenant->id);
        $admin->assignRole('Tenant Admin');
        setPermissionsTeamId(null);

        return [$tenant, $admin, $account];
    }
}

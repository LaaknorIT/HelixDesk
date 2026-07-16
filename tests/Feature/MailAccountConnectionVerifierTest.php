<?php

namespace Tests\Feature;

use App\Enums\MailInboundProtocol;
use App\Models\MailAccount;
use App\Models\Organization;
use App\Models\OrganizationIntegration;
use App\Models\Tenant;
use App\Services\Mail\MailAccountConnectionVerifier;
use App\Support\OrganizationIntegrationSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MailAccountConnectionVerifierTest extends TestCase
{
    use RefreshDatabase;

    public function test_microsoft_graph_verify_reports_inbox_counts(): void
    {
        Http::fake([
            'https://login.microsoftonline.com/*' => Http::response([
                'access_token' => 'token',
            ]),
            'https://graph.microsoft.com/v1.0/users/*/mailFolders/inbox*' => Http::response([
                'id' => 'inbox',
                'displayName' => 'Inbox',
                'totalItemCount' => 1,
                'unreadItemCount' => 0,
            ]),
        ]);

        $tenant = Tenant::factory()->create();
        $organization = Organization::factory()->create(['tenant_id' => $tenant->id]);
        $integration = OrganizationIntegration::factory()->create([
            'id' => 'entra-mail',
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
            'email_address' => 'shared@example.com',
            'integration_type' => OrganizationIntegrationSettings::TYPE_MICROSOFT_ENTRA,
            'integration_id' => (string) $integration->getKey(),
            'connection_settings' => [
                'mailbox_upn' => 'shared@example.com',
            ],
        ]);

        $result = app(MailAccountConnectionVerifier::class)->verify($account);

        $this->assertTrue($result->ok);
        $this->assertStringContainsString('1 message(s), 0 unread', $result->message);
        $this->assertStringContainsString('only fetches unread messages', $result->message);
        $this->assertSame(1, $result->details['total_item_count']);
        $this->assertSame(0, $result->details['unread_item_count']);
    }

    public function test_microsoft_graph_verify_surfaces_permission_errors(): void
    {
        Http::fake([
            'https://login.microsoftonline.com/*' => Http::response([
                'access_token' => 'token',
            ]),
            'https://graph.microsoft.com/v1.0/*' => Http::response([
                'error' => [
                    'code' => 'ErrorAccessDenied',
                    'message' => 'Access is denied.',
                ],
            ], 403),
        ]);

        $tenant = Tenant::factory()->create();
        $organization = Organization::factory()->create(['tenant_id' => $tenant->id]);
        $integration = OrganizationIntegration::factory()->create([
            'id' => 'entra-mail-denied',
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
            'integration_type' => OrganizationIntegrationSettings::TYPE_MICROSOFT_ENTRA,
            'integration_id' => (string) $integration->getKey(),
        ]);

        $result = app(MailAccountConnectionVerifier::class)->verify($account);

        $this->assertFalse($result->ok);
        $this->assertStringContainsString('403', $result->message);
    }

    public function test_google_gmail_api_verify_reports_not_implemented(): void
    {
        $account = MailAccount::factory()->create([
            'protocol' => MailInboundProtocol::GoogleGmailApi,
        ]);

        $result = app(MailAccountConnectionVerifier::class)->verify($account);

        $this->assertFalse($result->ok);
        $this->assertStringContainsString('not implemented', $result->message);
    }
}

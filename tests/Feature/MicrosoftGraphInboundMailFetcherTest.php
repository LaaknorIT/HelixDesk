<?php

namespace Tests\Feature;

use App\Models\MailAccount;
use App\Models\Organization;
use App\Models\OrganizationIntegration;
use App\Models\Tenant;
use App\Services\Mail\Fetchers\MicrosoftGraphInboundMailFetcher;
use App\Support\OrganizationIntegrationSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MicrosoftGraphInboundMailFetcherTest extends TestCase
{
    use RefreshDatabase;

    public function test_fetch_skips_message_ids_already_recorded_in_sync_state(): void
    {
        Http::fake([
            'https://login.microsoftonline.com/*' => Http::response([
                'access_token' => 'token',
            ]),
            'https://graph.microsoft.com/v1.0/users/*/mailFolders/inbox/messages*' => Http::response([
                'value' => [
                    ['id' => 'already-seen'],
                    ['id' => 'new-message'],
                ],
            ]),
            'https://graph.microsoft.com/v1.0/users/*/messages/new-message/$value' => Http::response(
                "Message-ID: <new@example.com>\r\nFrom: a@example.com\r\nSubject: Hi\r\n\r\nBody\r\n",
                200,
                ['Content-Type' => 'text/plain'],
            ),
        ]);

        $account = $this->graphMailAccount([
            'sync_state' => [
                'graph_processed_message_ids' => [
                    'already-seen' => true,
                ],
            ],
        ]);

        $messages = app(MicrosoftGraphInboundMailFetcher::class)->fetchNewMessages($account);

        $this->assertCount(1, $messages);
        $this->assertSame('new-message', $messages[0]['uid']);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'already-seen/$value'));
    }

    public function test_acknowledge_records_message_id_even_when_mark_read_fails(): void
    {
        Http::fake([
            'https://login.microsoftonline.com/*' => Http::response([
                'access_token' => 'token',
            ]),
            'https://graph.microsoft.com/v1.0/users/*/messages/msg-1' => Http::response([
                'error' => [
                    'code' => 'ErrorAccessDenied',
                    'message' => 'Access is denied.',
                ],
            ], 403),
        ]);

        $account = $this->graphMailAccount();

        try {
            app(MicrosoftGraphInboundMailFetcher::class)->acknowledgeMessage($account, 'msg-1');
            $this->fail('Expected acknowledge to throw.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Mail.ReadWrite', $e->getMessage());
        }

        $account->refresh();
        $this->assertTrue($account->sync_state['graph_processed_message_ids']['msg-1'] ?? false);

        Http::fake([
            'https://login.microsoftonline.com/*' => Http::response([
                'access_token' => 'token',
            ]),
            'https://graph.microsoft.com/v1.0/users/*/mailFolders/inbox/messages*' => Http::response([
                'value' => [
                    ['id' => 'msg-1'],
                ],
            ]),
        ]);

        $messages = app(MicrosoftGraphInboundMailFetcher::class)->fetchNewMessages($account->fresh());
        $this->assertSame([], $messages);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function graphMailAccount(array $overrides = []): MailAccount
    {
        $tenant = Tenant::factory()->create();
        $organization = Organization::factory()->create(['tenant_id' => $tenant->id]);
        $integration = OrganizationIntegration::factory()->create([
            'id' => 'entra-graph-fetcher',
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

        return MailAccount::factory()->microsoftGraph()->create(array_merge([
            'tenant_id' => $tenant->id,
            'organization_id' => $organization->id,
            'email_address' => 'shared@example.com',
            'integration_type' => OrganizationIntegrationSettings::TYPE_MICROSOFT_ENTRA,
            'integration_id' => (string) $integration->getKey(),
            'connection_settings' => [
                'mailbox_upn' => 'shared@example.com',
            ],
        ], $overrides));
    }
}

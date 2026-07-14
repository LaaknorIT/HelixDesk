<?php

namespace Database\Factories;

use App\Enums\MailInboundProtocol;
use App\Enums\MailOutboundProtocol;
use App\Models\MailAccount;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MailAccount>
 */
class MailAccountFactory extends Factory
{
    protected $model = MailAccount::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => fake()->words(2, true),
            'email_address' => fake()->unique()->safeEmail(),
            'protocol' => MailInboundProtocol::Imap,
            'outbound_protocol' => MailOutboundProtocol::Smtp,
            'organization_id' => null,
            'integration_type' => null,
            'integration_id' => null,
            'connection_settings' => [
                'host' => 'imap.example.com',
                'port' => 993,
                'encryption' => 'ssl',
                'username' => fake()->userName(),
                'password' => 'secret',
                'smtp_host' => 'smtp.example.com',
                'smtp_port' => 587,
                'smtp_encryption' => 'tls',
            ],
            'is_enabled' => true,
            'last_polled_at' => null,
            'last_poll_error' => null,
            'sync_state' => null,
        ];
    }

    public function microsoftGraph(): static
    {
        return $this->state(fn (array $attributes) => [
            'protocol' => MailInboundProtocol::MicrosoftGraph,
            'outbound_protocol' => MailOutboundProtocol::MicrosoftGraph,
            'connection_settings' => [
                'mailbox_upn' => $attributes['email_address'] ?? fake()->safeEmail(),
            ],
        ]);
    }
}

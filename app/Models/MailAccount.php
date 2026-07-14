<?php

namespace App\Models;

use App\Enums\MailInboundProtocol;
use App\Enums\MailOutboundProtocol;
use Database\Factories\MailAccountFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MailAccount extends TenantModel
{
    /** @use HasFactory<MailAccountFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'name',
        'email_address',
        'protocol',
        'outbound_protocol',
        'organization_id',
        'integration_type',
        'integration_id',
        'connection_settings',
        'is_enabled',
        'last_polled_at',
        'last_poll_error',
        'sync_state',
    ];

    protected function casts(): array
    {
        return [
            'protocol' => MailInboundProtocol::class,
            'outbound_protocol' => MailOutboundProtocol::class,
            'connection_settings' => 'encrypted:array',
            'is_enabled' => 'boolean',
            'last_polled_at' => 'datetime',
            'sync_state' => 'array',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function queues(): BelongsToMany
    {
        return $this->belongsToMany(Queue::class, 'queue_mail_account');
    }

    public function incidents(): HasMany
    {
        return $this->hasMany(Incident::class);
    }

    public function processedInboundEmails(): HasMany
    {
        return $this->hasMany(ProcessedInboundEmail::class);
    }

    public function usesMicrosoftGraph(): bool
    {
        return $this->protocol === MailInboundProtocol::MicrosoftGraph
            || $this->outbound_protocol === MailOutboundProtocol::MicrosoftGraph;
    }

    public function imapUsername(): string
    {
        $settings = is_array($this->connection_settings) ? $this->connection_settings : [];
        $username = trim((string) ($settings['username'] ?? ''));

        if ($username !== '') {
            return $username;
        }

        return trim($this->email_address);
    }

    public function imapPassword(): string
    {
        $settings = is_array($this->connection_settings) ? $this->connection_settings : [];

        return (string) ($settings['password'] ?? '');
    }
}

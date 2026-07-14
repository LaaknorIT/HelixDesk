<?php

namespace Tests\Feature;

use App\Enums\IncidentSource;
use App\Models\Incident;
use App\Models\IncidentMessage;
use App\Models\MailAccount;
use App\Models\ProcessedInboundEmail;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Mail\InboundMailIngestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class InboundMailIngestionTest extends TestCase
{
    use RefreshDatabase;

    public function test_inbound_email_creates_incident_and_end_user(): void
    {
        Storage::fake('local');

        $tenant = Tenant::factory()->create();
        $queue = $tenant->generalQueue();
        $this->assertNotNull($queue);

        $account = MailAccount::factory()->create([
            'tenant_id' => $tenant->id,
            'email_address' => 'support@example.com',
        ]);
        $queue->mailAccounts()->attach($account->id);

        $raw = $this->sampleMime(
            messageId: '<new-ticket@example.com>',
            from: 'customer@example.com',
            subject: 'Printer broken',
            body: 'It will not print.',
        );

        app(InboundMailIngestionService::class)->ingest($account, $raw);

        $incident = Incident::query()->where('tenant_id', $tenant->id)->first();
        $this->assertNotNull($incident);
        $this->assertSame(IncidentSource::Email, $incident->source);
        $this->assertSame($account->id, $incident->mail_account_id);
        $this->assertSame('<new-ticket@example.com>', $incident->inbound_message_id);
        $this->assertSame($queue->id, $incident->queue_id);

        $requester = User::query()->where('email', 'customer@example.com')->first();
        $this->assertNotNull($requester);
        $this->assertSame('end_user', $requester->type);

        $this->assertDatabaseHas('incident_messages', [
            'incident_id' => $incident->id,
            'external_message_id' => '<new-ticket@example.com>',
            'from_address' => 'customer@example.com',
        ]);

        $this->assertDatabaseHas('processed_inbound_emails', [
            'mail_account_id' => $account->id,
            'message_id' => '<new-ticket@example.com>',
        ]);
    }

    public function test_inbound_reply_threads_on_existing_incident(): void
    {
        $tenant = Tenant::factory()->create();
        $queue = $tenant->generalQueue();
        $account = MailAccount::factory()->create(['tenant_id' => $tenant->id]);
        $queue?->mailAccounts()->attach($account->id);

        $requester = User::factory()->create([
            'tenant_id' => $tenant->id,
            'type' => 'end_user',
            'email' => 'customer@example.com',
        ]);

        $incident = Incident::query()->create([
            'tenant_id' => $tenant->id,
            'queue_id' => $queue?->id,
            'reported_by_user_id' => $requester->id,
            'title' => 'Printer broken',
            'priority' => 'normal',
            'status' => 'New',
            'source' => IncidentSource::Email,
            'mail_account_id' => $account->id,
            'inbound_message_id' => '<original@example.com>',
            'opened_at' => now(),
        ]);

        IncidentMessage::query()->create([
            'tenant_id' => $tenant->id,
            'incident_id' => $incident->id,
            'author_user_id' => $requester->id,
            'body' => 'Initial message',
            'external_message_id' => '<original@example.com>',
            'from_address' => 'customer@example.com',
        ]);

        $raw = $this->sampleMime(
            messageId: '<reply@example.com>',
            from: 'customer@example.com',
            subject: 'Re: Printer broken',
            body: 'Still broken.',
            inReplyTo: '<original@example.com>',
        );

        app(InboundMailIngestionService::class)->ingest($account, $raw);

        $this->assertSame(1, Incident::query()->where('tenant_id', $tenant->id)->count());
        $this->assertSame(2, IncidentMessage::query()->where('incident_id', $incident->id)->count());
    }

    public function test_duplicate_message_id_is_ignored(): void
    {
        $tenant = Tenant::factory()->create();
        $account = MailAccount::factory()->create(['tenant_id' => $tenant->id]);

        ProcessedInboundEmail::query()->create([
            'tenant_id' => $tenant->id,
            'mail_account_id' => $account->id,
            'message_id' => '<dup@example.com>',
            'processed_at' => now(),
        ]);

        $raw = $this->sampleMime(
            messageId: '<dup@example.com>',
            from: 'customer@example.com',
            subject: 'Dup',
            body: 'Dup body',
        );

        app(InboundMailIngestionService::class)->ingest($account, $raw);

        $this->assertSame(0, Incident::query()->count());
    }

    public function test_inbound_email_with_attachment_is_stored(): void
    {
        Storage::fake('local');

        $tenant = Tenant::factory()->create();
        $account = MailAccount::factory()->create(['tenant_id' => $tenant->id]);
        $tenant->generalQueue()?->mailAccounts()->attach($account->id);

        $boundary = 'boundary42';
        $raw = implode("\r\n", [
            'Message-ID: <attach@example.com>',
            'From: customer@example.com',
            'To: support@example.com',
            'Subject: With file',
            'MIME-Version: 1.0',
            'Content-Type: multipart/mixed; boundary="'.$boundary.'"',
            '',
            '--'.$boundary,
            'Content-Type: text/plain; charset=utf-8',
            '',
            'See attached.',
            '--'.$boundary,
            'Content-Type: text/plain; name="log.txt"',
            'Content-Disposition: attachment; filename="log.txt"',
            '',
            'error line',
            '--'.$boundary.'--',
            '',
        ]);

        app(InboundMailIngestionService::class)->ingest($account, $raw);

        $this->assertDatabaseHas('incident_message_attachments', [
            'tenant_id' => $tenant->id,
            'filename' => 'log.txt',
        ]);
    }

    private function sampleMime(
        string $messageId,
        string $from,
        string $subject,
        string $body,
        ?string $inReplyTo = null,
    ): string {
        $lines = [
            'Message-ID: '.$messageId,
            'From: '.$from,
            'To: support@example.com',
            'Subject: '.$subject,
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=utf-8',
        ];

        if ($inReplyTo !== null) {
            $lines[] = 'In-Reply-To: '.$inReplyTo;
            $lines[] = 'References: '.$inReplyTo;
        }

        $lines[] = '';
        $lines[] = $body;
        $lines[] = '';

        return implode("\r\n", $lines);
    }
}

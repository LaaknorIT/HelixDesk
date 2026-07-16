<?php

namespace Tests\Feature;

use App\Enums\InboundMailIngestOutcome;
use App\Jobs\PollMailAccountJob;
use App\Models\MailAccount;
use App\Models\ProcessedInboundEmail;
use App\Models\Queue;
use App\Models\Tenant;
use App\Models\WorkItem;
use App\Services\Mail\Contracts\InboundMailFetcher;
use App\Services\Mail\Fetchers\InboundMailFetcherFactory;
use App\Services\Mail\InboundMailIngestionService;
use App\Services\Mail\MailAccountPollService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class MailAccountPollServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_poll_ingests_messages_and_updates_account_state(): void
    {
        $tenant = Tenant::factory()->create();
        $account = MailAccount::factory()->create([
            'tenant_id' => $tenant->id,
            'is_enabled' => true,
            'last_poll_error' => 'old error',
        ]);
        $tenant->generalQueue()?->mailAccounts()->attach($account->id);

        $raw = $this->sampleMime('<poll@example.com>', 'customer@example.com', 'Help', 'Body');

        $fetcher = Mockery::mock(InboundMailFetcher::class);
        $fetcher->shouldReceive('fetchNewMessages')->once()->andReturn([
            ['raw' => $raw, 'uid' => 'uid-1'],
        ]);
        $fetcher->shouldReceive('acknowledgeMessage')->once()->with(Mockery::type(MailAccount::class), 'uid-1');

        $factory = Mockery::mock(InboundMailFetcherFactory::class);
        $factory->shouldReceive('forAccount')->once()->andReturn($fetcher);
        $this->app->instance(InboundMailFetcherFactory::class, $factory);

        $result = app(MailAccountPollService::class)->poll($account->fresh());

        $this->assertTrue($result->succeeded());
        $this->assertSame(1, $result->fetched);
        $this->assertSame(1, $result->ingested);
        $this->assertSame(0, $result->skipped);

        $account->refresh();
        $this->assertNotNull($account->last_polled_at);
        $this->assertNull($account->last_poll_error);
        $this->assertSame(1, WorkItem::query()->where('tenant_id', $tenant->id)->count());
    }

    public function test_poll_skips_acknowledge_when_no_queue_available(): void
    {
        $tenant = Tenant::factory()->create();
        Queue::query()->where('tenant_id', $tenant->id)->delete();

        $account = MailAccount::factory()->create([
            'tenant_id' => $tenant->id,
            'is_enabled' => true,
        ]);

        $raw = $this->sampleMime('<noqueue@example.com>', 'customer@example.com', 'Help', 'Body');

        $fetcher = Mockery::mock(InboundMailFetcher::class);
        $fetcher->shouldReceive('fetchNewMessages')->once()->andReturn([
            ['raw' => $raw, 'uid' => 'uid-2'],
        ]);
        $fetcher->shouldReceive('acknowledgeMessage')->never();

        $factory = Mockery::mock(InboundMailFetcherFactory::class);
        $factory->shouldReceive('forAccount')->once()->andReturn($fetcher);
        $this->app->instance(InboundMailFetcherFactory::class, $factory);

        $result = app(MailAccountPollService::class)->poll($account->fresh());

        $this->assertTrue($result->succeeded());
        $this->assertSame(1, $result->fetched);
        $this->assertSame(0, $result->ingested);
        $this->assertSame(1, $result->skipped);
        $this->assertSame(0, ProcessedInboundEmail::query()->count());
        $this->assertSame(0, WorkItem::query()->count());
    }

    public function test_poll_records_error_and_job_rethrows(): void
    {
        $tenant = Tenant::factory()->create();
        $account = MailAccount::factory()->create([
            'tenant_id' => $tenant->id,
            'is_enabled' => true,
        ]);

        $fetcher = Mockery::mock(InboundMailFetcher::class);
        $fetcher->shouldReceive('fetchNewMessages')->twice()->andThrow(new \RuntimeException('Graph denied'));

        $factory = Mockery::mock(InboundMailFetcherFactory::class);
        $factory->shouldReceive('forAccount')->twice()->andReturn($fetcher);
        $this->app->instance(InboundMailFetcherFactory::class, $factory);

        $result = app(MailAccountPollService::class)->poll($account->fresh());

        $this->assertFalse($result->succeeded());
        $this->assertSame('Graph denied', $result->error);
        $this->assertSame('Graph denied', $account->fresh()->last_poll_error);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Graph denied');

        (new PollMailAccountJob($account->id))->handle(app(MailAccountPollService::class));
    }

    public function test_ingest_returns_duplicate_outcome(): void
    {
        $tenant = Tenant::factory()->create();
        $account = MailAccount::factory()->create(['tenant_id' => $tenant->id]);

        ProcessedInboundEmail::query()->create([
            'tenant_id' => $tenant->id,
            'mail_account_id' => $account->id,
            'message_id' => '<dup2@example.com>',
            'processed_at' => now(),
        ]);

        $outcome = app(InboundMailIngestionService::class)->ingest(
            $account,
            $this->sampleMime('<dup2@example.com>', 'customer@example.com', 'Dup', 'Body'),
        );

        $this->assertSame(InboundMailIngestOutcome::Duplicate, $outcome);
    }

    private function sampleMime(string $messageId, string $from, string $subject, string $body): string
    {
        return implode("\r\n", [
            'Message-ID: '.$messageId,
            'From: '.$from,
            'To: support@example.com',
            'Subject: '.$subject,
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=utf-8',
            '',
            $body,
            '',
        ]);
    }
}

<?php

namespace Tests\Feature;

use App\Jobs\PollMailAccountJob;
use App\Jobs\PollMailAccountsJob;
use App\Models\MailAccount;
use App\Models\Tenant;
use App\Services\Mail\InboundMailFetcherFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class PollMailAccountsJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_coordinator_dispatches_poll_jobs_for_due_accounts(): void
    {
        Bus::fake([PollMailAccountJob::class]);

        $tenant = Tenant::factory()->create();

        $dueAccount = MailAccount::factory()->create([
            'tenant_id' => $tenant->id,
            'is_enabled' => true,
            'last_polled_at' => now()->subHour(),
        ]);

        MailAccount::factory()->create([
            'tenant_id' => $tenant->id,
            'is_enabled' => true,
            'last_polled_at' => now(),
        ]);

        (new PollMailAccountsJob)->handle();

        Bus::assertDispatched(PollMailAccountJob::class, fn (PollMailAccountJob $job): bool => $job->mailAccountId === $dueAccount->id);
        Bus::assertDispatchedTimes(PollMailAccountJob::class, 1);
    }

    public function test_mail_fetcher_factory_autoloads_from_fetchers_namespace(): void
    {
        $this->assertTrue(class_exists(\App\Services\Mail\Fetchers\InboundMailFetcherFactory::class));
        $this->assertFalse(class_exists(InboundMailFetcherFactory::class));
    }
}

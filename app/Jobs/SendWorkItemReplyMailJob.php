<?php

namespace App\Jobs;

use App\Models\WorkItemMessage;
use App\Services\Mail\MailInboundLogger;
use App\Services\Mail\OutboundWorkItemMailService;
use App\Services\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class SendWorkItemReplyMailJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $workItemMessageId) {}

    public function handle(
        TenantContext $tenantContext,
        OutboundWorkItemMailService $mailService,
        MailInboundLogger $mailLogger,
    ): void {
        /** @var WorkItemMessage|null $message */
        $message = WorkItemMessage::query()->withoutGlobalScopes()->find($this->workItemMessageId);

        if ($message === null) {
            return;
        }

        $previousTenantId = $tenantContext->tenantId();
        $tenantContext->setTenantId((int) $message->tenant_id);
        setPermissionsTeamId((int) $message->tenant_id);

        $mailLogger->logger()->info('Outbound work item reply mail job started.', [
            'work_item_id' => $message->work_item_id,
            'work_item_message_id' => $message->getKey(),
            'tenant_id' => $message->tenant_id,
        ]);

        try {
            $mailService->sendAgentReply($message);
        } catch (Throwable $e) {
            // Conversation message is already saved; never fail the agent UI / request lifecycle.
            // OutboundWorkItemMailService already writes a detailed mail-channel error; keep a
            // job-level breadcrumb so failures are visible even if the service logging path changes.
            report($e);

            $account = $message->workItem?->mailAccount
                ?? $message->workItem?->queue?->defaultMailAccount;

            if ($account !== null) {
                $mailLogger->exception($account, 'Outbound work item reply mail job failed.', $e, [
                    'work_item_id' => $message->work_item_id,
                    'work_item_message_id' => $message->getKey(),
                ]);
            } else {
                $mailLogger->logger()->error('Outbound work item reply mail job failed.', [
                    'work_item_id' => $message->work_item_id,
                    'work_item_message_id' => $message->getKey(),
                    'exception' => $e::class,
                    'error' => $e->getMessage(),
                ]);
            }
        } finally {
            $tenantContext->setTenantId($previousTenantId);
            setPermissionsTeamId($previousTenantId);
        }
    }
}

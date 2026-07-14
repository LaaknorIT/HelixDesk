<?php

namespace App\Jobs;

use App\Models\WorkItemMessage;
use App\Services\Mail\OutboundWorkItemMailService;
use App\Services\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendWorkItemReplyMailJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $workItemMessageId) {}

    public function handle(TenantContext $tenantContext, OutboundWorkItemMailService $mailService): void
    {
        /** @var WorkItemMessage|null $message */
        $message = WorkItemMessage::query()->withoutGlobalScopes()->find($this->workItemMessageId);

        if ($message === null) {
            return;
        }

        $tenantContext->setTenantId((int) $message->tenant_id);
        setPermissionsTeamId((int) $message->tenant_id);

        try {
            $mailService->sendAgentReply($message);
        } finally {
            $tenantContext->setTenantId(null);
            setPermissionsTeamId(null);
        }
    }
}

<?php

namespace App\Jobs;

use App\Models\IncidentMessage;
use App\Services\Mail\OutboundIncidentMailService;
use App\Services\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendIncidentReplyMailJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $incidentMessageId) {}

    public function handle(TenantContext $tenantContext, OutboundIncidentMailService $mailService): void
    {
        /** @var IncidentMessage|null $message */
        $message = IncidentMessage::query()->withoutGlobalScopes()->find($this->incidentMessageId);

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

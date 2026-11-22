<?php

namespace App\Services;

use App\Enums\AutomationActionType;
use App\Enums\AutomationTrigger;
use App\Enums\WorkItemSource;
use App\Enums\WorkItemType;
use App\Jobs\SendWorkItemReplyMailJob;
use App\Models\AutomationRule;
use App\Models\AutomationRuleAction;
use App\Models\User;
use App\Models\WorkItem;
use App\Models\WorkItemMessage;
use App\Models\WorkItemStatus;
use App\Services\Mail\OutboundWorkItemMailService;
use App\Services\Notifications\WorkItemNotifier;
use Illuminate\Support\Facades\DB;

class WorkItemAutomationService
{
    public function __construct(
        private readonly SlaTimerService $slaTimerService,
        private readonly OutboundWorkItemMailService $outboundMail,
        private readonly WorkItemNotifier $notifier,
    ) {}

    /**
     * Run customer-reply automations after a non-internal customer message is saved.
     *
     * Agents are notified of the reply, unless an automation moved it to a new ticket (which notifies as a new ticket).
     *
     * @return WorkItem The work item that should own the reply going forward (may be a newly created ticket).
     */
    public function handleCustomerReplied(WorkItem $workItem, WorkItemMessage $message): WorkItem
    {
        $originalWorkItemId = (int) $workItem->getKey();

        $context = [
            'message' => $message,
            'work_item' => $workItem,
        ];

        $rules = AutomationRule::query()
            ->where('tenant_id', $workItem->tenant_id)
            ->where('trigger', AutomationTrigger::CustomerReplied->value)
            ->where('is_enabled', true)
            ->orderBy('sort_order')
            ->with('actions')
            ->get();

        foreach ($rules as $rule) {
            if (! $this->conditionsMatch($rule, $workItem)) {
                continue;
            }

            foreach ($rule->actions as $action) {
                $result = $this->runAction($action, $workItem, $context);
                if ($result instanceof WorkItem) {
                    $workItem = $result;
                    $context['work_item'] = $workItem;
                }
            }
        }

        $workItem = $workItem->fresh() ?? $workItem;

        if ((int) $workItem->getKey() === $originalWorkItemId) {
            $this->notifier->customerReplied($workItem, $message);
        }

        return $workItem;
    }

    public function handleAgentReplied(WorkItem $workItem, WorkItemMessage $message): void
    {
        $this->slaTimerService->markFirstResponseIfNeeded($workItem);

        $rules = AutomationRule::query()
            ->where('tenant_id', $workItem->tenant_id)
            ->where('trigger', AutomationTrigger::AgentReplied->value)
            ->where('is_enabled', true)
            ->orderBy('sort_order')
            ->with('actions')
            ->get();

        $context = [
            'message' => $message,
            'work_item' => $workItem,
        ];

        foreach ($rules as $rule) {
            if (! $this->conditionsMatch($rule, $workItem)) {
                continue;
            }

            foreach ($rule->actions as $action) {
                $this->runAction($action, $workItem, $context);
            }
        }
    }

    private function conditionsMatch(AutomationRule $rule, WorkItem $workItem): bool
    {
        $conditions = $rule->conditions_json ?? [];

        if (isset($conditions['statuses']) && is_array($conditions['statuses']) && $conditions['statuses'] !== []) {
            if (! in_array($workItem->status, $conditions['statuses'], true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array{message?: WorkItemMessage, work_item: WorkItem}  $context
     */
    private function runAction(AutomationRuleAction $action, WorkItem $workItem, array $context): ?WorkItem
    {
        $config = $action->action_config_json ?? [];

        return match ($action->action_type) {
            AutomationActionType::SetStatus->value => $this->actionSetStatus($workItem, $config),
            AutomationActionType::CreateWorkItem->value => $this->actionCreateWorkItem($workItem, $config, $context),
            AutomationActionType::SendCustomerEmail->value => $this->actionSendCustomerEmail($workItem, $config),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function actionSetStatus(WorkItem $workItem, array $config): ?WorkItem
    {
        $status = isset($config['status']) ? (string) $config['status'] : '';
        if ($status === '' || $workItem->status === $status) {
            return null;
        }

        $from = $workItem->status;
        $workItem->status = $status;
        $this->applyStatusTimestamps($workItem, $status);
        $workItem->save();

        $this->slaTimerService->syncPauseState($workItem, $from, $status);

        return $workItem;
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array{message?: WorkItemMessage, work_item: WorkItem}  $context
     */
    private function actionCreateWorkItem(WorkItem $original, array $config, array $context): WorkItem
    {
        return DB::transaction(function () use ($original, $config, $context): WorkItem {
            $newWorkItem = WorkItem::query()->create([
                'tenant_id' => $original->tenant_id,
                'organization_id' => $original->organization_id,
                'reported_by_user_id' => $original->reported_by_user_id,
                'assigned_agent_id' => null,
                'queue_id' => $original->queue_id,
                'type' => $original->type ?? WorkItemType::Incident,
                'title' => $original->title,
                'description' => $original->description,
                'priority' => $original->priority,
                'category_id' => $original->category_id,
                'status' => 'New',
                'source' => $original->source ?? WorkItemSource::Email,
                'mail_account_id' => $original->mail_account_id,
                'opened_at' => now(),
            ]);

            app(SlaPolicyResolver::class)->attachBestPolicy($newWorkItem);
            $this->slaTimerService->startTimers($newWorkItem);

            /** @var WorkItemMessage|null $message */
            $message = $context['message'] ?? null;
            $moveReply = (bool) ($config['move_reply_message'] ?? true);

            if ($message !== null && $moveReply) {
                $message->work_item_id = $newWorkItem->getKey();
                $message->save();
            }

            return $newWorkItem;
        });
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function actionSendCustomerEmail(WorkItem $workItem, array $config): null
    {
        $body = trim((string) ($config['body'] ?? ''));
        if ($body === '') {
            return null;
        }

        $subject = trim((string) ($config['subject'] ?? ''));
        if ($subject !== '') {
            $body = $subject."\n\n".$body;
        }

        $agent = User::query()
            ->where('tenant_id', $workItem->tenant_id)
            ->where('type', 'agent')
            ->orderBy('id')
            ->first();

        if ($agent === null) {
            $this->outboundMail->sendCustomerNotification($workItem, $body);

            return null;
        }

        $message = WorkItemMessage::query()->create([
            'tenant_id' => $workItem->tenant_id,
            'work_item_id' => $workItem->getKey(),
            'author_user_id' => $agent->getKey(),
            'parent_message_id' => null,
            'is_internal' => false,
            'body' => $body,
        ]);

        SendWorkItemReplyMailJob::dispatch($message->getKey());

        return null;
    }

    private function applyStatusTimestamps(WorkItem $workItem, string $status): void
    {
        $statusMeta = WorkItemStatus::query()
            ->where('tenant_id', $workItem->tenant_id)
            ->where('name', $status)
            ->first();

        $now = now();

        if ($statusMeta?->sets_resolved_at && $workItem->resolved_at === null) {
            $workItem->resolved_at = $now;
        }

        if ($statusMeta?->sets_closed_at && $workItem->closed_at === null) {
            $workItem->closed_at = $now;
        }

        if ($status === 'Closed' && $workItem->closed_at === null) {
            $workItem->closed_at = $now;
        }

        if (in_array($status, ['Closed (pending)', 'Closed'], true) && $workItem->resolved_at === null) {
            $workItem->resolved_at = $now;
        }
    }
}

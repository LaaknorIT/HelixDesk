# Agent notifications

Design for the Release 1 roadmap item **Notifications** ([`roadmap.md`](roadmap.md)). It covers notifications to **agents** about tickets. Mail to customers is unchanged and stays with the ticket email channel ([`email-handling.md`](email-handling.md)).

## Events

| Event | Fires when | Agents notified |
|-------|------------|-----------------|
| **New ticket** | A work item is created from any source (portal, agent panel, API, email, monitoring, automation). | Agents who can read the ticket's queue. |
| **Assigned** | `assigned_agent_id` is set or changed. | The new assignee, unless they assigned it to themselves. |
| **Customer reply** | The requester adds a public message by email or in the portal. | The assignee, or the queue's agents when the ticket is unassigned. |
| **SLA at risk** | First response or resolution is due within the warning window (default 30 minutes) and the SLA is not paused. Sent once per target. | The assignee, or the queue's agents when the ticket is unassigned. |

The agent who caused an event is never notified about it.

## Channels

- **Email** to the agent, sent with the system mailer (`MAIL_*` settings).
- **In-app**: Filament database notifications, shown under the bell icon in the agent panel.
- **Microsoft Teams** and **Slack**: a message posted to a channel through an incoming webhook configured **per queue**. These go to a channel, not to a person.

Each agent chooses per event whether to get email and in-app notifications on the **Notification settings** page (user menu in the agent panel). Everything is on until changed. Each queue chooses which events are posted to its Teams and Slack channels; by default new tickets, customer replies and SLA warnings, but not assignments.

## How it works

- `WorkItemObserver` reports new tickets and assignment changes, so every creation path is covered without touching each one. `WorkItemAutomationService::handleCustomerReplied()` reports customer replies, which is already called by both the email and portal paths. When a reply to a closed ticket opens a new ticket, only the new-ticket notification is sent.
- `SlaTimerService::notifyAtRiskWorkItems()` runs with the existing five-minute SLA job. The timestamps `response_warning_notified_at` and `resolution_warning_notified_at` on `work_items` make sure each warning is sent once.
- `WorkItemNotifier` decides recipients and channels. Agent notifications (`WorkItemActivityNotification`) and chat posts (`SendWorkItemChatMessageJob`) are queued and dispatched after the database transaction commits, so a slow mail server or webhook never blocks saving a ticket.
- Queue agents are the tenant staff users for whom `HelixdeskWorkItemAuthorization::hasWorkItemPermission($user, 'read', $queueId)` is true, evaluated in the ticket's tenant.
- Webhook URLs are secrets and are stored encrypted. Teams uses the **Workflows** "post to a channel when a webhook request is received" webhook with an Adaptive Card; the retired Office 365 connectors are not supported.

## Settings

| Setting | Where | Default |
|---------|-------|---------|
| Agent email / in-app per event | Agent panel → user menu → Notification settings | All on |
| Teams webhook, Slack webhook, events to post | Administration → Queues → edit queue | No webhooks |
| SLA warning window | `HELIXDESK_SLA_WARNING_MINUTES` | 30 minutes |

## Out of scope

Personal Teams or Slack messages, digest emails, and notifications for problems and changes. These can build on the same notifier later.

# Agent productivity

Design for the Release 1 roadmap item **Agent productivity** ([`roadmap.md`](roadmap.md)): canned responses, merging and linking tickets, watchers and CC, and a collision warning. Everything lives on the agent ticket page.

## Canned responses

Saved replies that agents insert into the reply box.

- **Model:** `canned_responses` (tenant, title, body, optional queue). A response without a queue is available on every ticket; one with a queue only on tickets in that queue.
- **Managed** by tenant administrators under **Administration → Canned responses**.
- **Used** from a **Canned response** picker above the message field (shown when the ticket has any). Picking one appends its text to the message, so the agent can still edit it before sending.
- **Placeholders** are filled in when inserted: `{{requester.name}}`, `{{requester.first_name}}`, `{{ticket.id}}`, `{{ticket.title}}`, `{{agent.name}}`, `{{queue.name}}`. Unknown placeholders are left as typed.

## Linking tickets

A plain "related" link between two tickets in the same tenant, shown both ways under **Relations → Linked tickets**. Agents add and remove links from the ticket's **More** menu; that needs Update on the current ticket's queue and read access to the other ticket.

- **Model:** `work_item_links` (`work_item_id`, `linked_work_item_id`), stored once per pair.

## Merging tickets

Merging folds a duplicate (the source) into another ticket (the target), typically when a customer writes twice about the same thing.

- All messages and their attachments move to the target, keeping their timestamps.
- An internal note on both tickets records the merge and who did it.
- The source gets `merged_into_work_item_id`, is closed, and is linked to the target.
- Requesters, watchers and CC addresses of the source are added to the target's watchers and CC list (the source requester goes on the CC list when they differ from the target requester).
- Email replies that later arrive for the source are filed on the target: mail threading follows `merged_into_work_item_id`.
- Needs Update on both tickets' queues. A ticket cannot be merged into itself, into a ticket that is already merged, or across tenants. Merging cannot be undone, so the action asks for confirmation.

## Watchers and CC

- **Watchers** are agents who follow a ticket without being assigned. They get the same customer reply and SLA warning notifications as the assignee, using their own notification settings ([`notifications.md`](notifications.md)), as long as they can still read the queue. Any agent who can read the ticket can **Watch** it; agents with Update choose watchers for others under **More → Watchers**.
- **CC addresses** are extra customer email addresses (`work_items.cc_addresses`). Every public agent reply is sent to the requester with these addresses in Cc, over SMTP and Microsoft Graph. Agents with Update edit the list under **More → CC recipients**; addresses are lower-cased and the requester's own address is dropped.

## Collision warning

Two agents should not answer the same ticket at the same time without knowing it.

- **Presence:** while an agent has a ticket open, the page sends a heartbeat every 15 seconds to the cache (`work-item-viewers:{id}`, entries expire after 45 seconds). A banner at the top of the ticket lists the other agents who have it open.
- **Stale reply guard:** the page remembers the newest message when the agent opened the ticket (or last sent). If someone else has added a public message since, sending a reply stops once and shows who replied; sending again goes ahead. The message the agent typed is kept.
- No locking: the warning informs, it does not block.

## Out of scope

Personal canned responses, rich-text replies, splitting tickets, and live typing indicators. They can build on this later.

# Email handling

HelixDesk uses email as a first-class ticket channel: inbound mail creates or updates work items, and agent replies from the queue send outbound mail. Multiple mail accounts can route into the same queue; replies use the address the customer originally wrote to, or the queue’s default outbound address when the ticket was not created by email.

## Goals

- **Inbound**: scheduled polling via IMAP, POP3, Microsoft Graph (Microsoft 365), or **Gmail API** (Google Workspace — [planned](google-workspace-mail.md)).
- **Outbound**: SMTP, Microsoft Graph, or **Gmail API**, using the work item’s receiving mailbox or the queue default.
- **Multi-account queues**: many `mail_accounts` linked to one `queue` via `queue_mail_account`.
- **Threading**: RFC `Message-ID`, `In-Reply-To`, and `References` for reply correlation.
- **Requesters**: match `From` to an existing `User` by email, or auto-create an `end_user`.
- **Attachments**: stored on disk (local/S3-compatible) and linked to `work_item_messages`.
- **Internal notes**: never sent by email.

## Data model

### `mail_accounts`

Tenant-scoped mailbox configuration.

| Field | Purpose |
|-------|---------|
| `name` | Admin label |
| `email_address` | Primary mailbox address (also default `From` for outbound) |
| `protocol` | `imap`, `pop3`, `microsoft_graph`, or `google_gmail_api` (inbound) |
| `outbound_protocol` | `smtp`, `microsoft_graph`, or `google_gmail_api` |
| `organization_id` | Optional; organization that owns the integration used for API mail |
| `integration_type` | Optional; organization integration type (e.g. `microsoft_entra`, `google_workspace`) |
| `integration_id` | Optional; `settings[].id` from `organizational_settings` |
| `connection_settings` | Encrypted JSON (hosts, ports, credentials, `mailbox_upn` / `mailbox_address`) |
| `is_enabled` | Polling and outbound enabled |
| `last_polled_at`, `last_poll_error` | Operational state |
| `sync_state` | JSON cursor (Graph delta link, POP3 UIDL map, IMAP UID) |

**Organization integration reference**: API mail accounts (`microsoft_graph`, `google_gmail_api`, …) reference an organization integration via `organization_id` + `integration_type` + `integration_id`. The `integration_type` value matches entries in `organizations.organizational_settings` (e.g. `microsoft_entra`, `google_workspace`). Different customers can use different integrations per organization.

**Microsoft Graph:** requires `Mail.Read` and `Mail.Send` application permissions (admin consent).

**Google Workspace (planned):** see [`google-workspace-mail.md`](google-workspace-mail.md).

### `queue_mail_account`

Many-to-many between `queues` and `mail_accounts`.

### `queues.default_mail_account_id`

Optional FK to `mail_accounts`. Used for outbound mail when the incident was **not** created from email (portal, API, monitoring). Must be one of the accounts linked to the queue.

### Incident email fields

| Field | Purpose |
|-------|---------|
| `source` | `portal`, `email`, `api`, `monitoring`, … |
| `mail_account_id` | Mailbox that received the original email (reply-from-same-address) |
| `inbound_message_id` | First RFC `Message-ID` for the thread |

### `work_item_messages` email fields

| Field | Purpose |
|-------|---------|
| `external_message_id` | RFC `Message-ID` |
| `in_reply_to`, `email_references` | Threading headers |
| `from_address`, `to_addresses` | Envelope snapshot |
| `mail_account_id` | Account used to send (outbound) or received on (inbound) |
| `author_user_id` | Nullable for system-ingested inbound messages |
| `email_sent_at` | When outbound delivery completed |

### `processed_inbound_emails`

Dedup table: unique `(mail_account_id, message_id)`.

### `work_item_message_attachments`

`filename`, `mime_type`, `size`, `storage_path` per message attachment.

## Inbound flow

1. Scheduler dispatches `PollMailAccountsJob` every few minutes when any account is due.
2. `PollMailAccountJob` runs per account inside tenant context.
3. Transport fetches new messages (IMAP unseen / POP3 UIDL / Graph `messages` with delta).
4. `InboundMailIngestionService`:
   - Parses MIME (`zbateson/mail-mime-parser`).
   - Skips if `Message-ID` already in `processed_inbound_emails`.
   - Resolves requester (`RequesterResolutionService`: find or create `end_user`).
   - If `In-Reply-To` / `References` match an existing incident message → `addMessage` on that incident.
   - Else → create incident on a queue linked to the mail account (`source=email`, `mail_account_id` set).
   - Stores attachments.
5. Marks message read / deletes (POP3) / records Graph id in `sync_state`.

## Outbound flow

1. Agent posts a non-internal reply via `WorkItemWorkflowService::addMessage`.
2. Service dispatches `SendWorkItemReplyMailJob` (not for internal notes or end-user portal replies unless configured later).
3. `OutboundWorkItemMailService` resolves the sending account:
   - If `incident.mail_account_id` is set → use that account.
   - Else → `queue.default_mail_account_id`.
   - If neither → skip send (log warning).
4. Builds MIME with proper `In-Reply-To` / `References`, agent display name, attachments from the message if any.
5. Sends via SMTP (dynamic Laravel mailer) or Graph `sendMail`.
6. Sets `email_sent_at` and outbound `external_message_id`.

## UI

- **Settings → Mail accounts**: CRUD, protocol-specific fields, Entra integration picker.
- **Settings → Queues**: attach mail accounts; select default outbound account (must be linked).

## Provider notes

### Gmail / Google Workspace (IMAP + SMTP)

Google **does not accept your normal account password** for third-party mail clients. `AUTHENTICATIONFAILED` almost always means an App Password or OAuth is required, not wrong HelixDesk settings.

1. **Enable IMAP** in Gmail: Settings → See all settings → Forwarding and POP/IMAP → Enable IMAP.
2. **Turn on 2-Step Verification** on the Google account.
3. Create an **App Password** at [https://myaccount.google.com/apppasswords](https://myaccount.google.com/apppasswords) (Google Account → Security → App passwords).
4. Use that **16-character app password** in the mail account form (not your login password).
5. Use these connection values (or choose the **Gmail / Google Workspace** preset in the form):

| Setting | Value |
|---------|-------|
| Inbound host | `imap.gmail.com` |
| Inbound port | `993` |
| Inbound encryption | `ssl` |
| SMTP host | `smtp.gmail.com` |
| SMTP port | `587` |
| SMTP encryption | `tls` |
| Username | Full email address (`you@gmail.com`) |

**Google Workspace:** Prefer the planned **Gmail API** integration ([`google-workspace-mail.md`](google-workspace-mail.md)) when IMAP or app passwords are blocked. IMAP + App Password remains supported for simple cases.

HelixDesk does not implement Gmail OAuth for personal IMAP yet; App Passwords are the supported approach for personal Gmail.

## Configuration

`config/helixdesk.php`:

```php
'mail' => [
    'poll_interval_minutes' => (int) env('MAIL_POLL_INTERVAL_MINUTES', 5),
],
```

## Security

- Mailbox passwords and SMTP secrets stored with Laravel `encrypted` cast.
- Entra client secrets remain in organization `organizational_settings` (existing pattern); mail accounts only reference integration id.
- Tenant isolation on all mail tables via `TenantModel` global scope.

## Future (out of scope for v1)

- **Google Workspace Gmail API** — designed in [`google-workspace-mail.md`](google-workspace-mail.md); not implemented yet.
- Inbound SMTP webhook (push instead of poll).
- End-user notification emails on status change.
- Major-incident broadcast mail.

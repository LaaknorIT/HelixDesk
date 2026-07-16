# Email handling

HelixDesk uses email as a first-class ticket channel: inbound mail creates or updates work items, and agent replies from the queue send outbound mail. Multiple mail accounts can route into the same queue; replies use the address the customer originally wrote to, or the queue’s default outbound address when the ticket was not created by email.

## Goals

- **Inbound**: scheduled polling via IMAP, POP3, Microsoft Graph (Microsoft 365), or **Gmail API** (Google Workspace — [planned](google-workspace-mail.md)).
- **Outbound**: SMTP, Microsoft Graph, or **Gmail API**, using the work item’s receiving mailbox or the queue default.
- **Multi-account queues**: many `mail_accounts` linked to one `queue` via `queue_mail_account`.
- **Threading**: RFC `Message-ID`, `In-Reply-To`, and `References` for reply correlation, with fallbacks via subject token and (when enabled) plus-addressing.
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
| `supports_plus_addressing` | When true, outbound replies set `Reply-To` to `local+{public_id}@domain` (default **false** — not all providers deliver plus-addressed mail to the same mailbox) |
| `is_enabled` | Polling and outbound enabled |
| `last_polled_at`, `last_poll_error` | Operational state |
| `sync_state` | JSON cursor (Graph delta link, POP3 UIDL map, IMAP UID) |

**Organization integration reference**: API mail accounts (`microsoft_graph`, `google_gmail_api`, …) reference an organization integration via `organization_id` + `integration_type` + `integration_id`. The `integration_type` value matches entries in `organizations.organizational_settings` (e.g. `microsoft_entra`, `google_workspace`). Different customers can use different integrations per organization.

**Microsoft Graph:** requires `Mail.ReadWrite` and `Mail.Send` application permissions (admin consent) on the linked Entra app registration. `Mail.Read` alone can fetch MIME but **cannot** mark messages read after ingest (`PATCH isRead`); without `Mail.ReadWrite`, polls re-see the same unread message. Enable the **Mail (shared mailboxes)** capability on the organization Entra integration (or create the integration from the mail account form) so the wizard shows these permissions. A Graph `403 ErrorAccessDenied` on **Test connection** or after ingest almost always means those permissions are missing or not admin-consented.

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
| `public_id` | Tenant-unique random reference (YouTube-style; not the DB id). Used in subject tokens and optional plus-addresses |

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
2. `PollMailAccountJob` runs per account inside tenant context (same path as the admin **Check email** action).
3. Transport fetches new messages:
   - IMAP: unseen (`UNSEEN`) in INBOX
   - POP3: UIDL not yet in `sync_state`
   - Microsoft Graph: inbox messages with `isRead eq false`, excluding Graph ids already recorded in `sync_state.graph_processed_message_ids`
4. `InboundMailIngestionService`:
   - Parses MIME (`zbateson/mail-mime-parser`).
   - Skips if `Message-ID` already in `processed_inbound_emails`.
   - Skips (and does **not** mark processed) when a new ticket cannot be routed because no queue is linked to the mail account and no tenant fallback queue exists — so a later poll can retry after queue linking.
   - Resolves requester (`RequesterResolutionService`: find or create `end_user`). For new users, the display name comes from the `From` header’s person name (`AddressHeader::getPersonName()`), not the header field name; if absent, the email address is used.
   - Resolves the target work item in this order:
     1. RFC `In-Reply-To` / `References` → existing message / `inbound_message_id`
     2. Plus-address token in `To` (e.g. `support+a7Kx2@example.com`) when it matches the mail account’s base address and a tenant `public_id` (only relied on for accounts with `supports_plus_addressing`; still matched if present)
     3. Subject token `[#a7Kx2]` → tenant `public_id`
     4. Else → create incident on a queue linked to the mail account (`source=email`, `mail_account_id` set; assigns a new `public_id`)
   - Stores attachments.
   - Logs threading diagnostics (`in_reply_to`, reference candidates, plus/subject tokens, match method, work item id).
5. Acknowledges ingested, duplicate, and empty-From messages (IMAP/Graph mark read; POP3 UIDL already recorded). Graph also records the message id in `sync_state` **before** mark-as-read so a failed `PATCH` cannot cause endless re-fetch. Acknowledge failures after a successful ingest/duplicate are logged as warnings and do not fail the whole poll. Messages skipped for missing queue stay unread so a later poll can retry after queue linking.

**Microsoft Graph note:** Prefer marking messages read (`Mail.ReadWrite`). If a message is already marked read and not in `sync_state`, HelixDesk will not fetch it. **Test connection** reports total vs unread inbox counts so this is visible during setup.

## Outbound flow

1. Agent posts a non-internal reply via `WorkItemWorkflowService::addMessage` (conversation message is saved first).
2. Service dispatches `SendWorkItemReplyMailJob` **after the HTTP/Livewire response** (not for internal notes or end-user portal replies), so Graph/SMTP failures cannot abort saving the reply or show a generic Filament page error.
3. `OutboundWorkItemMailService` resolves the sending account:
   - If `incident.mail_account_id` is set → use that account.
   - Else → `queue.default_mail_account_id`.
   - If neither → skip send (mail-channel warning).
4. Builds the outbound message (agent display name, attachments when present):
   - Subject includes a stable token using `public_id`, e.g. `Re: Printer broken [#a7Kx2]` (token is not the autoincrement id).
   - When the mail account has `supports_plus_addressing`, sets **Reply-To** to `local+{public_id}@domain` (From stays the normal mailbox address). Leave this off for providers that do not deliver plus-addressed recipients to the same inbox.
   - **SMTP**: sets RFC `In-Reply-To` / `References` on the MIME message.
   - **Microsoft Graph**: Graph rejects standard headers in `internetMessageHeaders` (names must start with `x-` / `X-`). Threading is set via MAPI extended properties instead: `PidTagInReplyToId` (`String 0x1042`) and `PidTagInternetReferences` (`String 0x1039`).
5. Sends via SMTP (dynamic Laravel mailer) or Graph `sendMail`.
6. Sets `email_sent_at` and outbound `external_message_id`.

## UI

- **Administration → Mail accounts**: CRUD, protocol-specific fields, Entra integration picker, **Supports plus-addressing** toggle (off by default).
  - **Test connection**: verifies credentials / Graph app permissions and mailbox access (IMAP login, POP3 login, or Graph token + inbox read). Run after creating or editing an account; also offered automatically after create. For Graph, the result includes inbox total/unread counts.
  - **Check email**: runs inbound poll for that account immediately (same as `PollMailAccountJob`) and reports how many messages were fetched / ingested / skipped, or the error stored in `last_poll_error`.
  - `last_poll_error` is shown on the list (toggleable) and on the edit page when set.
- **Administration → Queues**: attach mail accounts; select default outbound account (must be linked).

## Logging

Mail uses a dedicated Monolog channel `mail` (`storage/logs/mail-YYYY-MM-DD.log`) with **daily rotation and 7-day retention** (`LOG_MAIL_DAYS`, default `7`) so verbose poll/ingest/outbound diagnostics do not grow forever.

**Inbound** events include: poll start/finish (fetched/ingested/skipped counts), fetch failures, ingest skips (empty From, duplicate Message-ID, no resolvable queue), connection verify results, and **threading diagnostics** on each ingest (`match_method`: `message_id` | `plus_address` | `subject_token` | `none`, plus candidates / tokens).

**Outbound** events (agent reply mail) include:
- attempt start (work item / message / protocol / recipient),
- skip reasons (already sent, missing author/recipient, no outbound account, Gmail API not implemented),
- send success (`email_sent_at` set),
- send failures (exception class + message; also recorded by `SendWorkItemReplyMailJob` so Graph/SMTP errors are visible even when the conversation reply was already saved).

Application-wide logs should also use the Laravel `daily` driver in production (`LOG_STACK=daily`, `LOG_DAILY_DAYS=7`) so `laravel-*.log` files are pruned after a week.

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
    'work_item_public_id_length' => (int) env('MAIL_WORK_ITEM_PUBLIC_ID_LENGTH', 5),
    'work_item_public_id_alphabet' => env('MAIL_WORK_ITEM_PUBLIC_ID_ALPHABET', /* lowercase YouTube-like, no 0/o/1/l */),
],
```

| Variable | Purpose | Default |
|----------|---------|---------|
| `MAIL_WORK_ITEM_PUBLIC_ID_LENGTH` | Length of `work_items.public_id` | `5` |
| `MAIL_WORK_ITEM_PUBLIC_ID_ALPHABET` | Characters used when generating `public_id` | Lowercase YouTube-like alphabet (no ambiguous 0/o/1/l) |

Logging (`.env` / `config/logging.php`):

| Variable | Purpose | Default |
|----------|---------|---------|
| `LOG_STACK` | Channels in the default stack | `daily` (recommended) |
| `LOG_DAILY_DAYS` | Days to keep `laravel-*.log` | `7` |
| `LOG_MAIL_DAYS` | Days to keep `mail-*.log` | `7` |

## Security

- Mailbox passwords and SMTP secrets stored with Laravel `encrypted` cast.
- Entra client secrets remain in organization `organizational_settings` (existing pattern); mail accounts only reference integration id.
- Tenant isolation on all mail tables via `TenantModel` global scope.

## Future (out of scope for v1)

- **Google Workspace Gmail API** — designed in [`google-workspace-mail.md`](google-workspace-mail.md); not implemented yet.
- Inbound SMTP webhook (push instead of poll).
- End-user notification emails on status change.
- Major-incident broadcast mail.

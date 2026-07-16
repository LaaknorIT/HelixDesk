# Google Workspace mail (Gmail API)

Design for API-based inbound/outbound mail for **Google Workspace** customers, parallel to the existing **Microsoft Graph** path for Microsoft 365. This document is the implementation spec; code is not yet built.

**Related:** [`email-handling.md`](email-handling.md) (current mail channel), Entra integration pattern in `OrganizationIntegrationSettings`.

---

## 1. Problem statement

HelixDesk today supports Google mail only via **IMAP/SMTP + App Passwords**. That works for some personal Gmail accounts but is a poor fit for **Google Workspace** (including legacy free editions) because:

- Admins often **disable IMAP** or **block app passwords** org-wide.
- There is no Exchange-style **shared mailbox**; team mail is a **user mailbox**, **Google Group**, or **Collaborative Inbox**.
- Service-desk integrations need **stable, admin-consented API access**, not per-user passwords.

**Microsoft 365** already uses application permissions + Graph (`Mail.ReadWrite`, `Mail.Send`) with per-organization Entra app registration. **Google Workspace** needs the equivalent: **Gmail API** with a **service account** and **domain-wide delegation**.

---

## 2. Goals

| Goal | Detail |
|------|--------|
| Parity with M365 Graph mail | Same queue linking, threading, reply-from-same-address, attachments |
| Per-customer credentials | Each organization can have its own Google Cloud / Workspace integration (like multiple Entra apps) |
| Workspace-first | Optimized for `@customer.com` mailboxes; personal `@gmail.com` remains on IMAP/App Password unless we add user OAuth later |
| Reuse ingest pipeline | `InboundMailIngestionService`, `OutboundWorkItemMailService`, `PollMailAccountJob` — only transport layer changes |
| Admin setup clarity | Filament wizard on Organization, similar to Microsoft Entra settings |

**Non-goals (v1):**

- Gmail Pub/Sub push notifications (stay on scheduled polling; push is a later phase).
- Personal Gmail OAuth (user consent flow).
- Google Groups API as a separate product surface (v1 targets a **delegated user mailbox** UPN).

---

## 3. High-level architecture

```mermaid
flowchart LR
  subgraph org [Organization]
    GWS[google_workspace integration in organizational_settings]
  end
  subgraph mail [Mail account]
    MA[mail_accounts.protocol = google_gmail_api]
    MA --> GWS
  end
  Schedule[PollMailAccountsJob] --> Poll[PollMailAccountJob]
  Poll --> Fetch[GmailApiInboundMailFetcher]
  Fetch --> Gmail[Gmail API]
  GWS --> Auth[Service account JWT + domain-wide delegation]
  Auth --> Gmail
  Fetch --> Ingest[InboundMailIngestionService]
  Reply[SendWorkItemReplyMailJob] --> Out[GmailApiOutboundMailService]
  Out --> Gmail
```

Mirror the Microsoft stack:

| M365 (today) | Google Workspace (proposed) |
|--------------|-------------------------------|
| `OrganizationIntegrationSettings::TYPE_MICROSOFT_ENTRA` | `TYPE_GOOGLE_WORKSPACE` |
| Entra app: tenant id, client id, client secret | GCP: service account JSON (encrypted), delegated admin email optional |
| Graph `Mail.ReadWrite` / `Mail.Send` | Gmail API scopes (see §5) |
| `mail_accounts.protocol = microsoft_graph` | `mail_accounts.protocol = google_gmail_api` |
| `connection_settings.mailbox_upn` | `connection_settings.mailbox_address` (Workspace user or group address) |
| `MailAccountEntraResolver` | `MailAccountGoogleWorkspaceResolver` |
| `MicrosoftGraphInboundMailFetcher` | `GmailApiInboundMailFetcher` |
| Graph `sendMail` | `users.messages.send` |

---

## 4. Organization integration (`google_workspace`)

Store in `organizations.organizational_settings` using the same `settings[]` array pattern as Entra.

### 4.1 Integration entry

```json
{
  "id": "uuid",
  "type": "google_workspace",
  "config": {
    "enabled": true,
    "customer_domain": "acme.example",
    "google_cloud_project_id": "helixdesk-acme",
    "service_account_client_email": "helixdesk-mail@helixdesk-acme.iam.gserviceaccount.com",
    "service_account_private_key": "-----BEGIN PRIVATE KEY-----…",
    "delegated_scopes": [
      "https://www.googleapis.com/auth/gmail.readonly",
      "https://www.googleapis.com/auth/gmail.send",
      "https://www.googleapis.com/auth/gmail.modify"
    ],
    "domain_wide_delegation_client_id": "1234567890",
    "setup_verified_at": "2026-07-13T12:00:00Z",
    "last_mail_sync_at": null
  }
}
```

| Field | Purpose |
|-------|---------|
| `customer_domain` | Workspace primary domain (for admin UI and validation) |
| `service_account_client_email` | From GCP service account |
| `service_account_private_key` | Encrypted at rest in JSON (same treatment as Entra `client_secret`) |
| `domain_wide_delegation_client_id` | Numeric client ID shown in Workspace Admin → Security → API controls → Domain-wide delegation |
| `delegated_scopes` | Scopes granted in Admin console (documented in UI) |
| `setup_verified_at` | Set after a successful “Test connection” against a mailbox |

**Constants:** `OrganizationIntegrationSettings::TYPE_GOOGLE_WORKSPACE = 'google_workspace'`.

### 4.2 Workspace admin setup (documented in UI)

1. In **Google Cloud Console** (per customer or shared MSP project):
   - Enable **Gmail API**.
   - Create a **service account**.
   - Enable **domain-wide delegation** on the service account; note the **Client ID**.
2. In **Google Workspace Admin** (admin.google.com):
   - Security → Access and data control → API controls → Domain-wide delegation.
   - Add the service account Client ID with scopes:
     - `https://www.googleapis.com/auth/gmail.readonly`
     - `https://www.googleapis.com/auth/gmail.send`
     - `https://www.googleapis.com/auth/gmail.modify` (mark read / labels after ingest)
3. In **HelixDesk** (Organization → Integrations):
   - Add **Google Workspace** integration; paste service account details.
   - Run **Verify** (calls Gmail API as a test mailbox).

**Legacy free Workspace:** Same delegation model applies if the customer still has Admin console access. If IMAP/app passwords are blocked, API is often the **only** viable automation path.

### 4.3 Mailbox types on Workspace

| Mailbox type | v1 support | Notes |
|--------------|------------|-------|
| **User inbox** (`support@customer.com`) | Yes | Impersonate user via `subject` = mailbox email |
| **Google Group** (Collaborative Inbox) | Best-effort | Use group address as `mailbox_address` if the group has a collaborative inbox; may require group-specific testing |
| **Alias** on user | Yes | Send/receive as user; alias appears in headers |
| **Shared drive / non-mail** | No | Out of scope |

Document in UI: “Enter the **email address of the mailbox** HelixDesk should read and send as.”

---

## 5. Gmail API scopes and auth

### 5.1 Scopes (domain-wide delegation)

| Scope | Use |
|-------|-----|
| `gmail.readonly` | List and download messages |
| `gmail.modify` | Mark as read, add label `HelixDesk/Processed` (optional) |
| `gmail.send` | Send agent replies |

Use **`gmail.modify`** instead of full `gmail` to avoid unnecessary delete permissions.

### 5.2 Authentication flow

1. Load integration config from `organization_id` + `integration_type` + `integration_id` on `mail_accounts`.
2. Build Google API client with service account credentials.
3. Call `setSubject($mailboxAddress)` for **domain-wide delegation** impersonation.
4. Request access token; cache in memory for the job (optionally Redis per integration for token reuse).

**PHP client:** `google/apiclient` (official) or thin HTTP wrapper like `MicrosoftGraphHttpClient`.

No end-user OAuth in v1.

---

## 6. Data model changes

### 6.1 `mail_accounts` (extend)

| Change | Detail |
|--------|--------|
| `protocol` | Add enum case `google_gmail_api` |
| `outbound_protocol` | Add enum case `google_gmail_api` |
| Integration reference | **`integration_type` + `integration_id`** + `organization_id` (implemented; replaces `entra_integration_id`) |

`integration_type` values align with `OrganizationIntegrationSettings` types (`microsoft_entra`, `google_workspace`, …). Mail accounts and future features reference the same organization integration catalog.

`connection_settings` for Google API accounts:

```json
{
  "mailbox_address": "support@customer.com"
}
```

### 6.2 `sync_state` for Gmail API

```json
{
  "history_id": "123456",
  "last_internal_date": "2026-07-13T10:00:00Z"
}
```

- **First poll:** `users.messages.list` with `q=is:unread in:inbox` (or `label:INBOX`) and maxResults; store `historyId` from profile.
- **Subsequent polls:** `users.history.list` from last `historyId` (efficient delta); fallback to unread list if history expired (HTTP 404 → full resync).

### 6.3 Dedup

Continue using `processed_inbound_emails.message_id` with RFC `Message-ID` from parsed MIME. Also store Gmail `id` in `sync_state` or a new optional column `external_provider_id` on processed rows if needed for acknowledge step.

---

## 7. Inbound design

### 7.1 Fetcher: `GmailApiInboundMailFetcher`

Implements existing `InboundMailFetcher` interface.

1. Resolve Google client for mailbox (`MailAccountGoogleWorkspaceResolver`).
2. If no `history_id` in `sync_state`:
   - `GET users/{mailbox}/profile` → `historyId`
   - `GET users/{mailbox}/messages?labelIds=INBOX&q=is:unread`
3. Else:
   - `GET users/{mailbox}/history?startHistoryId={history_id}`
   - For each `messagesAdded`, fetch message.
4. For each message id:
   - `GET users/{mailbox}/messages/{id}?format=raw`
   - Base64url-decode → pass to `InboundMimeParser` (same as IMAP/Graph).
5. `acknowledgeMessage`:
   - `POST users/{mailbox}/messages/{id}/modify` with `removeLabelIds: ['UNREAD']`
   - Optional: add label `HelixDesk/Processed`.

Errors map to `last_poll_error` on `mail_accounts` (same as IMAP).

### 7.2 Shared ingest

No changes to:

- `InboundMailIngestionService`
- `RequesterResolutionService`
- `processed_inbound_emails`
- Attachment storage

---

## 8. Outbound design

### 8.1 Send path

Extend `OutboundWorkItemMailService` (or `GmailApiOutboundMailService` helper):

1. Resolve account (incident mailbox or queue default) — unchanged.
2. Build RFC 2822 MIME (reuse Symfony Mailer / existing MIME builder used for SMTP).
3. Base64url-encode raw message.
4. `POST users/{mailbox}/messages/send` with body `{ "raw": "..." }`.
5. For threading, set `In-Reply-To`, `References`, and `Subject` on the MIME (Gmail threadId optional enhancement in v2).
6. Store returned `Message-ID` / Gmail `id` on `work_item_messages`.

**From address:** Must match delegated `mailbox_address` (or send-as alias configured in Gmail for that user).

---

## 9. UI

### 9.1 Organization → Google Workspace integration

New Filament section parallel to **Microsoft Entra** (reuse wizard patterns from `OrganizationEntraSettingsSchema`):

- Enable toggle
- Customer domain
- Service account email
- Private key (password field, encrypted)
- Domain-wide delegation client ID
- Scope checklist (read-only display)
- **Verify connection** (test mailbox input)
- Link to setup docs

### 9.2 Mail account form

When `protocol = Google Workspace (Gmail API)`:

- Organization picker
- Google Workspace integration picker (filtered by organization)
- Mailbox address (`support@customer.com`)
- Hide IMAP/SMTP fields

Provider preset row:

| Preset | Inbound | Outbound |
|--------|---------|----------|
| Gmail / Google Workspace (IMAP) | imap | smtp |
| **Google Workspace (API)** | `google_gmail_api` | `google_gmail_api` |
| Microsoft 365 (Graph) | `microsoft_graph` | `microsoft_graph` |

---

## 10. Jobs and scheduling

No new job types. Existing:

- `PollMailAccountsJob` → `PollMailAccountJob`
- `InboundMailFetcherFactory` returns `GmailApiInboundMailFetcher` when `protocol === GoogleGmailApi`
- `SendWorkItemReplyMailJob` → branch in `OutboundWorkItemMailService`

---

## 11. Security and compliance

- Service account private keys: **encrypted** in `organizational_settings`; never log.
- Delegation impersonates only mailboxes explicitly configured on `mail_accounts`.
- Prefer EU GCP regions if customer data residency requires it (operational concern; Gmail data still in Google).
- Audit: log integration verify events via `activity()` (optional v1).
- Principle of least privilege: readonly + send + modify only.

---

## 12. Testing strategy

| Layer | Tests |
|-------|-------|
| Unit | JWT subject builder, historyId cursor merge, base64url raw decode |
| Feature | Mock Gmail HTTP; ingest creates incident; reply sends with threading headers |
| Manual | Verify wizard against a Workspace test domain |

Use recorded HTTP fixtures (like Entra sync tests) rather than live Google in CI.

---

## 13. Implementation phases

### Phase A — Foundation
- `TYPE_GOOGLE_WORKSPACE` in `OrganizationIntegrationSettings`
- `GoogleWorkspaceIntegrationCatalog` (scopes, setup notes)
- Filament Organization integration CRUD + verify action
- Encrypted credential storage

### Phase B — Inbound
- `GoogleWorkspaceHttpClient` + `GmailApiInboundMailFetcher`
- `MailInboundProtocol::GoogleGmailApi`
- `sync_state.history_id` polling
- Feature tests with mocked API

### Phase C — Outbound
- `google_gmail_api` outbound in `OutboundWorkItemMailService`
- Threading headers on send
- Attachment support via MIME raw send

### Phase D — Polish
- Filament mail account UX (integration picker)
- Docs and admin runbook
- Optional: `HelixDesk/Processed` label creation

### Phase E (later)
- Gmail Pub/Sub push for near-real-time ingest
- Personal Gmail user OAuth
- Google Groups collaborative inbox hardening

---

## 14. Comparison: when to use which Google path

| Scenario | Recommended |
|----------|-------------|
| Personal `@gmail.com` | IMAP + App Password (current) |
| Workspace, admin allows IMAP | IMAP still OK; API preferred for production |
| Workspace, IMAP/app passwords blocked | **Gmail API (this design)** |
| Workspace shared team address | **Gmail API** on user or group mailbox |
| Microsoft 365 shared mailbox | **Microsoft Graph** (current) |

---

## 15. References

- [Gmail API overview](https://developers.google.com/gmail/api/guides)
- [Domain-wide delegation](https://developers.google.com/identity/protocols/oauth2/service-account#delegatingauthority)
- [users.messages.send](https://developers.google.com/gmail/api/reference/rest/v1/users.messages/send)
- [users.history.list](https://developers.google.com/gmail/api/reference/rest/v1/users.history/list)

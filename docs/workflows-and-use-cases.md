## HelixDesk Workflows and Key Use Cases

This document describes the main business workflows (incident lifecycle, monitoring events, documentation, change approval, password access), and they map to application components.

---

## 1. Incident lifecycle

### 1.1 Default incident workflow

Default statuses (tenant-scoped catalog; see [`domain-model.md`](domain-model.md) §3.0):

- `New`
- `Assigned`
- `Working`
- `Waiting for customer`
- `Replied`
- `Closed (pending)`
- `Closed`

Allowed default **manual** transitions (simplified):

- `New` → `Assigned`
- `Assigned` → `Working`
- `Working` → `Waiting for customer`
- `Working` → `Closed (pending)`
- `Waiting for customer` → `Working`
- `Replied` → `Working` / `Waiting for customer`
- `Closed (pending)` → `Closed` (end user may confirm close)
- `Closed (pending)` → `Working` (reopen)
- `Closed` → `Working` (agent reopen)

Implementation:

- Statuses: `WorkItemStatus` rows seeded by `TenantObserver`.
- Agent ticket UI lists **all** tenant catalog statuses (not only graph edges).
- Explicit `WorkflowTransition` rows still control end-user close and optional per-edge permissions; agents with `work_item.update` may set any catalog status even without a matching transition.
- Each transition can require a permission (usually `work_item.update`); end-user close uses `required_permission = null`.

**Event-driven automations** (`AutomationRule`, also seeded + admin UI):

| Trigger | Condition | Actions |
|---------|-----------|---------|
| Customer reply (portal or email) | Status ∈ Assigned, Working, Waiting for customer, Closed (pending) | Set status → `Replied` |
| Customer reply | Status = Closed | Create new ticket; attach reply to the new ticket |

Tenants can add further rules with multiple ordered actions (set status, create ticket, **send customer email**, …) under **Administration → Ticket automations**.

Queue visibility guard:

- Agent access to ticket lists/details is restricted to work items in queues assigned to that agent.
- Queue assignment is therefore part of effective authorization, alongside per-queue work-item permissions.

SLA integration:

- When a ticket is created (or match fields change):
  - Resolve `SlaPolicy` from any mix of priority, customer (`organization_id`), category, and ticket type (most specific match wins).
  - Set `first_response_due_at` / `resolution_due_at` according to policy targets and calendar (`working_hours` vs `continuous`).
- While status has `pauses_sla` (defaults: Waiting for customer, Closed (pending)), the SLA clock **pauses**.
- Background jobs mark breaches and may fire `sla_breached` automation rules.
- Admin UI: **Administration → SLA policies**.

### 1.2 Agent ticket view (work-first layout)

The **Agent panel** ticket view (`ViewWorkItem`) uses a **68/32 split layout** so agents work the conversation without scrolling past metadata:

**Primary work zone (left, ~68%)**

- Ticket header: ID, title, status/priority/type/queue badges, assignee summary.
- **Quick actions** bar (inline, always visible): workflow status transition, priority update, assignee selection — wired to `WorkItemWorkflowService` and direct field updates.
- **Conversation thread**: threaded external replies and agent internal notes (`is_internal`); internal notes are visually distinct.
- **Reply composer** (always visible): message body, reply-to selector (`parent_message_id`), internal-note toggle — replaces header modal actions for reply and internal note.

**Metadata sidebar (right, ~32%)**

Tabbed compact key-value panels:

| Tab | Contents |
|-----|----------|
| Details | type, priority, status, category, department, opened/resolved/closed dates, assignee, description |
| Requester | name, email, organization, previous ticket count |
| Routing | queue, source, organization, tenant |
| Relations | Assets and Documents relation managers (embedded), linked problems, monitoring events |
| Email | mail account, inbound message ID, thread subject, last inbound message time |
| Activity | Message and lifecycle timeline (creation, replies, internal notes, resolved/closed timestamps) |

Header actions are minimized; reply/internal-note flows live in the inline composer. Status and priority changes are surfaced in the quick-actions bar.

The **End-user panel** ticket view is unchanged (single-column infolist).

---

## 2. Monitoring events and auto-work_items

### 2.1 Event ingestion

Sources:

- Zabbix (initial)
- Other monitoring tools via HTTP/webhooks

Steps:

1. Monitoring system sends event to `/api/events` or dedicated webhook.
2. `ProcessMonitoringEventJob` is queued with:
   - `tenant_id`
   - event payload
3. Job performs:
   - Normalization into `Event` entity.
   - Calculation of `deduplication_key` from relevant fields (e.g. host, service, problem ID).
   - Deduplication:
     - If an open event exists with same `deduplication_key` in a recent time window, update existing event instead of creating a new one.

### 2.2 Incident creation and linking

- For a new or re-opened event:
  - Apply **event rules** to determine whether to:
    - create a new incident, or
    - link to an existing incident.
  - Use asset mapping to link event to `Asset` and route to appropriate `Queue` (e.g. NOC queue).
- For a resolved event:
  - Mark `Event.status` as `resolved` or `closed`.
  - Optionally:
    - auto-transition associated incident to `Resolved` if:
      - no recent agent activity contradicts that state, and
      - auto-resolution is enabled in tenant configuration.

### 2.3 Separation of queues

- Event-driven work_items are typically routed to:
  - special **monitoring/operations queues**, not standard service desk queues.
- This separation is configured per tenant and queue, allowing:
  - Dedicated NOC teams to work from their own dashboards.

---

## 3. Documentation workflows

### 3.1 States and transitions

Default documentation states:

- `draft`
- `in_review`
- `published`

Default transitions:

- `draft` → `in_review`
- `in_review` → `published`
- `in_review` → `draft` (changes requested)
- `published` → `draft` (major revision)

Implementation:

- `WorkflowDefinition` with `entity_type = document`, used by:
  - `KnowledgeArticle`
  - `HelpdeskGuide`
  - `ITDoc`
- Optional per-type overrides:
  - Tenants can enable workflow only for IT operations docs, for example.

### 3.2 Approval behavior

- By default, workflows are **disabled**:
  - Documents can be edited and published directly by authorized users.
- When enabled:
  - Only users with specific permissions (e.g. `approve_docs`) can move documents from `in_review` to `published`.
  - Audit logs record:
    - who approved
    - previous and new version
    - who wrote the change

### 3.3 Linking documents to other entities

Use cases:

- Link KB articles to:
  - work_items (for resolution references),
  - problems (for root cause knowledge),
  - services/assets (for service catalogs).
- Link helpdesk guides and IT docs to:
  - processes, risks, non-conformances (for ISO).
- Link IT ops docs to the software catalog (see [`service-offerings-and-software.md`](service-offerings-and-software.md)):
  - **Software product** — partner-level docs (CSP, shared vendor runbooks).
  - **Software deployment** — customer-level docs (default agent focus).
  - **Service offering** — capability overviews spanning deployments.
  - **Software license** — optional contract attachments.

Agent UX: when viewing a customer deployment, show **customer documents** first; provide navigation to **partner / product** documents without mixing them into the default list.

Implementation:

- Polymorphic `document_links` table:
  - `tenant_id`
  - `document_id`
  - `linkable_type`
  - `linkable_id`

---

## 4. Change management and risk assessment

### 4.1 Change request workflow

States:

- `Draft`
- `Review`
- `Approved`
- `Scheduled`
- `Implemented`
- `Closed`

Transitions (simplified):

- `Draft` → `Review`
- `Review` → `Approved`
- `Approved` → `Scheduled`
- `Scheduled` → `Implemented`
- `Implemented` → `Closed`

Approval model:

- Single-step approval in v1:
  - A user with `change.approve` (or broader change permissions) can move change from `Review` to `Approved`.
- Future extension:
  - Additional `ChangeApproval` records with role and sequence fields.

### 4.1.1 Change Calendar

Purpose:

- Give operations and change managers a **time-based overview** of all upcoming and past changes.

Behavior:

- Calendar can be viewed by:
  - day / week / month
  - filters (queue, team, organization, asset/service, status)
- Each change uses:
  - `scheduled_start_at` and `scheduled_end_at` for its calendar slot.
- Clicking a calendar entry opens:
  - change details
  - linked assets/services
  - risk assessment
  - related work-items/problems.

### 4.1.2 Change Kanban board

Purpose:

- Provide a **visual workflow board** for managing changes by status and work-in-progress.

Behavior:

- Columns correspond to change states:
  - `Draft`, `Review`, `Approved`, `Scheduled`, `Implemented`, `Closed` (or tenant-customized equivalents).
- Cards:
  - one card per `Change`, showing key fields (title, requester, risk, window, affected services).
- Interactions:
  - Drag-and-drop between columns where transitions are allowed by the workflow definition.
  - Permissions and workflow rules determine which moves are valid (e.g. only approvers can move into `Approved`).
- Filters:
  - by queue, team, organization, risk level, and schedule window (e.g. only show changes in the next 7 days).

### 4.2 Risk linkage

Steps for a risk-aware change:

1. During change creation or review:
   - Risk is assessed using probability and consequence (1–5).
2. A `Risk` entity can be:
   - created specifically for the change, or
   - selected from existing risks.
3. The change stores `risk_id` and displays:
   - risk score (probability × consequence),
   - risk matrix position (color-coded).

Visualization:

- A heatmap view shows all risks for the tenant with:
  - axes: `probability` and `consequence`
  - color: severity (low/medium/high).

---

## 5. Password access and MFA enforcement

### 5.1 Access flow

Scenario: Agent accesses a password entry.

1. Agent opens an asset/customer/team or password list.
2. Agent selects a password entry to view/copy.
3. System checks:
   - agent’s permissions (scoped to password’s tenant and scope).
   - tenant’s MFA policy for password access.
   - `last_mfa_at` on user.
4. If MFA is required and `last_mfa_at` is older than 30 minutes:
   - System triggers a new MFA challenge (e.g. TOTP, push).
   - On success, update `last_mfa_at`.
5. Decrypt password using tenant-unique salt.
6. Log an entry in `PasswordAccessLog` with:
   - `user_id`, `password_entry_id`, `access_type`, `reason`, `accessed_at`.

### 5.2 Encryption model

- Per-tenant key hierarchy:
  - Tenant-specific salt (stored securely, e.g. in encrypted config or KMS).
  - Passwords encrypted server-side using tenant key material.
- Future extension:
  - Support for client-side encryption if stricter compliance is needed.

---

## 6. AI-assisted workflows

### 6.1 Incident triage and suggestions

When an agent opens an incident:

1. UI calls backend endpoint to fetch AI suggestions.
2. Backend enqueues `RunAIInferenceJob` with:
   - incident context (sanitized)
   - request type (`work_item_suggestion`).
3. Job:
   - selects appropriate AI provider based on:
     - tenant’s configuration (BYO key vs. HelixDesk AI).
     - provider availability and priority order.
   - calls provider via Laravel AI SDK.
4. On response:
   - store `AIUsageRecord` (tokens, credits, provider).
   - store `AIInferenceLog` with summarized suggestion.
5. Agent UI shows:
   - proposed solution,
   - similar work-items/problems,
   - suggested KB articles.
6. If agent accepts:
   - apply changes (e.g. add work log, link problem),
   - record in `AIInferenceLog` as “applied”, with `approved_by_user_id`.

### 6.2 Batch analysis and clustering

Scheduled job:

1. Picks work-items/problems that match criteria (e.g. last N days, unresolved).
2. Groups them for clustering or trend analysis.
3. Enqueues low-priority `RunAIInferenceJob` tasks.
4. Uses results to:
   - propose new problems (root causes),
   - propose improvement actions,
   - enrich risk and ISO modules.

All such jobs:

- Run on low priority.
- Are fully logged via `AIUsageRecord` and `AIInferenceLog`.

---

## 7. Dashboards and widgets

### 7.1 Agent dashboard

Widgets:

- My work_items
- Incidents in my queues
- Major work_items
- Monitoring alerts/events
- SLA breaches
- KPIs (e.g. MTTR, open problems, open changes, risk distribution)

Configuration:

- Tenant admin can:
  - define default widget sets per role.
  - lock specific widgets:
    - widget locked → cannot be removed or significantly reconfigured by individual agents.

Implementation:

- Store dashboard layouts as JSON per:
  - `tenant_id`
  - `role_name` (Spatie role name, e.g. defaults from `config/helixdesk_permissions.php`)
  - `user_id` (for personal overrides).

Resolution order at runtime:

1. Active `user_override` for the current user.
2. Active `role_default` for one of the user's tenant roles (e.g. `Admin`, `Agent`).
3. Application fallback widget set.

---

## 8. Global admin assigns tenant administrator

**Actor:** platform global admin (`users.is_admin = true`).

**Goal:** grant a user the **Tenant Admin** Spatie role for a specific tenant without changing their `tenant_id`, `type`, or `is_admin`. For global admins, this also grants **role-based** access to that tenant’s agent panel (distinct from impersonation).

**Flow:**

1. Global admin opens **Global Admin → Tenants → Edit tenant**.
2. On the **Tenant administrators** relation tab, reviews current holders of the tenant-scoped **Tenant Admin** role (name + email + source badge: tenant agent vs global admin).
3. Clicks **Add tenant administrator**, selects an eligible user:
   - agents belonging to that tenant (`type = agent`, matching `tenant_id`), or
   - any global admin (`is_admin = true`).
4. System assigns the **Tenant Admin** role via `assignRole()` with `team_id` set to the tenant (roles stack — existing **Agent** role is retained) and logs a `tenant_access` / `tenant_admin_assigned` activity (who assigned whom, which tenant, whether the assignee is a global admin).
5. Global admins assigned this way remain hidden from tenant **People**; their `tenant_id`, `type`, and `is_admin` are unchanged.
6. A global admin with the assignment can open `/agent/{tenant-slug}/` (URL-based tenancy; tenant switcher is off). `SyncFilamentTenantContext` sets `team_id` so Spatie permissions resolve for that tenant, and logs a per-session `tenant_access` / `tenant_accessed` activity the first time that session hits the tenant. `User::isTenantStaff()` and `User::canOperateInTenant()` treat such global admins as back-office operators so tickets, notes, and other tenant writes work despite `users.tenant_id` being null.

**Constraints:**

- Add-only from this UI (no revoke action).
- Users who already hold **Tenant Admin** for the tenant are excluded from the candidate list.
- End users and agents from other tenants are not eligible.
- Global admins **without** the **Tenant Admin** role for a tenant must still be denied that tenant’s agent panel (no automatic access to all tenants).
- Global admins with the role for tenant A must not access tenant B’s agent panel.
- Global admins may assign themselves; that assignment and subsequent panel access are audited.

**Implementation:**

- `App\Services\TenantAdminAssignmentService`
- `App\Services\TenantAccessAudit`
- `App\Filament\GlobalAdmin\Resources\Tenants\RelationManagers\TenantAdminsRelationManager`
- `App\Http\Middleware\SyncFilamentTenantContext`


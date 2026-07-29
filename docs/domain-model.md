## HelixDesk Domain Model

This document describes the main domain entities, their relationships, and key invariants. It is a working model to guide migrations, Eloquent models, and API design.

> Note: Field lists are indicative, not exhaustive. Types and naming must follow Laravel and project conventions.  
> **AGPL core:** payment processors, Stripe, and subscription tables are **not** part of this codebase; some sections below are retained as **historic product sketches** only.

---

## 1. Core tenancy and identity

### 1.1 Tenant

Represents one MSP or one internal IT department.

Key fields:

- `id`
- `name`
- `slug` or `subdomain` (optional, for routing)
- `status` (`active`, `readonly`, `disabled`)
- `region` (EU region identifier, if multi-region introduced later)
- `integration_settings` (planned JSON) — tenant-wide integration behaviour, e.g. `auto_retire_assets_when_all_providers_drop` (see [`integrations.md`](integrations.md) §3.8)

Relationships:

- has many `Organization`
- has many `User`
- has many `Role`, `Team`, `Queue`
- has many `OrganizationIntegration` (through `Organization`, or direct `tenant_id` scope)
- has many `IdpConnection`

Invariants:

- All tenant-owned entities reference `tenant_id`.
- Prefer **archiving/disabling** tenants instead of hard deletion when audit or compliance retention applies.

### 1.2 Organization

Represents a node in the **tenant organization tree**: customer groups, billable customers, and departments share one table and one foreign-key target for scoped records.

Key fields:

- `id`
- `tenant_id`
- `parent_id` (tree parent; root nodes use `-1` for Filament tree compatibility)
- `type` (`group`, `customer`, `department`)
- `name`, optional `code`, optional `external_reference`
- `is_billable` (typically true for `customer` nodes used in invoicing)
- `order` (sibling sort order in the tree UI)
- `organizational_settings` (nullable JSON) — legacy/non-integration org configuration; **integrations migrate to `organization_integrations`** (see §1.2.1)

Nesting rules:

| Parent type | Allowed child types |
|-------------|---------------------|
| *(tenant root)* | `group`, `customer` |
| `group` | `group`, `customer` |
| `customer` | `customer`, `department` |
| `department` | `department` |

Relationships:

- belongs to `Tenant`
- belongs to `Organization` as parent (optional)
- has many `Organization` as children
- has many `User`, `Asset`
- belongs to many `ServiceOffering` via **`organization_service_offering`** (explicit multi-select; `customer` and/or `group` nodes — **no** child inheritance). See [`service-offerings-and-software.md`](service-offerings-and-software.md).
- has many `OrganizationIntegration` (planned — [`integrations.md`](integrations.md))

Scoped records (`User`, `Asset`, `WorkItem`, etc.) reference a **single** `organization_id` pointing at the tree node they belong to. Use `organization_id = null` for tenant-wide records (e.g. unassigned CIs).

### 1.2.1 OrganizationIntegration (planned)

Per-customer connection to an external provider (Entra, Google Workspace mail, Action1, future RMM). Replaces integration entries in `organizations.organizational_settings.settings[]`.

**Table:** `organization_integrations`  
**Model:** `App\Models\OrganizationIntegration`

Key fields:

- `id` (uuid PK)
- `tenant_id`, `organization_id`
- `type` (`microsoft_entra`, `google_workspace`, `action1`, …)
- `enabled`
- `config` (JSON — scopes, provider org IDs, `action1_group_id`, non-secrets)
- `credentials` (encrypted JSON — client secrets, API keys, private keys)
- `api_base_url` (nullable — Action1 region endpoint, e.g. US vs EU)
- `last_sync_at`, `last_sync_error`, `sync_interval_hours`

Relationships:

- belongs to `Tenant`, `Organization`
- referenced by `asset_external_identifiers.organization_integration_id`, satellite CMDB tables, and `mail_accounts.integration_id` (same UUID preserved on migration)

Invariants:

- All rows scoped by `tenant_id`; `organization.tenant_id` must match.
- Secrets only in `credentials`; never in plain `config`.

UI:

- Agent panel navigation label defaults to **Customers** (configurable via `config/helixdesk.php`).
- Managed via `OrganizationResource` with a Filament tree index page and standard edit/relation managers for assets, service offerings, and people.

### 1.3 User

Represents both agents and end users.

Key fields:

- `id`
- `tenant_id`
- `organization_id` (nullable for agents; scoped tree node for end users)
- `type` (`agent` for all back-office staff including those who administer the tenant, `end_user`, `global_admin` for platform operators when stored on the same table, etc.)
- `email`, `name`
- `last_mfa_at` (for password vault access enforcement)

Relationships:

- belongs to `Tenant`, `Organization` (optional), `Department` (optional)
- belongs to many `Role`, `Team`
- has many `WorkItem` as `reported_by` and `assigned_agent`
- has many `PasswordEntry` as last viewed by

Invariants:

- Back-office staff (`type = agent`) must belong to a tenant; **tenant administration** is a **Spatie role** (default name `Tenant Admin`, configurable in `config/helixdesk_permissions.php`) with full permission assignment via `*` (or explicitly including `tenant.admin`), not a separate `users.type`.
- **Tenant Admin** may be assigned from the **Global Admin** panel (`TenantAdminsRelationManager` on tenant edit) to tenant agents or global admins via `TenantAdminAssignmentService` (`assignRole()` with `team_id` = tenant id). Global admins receive the role without `tenant_id` or `type` changes; they stay hidden from tenant People. Global admins with the role may access that tenant’s agent panel via extended `User::canAccessPanel` / `canAccessTenant` / `getTenants` gates (role-based, not impersonation).
- Global admins do **not** automatically access every tenant. Access requires an explicit **Tenant Admin** assignment for that tenant’s `team_id`. Assigned global admins keep `users.tenant_id = null`; tenant-scoped writes (work-item messages, transitions, API creates, etc.) must use **`User::canOperateInTenant()`** / **`effectiveTenantId()`** (from Filament/`SyncFilamentTenantContext`) rather than requiring a non-null `users.tenant_id`. New rows (e.g. `work_item_messages.tenant_id`) take the **resource’s** tenant id, not the user’s home `tenant_id`.
- Assigning Tenant Admin and each session’s first agent-panel access by an assigned global admin are audited under activity log name `tenant_access`.
- End users must belong to an `Organization`.

### 1.4 Role, Team, Queue

**Role**

- `id`, `tenant_id`, `name`, `description` (Spatie `roles` with `team_id` = tenant)
- permission strings are attached via **`role_permissions`** (`role_id`, `permission`, `guard_name`, timestamps). There is **no** separate `permissions` lookup table; the string is the canonical permission name (e.g. `problem.read`, `tenant.admin`). **Work item** actions (`work_item.read`, `work_item.update`, …) are **not** stored here except **`work_item.admin`** (full bypass); per-queue work-item rights live on the **`role_queue`** pivot.
- belongs to many `Queue` via **`role_queue`** (`role_id`, `queue_id`, `work_item_permissions` JSON, timestamps). The JSON holds booleans per work-item action (read, create, update, delete, admin) for that queue on that role.
- users belong to many roles.
- Default seeded roles and **`{module}.{action}`** permission names are defined in `config/helixdesk_permissions.php`. The **Tenant Admin** role receives all catalog permissions by default (`*` expansion) but can be edited like any other role.

**Team**

- `id`, `tenant_id`, `name`, `description`
- organizational grouping (e.g. Network, Desktop).
- users belong to many teams.

**Queue**

- `id`, `tenant_id`, `name`, `description`
- `default_mail_account_id` (nullable FK) — outbound address when work items were not created from email
- routing unit for work items and monitoring-driven tickets.
- has many `WorkItem`
- belongs to many `Role` via **`role_queue`**
- belongs to many `MailAccount` via **`queue_mail_account`**

**MailAccount**

- `id`, `tenant_id`, `name`, `email_address`
- `protocol` (`imap`, `pop3`, `microsoft_graph`), `outbound_protocol` (`smtp`, `microsoft_graph`)
- `organization_id`, `integration_type`, `integration_id` (optional — API mail via organization integrations such as `microsoft_entra` or `google_workspace`)
- `connection_settings` (encrypted JSON), `is_enabled`, poll/sync state fields
- belongs to many `Queue` via **`queue_mail_account`**

See [`email-handling.md`](email-handling.md) for inbound/outbound flows.

Queue-scoped authorization invariant:

- For agents, **work item** capabilities are evaluated per **`queue_id`** using **`role_queue.work_item_permissions`** (and **`work_item.admin`** on `role_permissions` if present). Per-queue **`admin: true`** grants all work-item actions on that queue. Saving a role with **Admin** enabled on every linked queue also persists global **`work_item.admin`**. Legacy data may use global `work_item.*` on `role_permissions` only when the pivot JSON is null.
- An agent can read a work item only when they have **read** (or **admin**) for that work item’s queue on at least one assigned role; list/index scopes use the union of queue IDs where **read** is granted.

---

## 2. ITIL core: work items, problems, changes, major work_items

### 2.1 WorkItem (ticket)

The unified actionable record for service desk work. The UI label is **Ticket**; the Eloquent model is **`WorkItem`** (`work_items` table).

**`type`** (ITIL/product meaning — reclassifiable):

| `type` value | Meaning |
|--------------|---------|
| `incident` | Unplanned service disruption (ITIL incident) |
| `service_request` | Standard fulfillment (access, hardware, information) |
| `task` | Operational or proactive work (recurring checks, patch rollout, reminders) |
| `defect` | Software bug |
| `feature_request` | Product enhancement request |

**`source`** (how it entered the system — orthogonal to `type`):

- `portal`, `email`, `api`, `monitoring`, …

Key fields:

- `id`
- `tenant_id`
- `organization_id` (nullable — tree scope node)
- `reported_by_user_id`
- `assigned_agent_id` (nullable)
- `queue_id`
- `type` (see table above; default `incident`)
- `title`
- `description`
- `priority` (string; common values `normal`, `high`, `urgent`, or tenant-defined labels such as Low/Medium/High/Critical)
- `category_id` (nullable FK to `WorkItemCategory`)
- `status` (string matching a tenant `WorkItemStatus.name`; default open status is usually `New`)
- `sla_policy_id` (nullable FK to matched `SlaPolicy`)
- `first_response_due_at`, `resolution_due_at` (nullable — computed when an SLA is attached)
- `first_responded_at` (nullable — first non-internal agent reply)
- `sla_paused_at` (nullable — set when status pauses SLA)
- `sla_paused_seconds` (accumulated paused time)
- `response_breached_at`, `resolution_breached_at` (nullable)
- `opened_at`, `resolved_at`, `closed_at`
- `source`
- `mail_account_id` (nullable — receiving mailbox for reply-from-same-address)
- `inbound_message_id` (nullable — first RFC `Message-ID`)
- `public_id` (tenant-unique random mail/UI reference; not the autoincrement id)

Relationships:

- belongs to `Tenant`, `Organization` (optional), `Department` (optional), `Queue`, `MailAccount` (optional)
- belongs to `WorkItemCategory` (optional), `SlaPolicy` (optional), `WorkItemStatus` conceptually by name
- belongs to `User` as `reported_by` and as `assigned_agent`
- belongs to many `Asset`
- belongs to many `Problem`
- has many `MonitoringEvent` links (via pivot)
- has many `WorkItemMessage` (conversation thread)

Related entities kept **separate** (not ticket types):

- **`MonitoringEvent`** — raw monitoring signal; may spawn a work item via rules
- **`Problem`** — root-cause investigation linked to many work items
- **`Change`** — planned change with its own approval workflow
- **`TaskSchedule`** (future) — recurring definition that generates `task`-type work items

Invariants:

- `tenant_id` matches all related entities (organization, queue, status, SLA, category).
- Agents may set any `WorkItemStatus` in the tenant catalog (with queue `work_item.update`); explicit `WorkflowTransition` rows optionally tighten per-edge permissions. End users remain limited to allowed transitions (typically Closed (pending) → Closed).
- Event-driven `AutomationRule`s may change status or spawn tickets without going through the agent transition UI, but should still land on a valid tenant status name.
- Reclassifying `type` is allowed; audit the change and optionally re-match queue/SLA.

### 2.2 Problem

Represents the underlying cause of multiple work items, within a single tenant.

Key fields:

- `id`
- `tenant_id`
- `title`
- `description`
- `root_cause` (nullable until analysis complete)
- `workaround` (nullable)
- `permanent_fix` (nullable)
- `status` (e.g. `open`, `under_investigation`, `resolved`, `closed`)

Relationships:

- belongs to `Tenant`
- has many `WorkItem` (many-to-many)
- optionally linked to `KnowledgeArticle`

Invariants:

- Does not span tenants.

### 2.3 Change

Key fields:

- `id`
- `tenant_id`
- `title`
- `description`
- `risk_id` (nullable, link to Risk entity)
- `status` (Draft/Review/Approved/Scheduled/Implemented/Closed)
- `requested_by_user_id`
- `approved_by_user_id` (nullable for Draft/Review)
- `implementation_plan`
- `rollback_plan`
- `scheduled_start_at`, `scheduled_end_at`

Relationships:

- belongs to `Tenant`
- belongs to many `Asset`
- belongs to many `WorkItem` / `Problem`
- belongs to `Risk` (optional)

Invariants:

- Single-approver model initially; design allows additional approval records later.

### 2.4 MajorIncident

Key fields:

- `id`
- `tenant_id`
- `title`
- `description`
- `status` (e.g. `open`, `communicating`, `resolved`, `closed`)
- `impact_summary`

Relationships:

- belongs to `Tenant`
- has many `WorkItem`
- has many `MajorIncidentUpdate` (timeline events and communications)

---

## 3. Statuses, SLAs, and workflows

### 3.0 WorkItemStatus

Tenant-scoped catalog of ticket statuses. Agents and automations use the `name` string on `work_items.status`.

Key fields:

- `id`
- `tenant_id`
- `name` (unique per tenant; display and stored value, e.g. `Waiting for customer`)
- `sort_order`
- `is_system` (seeded defaults; rename allowed carefully, delete blocked while referenced)
- `is_default_open` (exactly one per tenant — usually `New`)
- `pauses_sla` (true for `Waiting for customer` and `Closed (pending)`)
- `sets_resolved_at` (true for terminal-ish states that stop active work, e.g. `Closed (pending)`, `Closed`)
- `sets_closed_at` (true for `Closed`)
- `color` (nullable — UI badge hint)

**Default statuses** (seeded by `TenantObserver` on tenant create):

| Name | pauses_sla | sets_resolved_at | sets_closed_at | Notes |
|------|------------|------------------|----------------|-------|
| New | no | no | no | Default open |
| Assigned | no | no | no | |
| Working | no | no | no | Active agent work |
| Waiting for customer | **yes** | no | no | SLA clock pauses |
| Replied | no | no | no | Customer replied; needs agent attention |
| Closed (pending) | **yes** | **yes** | no | Soft close; awaiting customer confirmation |
| Closed | no | **yes** | **yes** | Terminal |

Relationships:

- belongs to `Tenant`
- referenced by name from `work_items.status`, `workflow_transitions`, and automation conditions/actions

Admin UI: **Administration → Ticket statuses** (`tenant.admin`).

### 3.0b WorkItemCategory

Lightweight taxonomy for tickets (used by SLA matching and filtering).

Key fields:

- `id`, `tenant_id`, `name`, `sort_order`, `is_active`

Relationships:

- belongs to `Tenant`
- has many `WorkItem`

Admin UI: **Administration → Categories** (or embedded with SLA/ticket forms).

### 3.1 SlaPolicy

Defines response and/or resolution targets and which tickets they apply to.

Key fields:

- `id`
- `tenant_id`
- `name` (e.g. `Normal`, `Gold`)
- `is_active`
- `target_type` (`first_response` | `resolution` | `both`)
- `first_response_minutes` (nullable)
- `resolution_minutes` (nullable)
- `calendar_type` (`working_hours` | `continuous`)
  - `working_hours`: count only configured business hours (tenant/org calendar; v1 may use a simple Mon–Fri window)
  - `continuous`: wall-clock time including nights/weekends
- Matching dimensions (any mix; null = wildcard):
  - `applies_to_priority` (nullable string)
  - `applies_to_organization_id` (nullable — customer)
  - `applies_to_category_id` (nullable)
  - `applies_to_work_item_type` (nullable — `WorkItemType` value)
- `match_priority` (integer; higher wins when multiple policies match equally on specificity)

**Matching:** On create (and when priority/org/category/type change), pick the best active policy:

1. Prefer policies whose non-null match fields equal the ticket.
2. Among candidates, highest count of non-null match fields wins (most specific).
3. Tie-break with `match_priority`, then lowest `id`.

**Timer behavior:**

- Start when the work item is created (or when an SLA is first attached).
- **Pause** while the current status has `pauses_sla = true` (defaults: Waiting for customer, Closed (pending)).
- Resume when leaving a pausing status; due timestamps shift by paused duration (or remaining time is tracked via `sla_paused_seconds`).
- First-response SLA is satisfied when the first non-internal **agent** message is posted (`first_responded_at`).
- Resolution SLA is satisfied when status sets `resolved_at` / reaches a status with `sets_resolved_at` (typically Closed (pending) or Closed), per policy `target_type`.
- Background job marks `response_breached_at` / `resolution_breached_at` when due time passes while not paused.

Examples:

- **Normal**: first response within 8 working hours (`target_type=first_response`, `calendar_type=working_hours`).
- **Gold**: closed within 8 continuous hours (`target_type=resolution`, `calendar_type=continuous`, `resolution_minutes=480`).

Relationships:

- belongs to `Tenant`, optional `Organization`, optional `WorkItemCategory`
- used by many `WorkItem`

Admin UI: **Administration → SLA policies** (`tenant.admin`).

### 3.2 WorkflowDefinition (allowed transitions)

Manual from→to graph for agents (and end-user close). Still used by `WorkItemWorkflowService::transition()`.

Key fields:

- `id`
- `tenant_id`
- `entity_type` (`work_item`, `change`, `document`)
- `name`
- `is_default`

Relationships:

- belongs to `Tenant`
- has many `WorkflowTransition` (`from_status`, `to_status`, `required_permission`)

**Default work-item transitions** (seeded by `TenantObserver`):

- New → Assigned
- Assigned → Working
- Working → Waiting for customer
- Working → Closed (pending)
- Waiting for customer → Working
- Replied → Working
- Replied → Waiting for customer
- Closed (pending) → Closed (end users may close; `required_permission` null)
- Closed (pending) → Working (reopen)
- Closed → Working (reopen by agent)

Admin UI: transitions are editable alongside **Ticket statuses** or under **Ticket workflows** (allowed-transition editor).

### 3.3 AutomationRule (event-driven workflows)

Rules that fire on ticket events and run an ordered list of **actions** (tasks). Distinct from allowed transitions.

Key fields (`automation_rules`):

- `id`, `tenant_id`, `name`, `is_enabled`, `is_system` (seeded defaults)
- `trigger` (`customer_replied`, `agent_replied`, `status_changed`, `created`, `sla_breached`, …)
- `conditions_json` (e.g. `{ "statuses": ["Assigned", "Working", ...] }`)
- `sort_order`

Key fields (`automation_rule_actions`):

- `id`, `tenant_id`, `automation_rule_id`, `sort_order`
- `action_type` (`set_status`, `create_work_item`, `send_customer_email`, …)
- `action_config_json` (type-specific payload)

**Default rules** (seeded by `TenantObserver`):

1. **Customer reply → Replied**: trigger `customer_replied`, when status ∈ {Assigned, Working, Waiting for customer, Closed (pending)} → action `set_status` = `Replied`.
2. **Customer reply on Closed → new ticket**: trigger `customer_replied`, when status = `Closed` → action `create_work_item` (copy requester/queue/org; link to original; attach the reply to the new ticket).

**Multi-action rules:** A single rule may run several actions in `sort_order` (e.g. set status **and** send a customer email). Email actions use outbound mail (`OutboundWorkItemMailService` / queue mailbox) with configurable subject/body templates.

Relationships:

- belongs to `Tenant`
- has many `AutomationRuleAction`

Admin UI: **Administration → Ticket automations** (`tenant.admin`) — create/edit/delete rules, reorder actions, including email templates.

---

## 4. CMDB and assets

### 4.1 AssetType

Key fields:

- `id`
- `tenant_id`
- `name` (e.g. `PC`, `Server`, `Application`, `License`, `Network equipment`)

### 4.2 Asset

Key fields:

- `id`
- `tenant_id`
- `organization_id` (nullable — tree scope node; null = tenant-wide)
- `asset_type_id`
- `name`
- `asset_identifier` (internal asset tag; **not** provider GUIDs after multi-source migration — see [`integrations.md`](integrations.md))
- `serial_number`
- `primary_hostname` (planned — canonical hostname for search/matching)
- `operating_system` (planned — normalized OS string)
- `purchase_date`
- `warranty_date`
- `lifecycle_status` (e.g. `in_use`, `in_stock`, `retired`)
- `owner_user_id` (nullable)
- `identity_resolution_status` (planned — `resolved`, `ambiguous`, `manual`, `unlinked`)
- `last_inventory_sync_at` (planned)

Relationships:

- belongs to `Tenant`, `AssetType`
- belongs to `Organization` (optional)
- belongs to `User` as owner (optional)
- belongs to many `WorkItem`, `Change`, `Event`
- belongs to many `ServiceOffering` (technical realization pivot **`service_offering_asset`**)
- has many `AssetRelationship` as parent/child
- has many `AssetExternalIdentifier`, `AssetSoftwareInstallation`, `AssetPatchStatus`, `AssetPatchItem` (planned — [`integrations.md`](integrations.md))
- documents via **`document_links`** (morph)

### 4.2.1 AssetExternalIdentifier (planned)

Maps a canonical `Asset` to provider-specific IDs (Entra `azureADDeviceId`, Action1 endpoint id, etc.). Scoped by `tenant_id`, `organization_id`, and `organization_integration_id` (UUID FK → `organization_integrations.id`).

### 4.2.2 AssetSoftwareInstallation (planned)

Installed software per asset, primarily from RMM providers (Action1). Upserted on sync; stale rows removed after TTL.

### 4.2.3 AssetPatchStatus (planned)

Patch compliance **summary** per asset per provider (`missing_critical_count`, `missing_other_count`, `compliance_status`). Child **`AssetPatchItem`** rows hold normalized KB/CVE detail (v1, not deferred).

### 4.2.4 AssetPatchItem (planned)

Per-KB/CVE missing update row linked to `AssetPatchStatus`. Unique on `(tenant_id, asset_id, provider, organization_integration_id, kb_id)`.

### 4.2.5 AssetIdentityAmbiguity

Open reconciliation conflict when a provider snapshot soft-matches multiple assets. Agents resolve via Filament (`AssetIdentityAmbiguityResource`).

Key fields:

- `tenant_id`, `organization_id`, `organization_integration_id`, `provider`
- `candidate_asset_ids` (JSON), `snapshot_json`, `status`, `resolved_asset_id` (nullable)

Relationships:

- belongs to `Tenant` (required for Filament Agent-panel tenancy ownership; relationship name `tenant`)
- belongs to `Organization`, `OrganizationIntegration`
- belongs to `Asset` as `resolvedAsset` (optional)

### 4.3 AssetRelationship

Represents CMDB-style dependencies.

Key fields:

- `id`
- `tenant_id`
- `parent_asset_id`
- `child_asset_id`
- `relationship_type` (e.g. `depends_on`, `hosted_on`, `connected_to`)

Invariants:

- `parent_asset.tenant_id` must equal `child_asset.tenant_id`.

### 4.4 Supplier

Commercial vendor (e.g. carrier, software vendor) scoped to a tenant.

Key fields:

- `id`, `tenant_id`, `name`, `notes` (nullable)

Relationships:

- belongs to `Tenant`
- belongs to many `ServiceOffering` via **`service_offering_supplier`** (`role`: `primary`, `backup`, `support`; optional `notes`)
- documents via **`document_links`** (morph)

### 4.5 Service offering (service catalog)

A **customer IT capability** the tenant supports (e.g. “Internet connectivity”, “Financial software”, “Office support”) — **not** the MSP’s commercial package (e.g. “IT operations”). Distinct from CIs (`Asset`) and from software catalog entities (§4.6–4.8). Full design: [`service-offerings-and-software.md`](service-offerings-and-software.md).

Key fields:

- `id`, `tenant_id`
- `name`, `description` (nullable), `code` (nullable, internal reference)
- `kind` (`internet_connectivity`, `business_application_suite`, `custom`, …) — drives suggested asset/supplier roles and optional required attributes
- `attributes_json` (nullable) — service-specific facts (e.g. `line_number` for connectivity); validated per `kind` where configured

**Organization scope:** many-to-many via **`organization_service_offering`** (explicit multi-select of `customer` and/or `group` nodes). **No inheritance** from group to children. The same organization may have **multiple** offerings of the same `kind`.

*(Legacy: single `organization_id` on `service_offerings` — migrate into the pivot, then remove.)*

Relationships:

- belongs to `Tenant`
- belongs to many `Organization` via **`organization_service_offering`**
- belongs to many `Supplier` via **`service_offering_supplier`**
- belongs to many `Asset` via **`service_offering_asset`** with pivot **`role`** (e.g. `primary_access`, `backup_access`, `circuit`) — non-software technical realization
- belongs to many `SoftwareDeployment` via **`service_offering_software_deployment`** — application realization
- documents via **`document_links`** (morph)

Invariants:

- All linked `Supplier`, `Asset`, and `SoftwareDeployment` rows must belong to the same `tenant_id` as the offering.
- Pivot organizations must share that `tenant_id`.

### 4.6 Software product

Tenant-wide catalog entry for a product reused across customers (e.g. Microsoft 365, Visma ERP).

Key fields:

- `id`, `tenant_id`, `name`, optional `vendor_name`, `supplier_id`, `category`, `description`, `partner_notes`, `external_reference`, `is_active`

Relationships:

- belongs to `Tenant`, optional `Supplier`
- has many `SoftwareDeployment`
- documents via **`document_links`** — **partner-level** documentation

Invariants:

- Prefer unique `(tenant_id, name)` (enforce in application validation).

### 4.7 Software deployment

Concrete use of a **Software product** at one **Organization** (normally a customer).

Key fields:

- `id`, `tenant_id`, `software_product_id`, `organization_id`
- `name`, optional `environment` (`production`, `test`, `dr`, `other`), `status` (`active`, `planned`, `retired`)
- optional `external_tenant_id`, `admin_url`, `notes`, `attributes_json`

Relationships:

- belongs to `Tenant`, `SoftwareProduct`, `Organization`
- belongs to many `ServiceOffering` via **`service_offering_software_deployment`** (one deployment may serve multiple offerings)
- has many `SoftwareLicense`
- belongs to many `Asset` via **`software_deployment_asset`** (pivot `role`: e.g. `hosted_on`, `related`, `identity_provider`)
- documents via **`document_links`** — **customer-level** documentation (default agent UX focus)

Invariants:

- Product, organization, and deployment share `tenant_id`.
- Multiple deployments of the same product are allowed (per customer and/or environments).

### 4.8 Software license

Dedicated entitlement row on a **Software deployment** (rich documentation: seats, expiry, contract refs). Not the same as a generic License-type `Asset`, though assets may still represent physical media.

Key fields:

- `id`, `tenant_id`, `software_deployment_id`, `name`
- optional `sku` / `product_code`, `license_type`, `metric`
- optional `seat_count`, `seats_used` (manual in v1 — not auto-synced)
- optional `starts_on`, `expires_on`, `auto_renew`
- optional `cost_amount`, `cost_currency`, `billing_period`
- optional `vendor_contract_reference`, `purchase_order_reference`, `notes`, `attributes_json`

Relationships:

- belongs to `Tenant`, `SoftwareDeployment`
- documents via **`document_links`** (optional)

Invariants:

- Each license belongs to exactly one deployment (customer context).

---

## 5. Documentation

### 5.1 Base documentation entities

Use a shared base pattern across:

- `KnowledgeArticle` (end-user KB)
- `HelpdeskGuide` (agent guide)
- `ITDoc` (technical ops docs)

Common fields:

- `id`
- `tenant_id`
- `type` (KB/Helpdesk/ITDoc) if using single table inheritance
- `title`
- `slug`
- `content` (rich text/markdown)
- `status` (`draft`, `in_review`, `published`, etc.)
- `visibility` (`public`, `authenticated`, `restricted`)
- `owner_user_id`

Relationships:

- belongs to `Tenant`
- belongs to `WorkflowDefinition` (optional, for approval)
- has many `DocVersion`

### 5.2 DocVersion

Key fields:

- `id`
- `tenant_id`
- `document_id`
- `version_number`
- `content`
- `created_by_user_id`

---

## 6. Monitoring and events

### 6.1 Event

Represents a monitoring event or alert.

Key fields:

- `id`
- `tenant_id`
- `event_source_id`
- `external_reference` (ID from monitoring system)
- `severity`
- `message`
- `status` (`open`, `acknowledged`, `resolved`, `closed`)
- `deduplication_key` (hash used for dedup)

Relationships:

- belongs to `Tenant`
- belongs to `EventSource`
- belongs to many `Asset`
- belongs to many `WorkItem`

Invariants:

- Deduplication logic uses `deduplication_key` + time window.

### 6.2 EventSource

Key fields:

- `id`
- `tenant_id`
- `provider` (`zabbix`, `prometheus`, etc.)
- `name`
- `configuration_json`

---

## 7. ISO modules: non-conformance, risk, process

### 7.1 NonConformance

Key fields:

- `id`
- `tenant_id`
- `title`
- `description`
- `status` (`open`, `under_investigation`, `resolved`, `closed`)

Relationships:

- belongs to `Tenant`
- may link to `WorkItem`, `Change`, `Risk`, `ImprovementAction`

### 7.2 Risk

Key fields:

- `id`
- `tenant_id`
- `title`
- `description`
- `probability` (e.g. 1–5)
- `consequence` (e.g. 1–5)
- `score` (calculated `probability * consequence`)
- `mitigation`

Relationships:

- belongs to `Tenant`
- may link to `Change`, `NonConformance`, `Process`, `Asset`

### 7.3 Process

Key fields:

- `id`
- `tenant_id`
- `name`
- `description`

Relationships:

- belongs to `Tenant`
- may link to `Risk`, `NonConformance`, `ImprovementAction`

### 7.4 ImprovementAction

Key fields:

- `id`
- `tenant_id`
- `title`
- `description`
- `status` (`planned`, `in_progress`, `completed`)

Relationships:

- belongs to `Tenant`
- may be linked from `Risk`, `NonConformance`, `Process`

---

## 8. Password vault

### 8.1 PasswordEntry

Key fields:

- `id`
- `tenant_id`
- `label`
- `encrypted_secret`
- `scope_type` (`asset`, `organization`, `team`, `global`)
- `scope_id` (nullable)

Relationships:

- belongs to `Tenant`
- belongs to `Asset`, `Organization`, or `Team` depending on scope.

Invariants:

- `encrypted_secret` is encrypted using a **tenant-unique salt**.

### 8.2 PasswordAccessLog

Key fields:

- `id`
- `tenant_id`
- `password_entry_id`
- `user_id`
- `access_type` (`view`, `copy`)
- `reason` (text)
- `accessed_at`

---

## 9. Billing and subscriptions (out of scope in AGPL core)

**Not implemented** in this repository: Stripe, Cashier, `Subscription`, seat metering exports, or payment webhooks. Any future **optional** usage accounting (e.g. AI quotas) should be documented separately and remain AGPL-compatible.

---

## 10. AI integration

### 10.1 AIProviderConfig

Key fields:

- `id`
- `tenant_id` (nullable for global defaults)
- `provider` (e.g. `openai`, `azure_openai`, `self_hosted_x`)
- `priority` (for failover order)
- `configuration_json` (keys, endpoints, models, limits)
- `enabled`

### 10.2 AIUsageRecord

Key fields:

- `id`
- `tenant_id`
- `provider`
- `model`
- `tokens_input`
- `tokens_output`
- `credits_charged`
- `context_type` (`work_item_suggestion`, `kb_answer`, etc.)
- `created_at`

Used for:

- internal monitoring and optional usage limits

### 10.3 AIInferenceLog

Key fields:

- `id`
- `tenant_id`
- `job_type`
- `request_payload` (sanitized as needed)
- `response_summary`
- `approved_by_user_id` (nullable if suggestion only)
- `applied_changes_json` (if AI made changes)
- `created_at`

---

## 11. Audit logging

### 11.1 AuditLogEntry

Key fields:

- `id`
- `tenant_id` (nullable for global events)
- `actor_type` (`user`, `global_admin`, `system`)
- `actor_id` (nullable for system)
- `action` (string key)
- `target_type`
- `target_id`
- `metadata` (JSON)
- `created_at`

Foreign key behavior:

- For most tenant-owned tables, the `tenant_id` foreign key is configured with **ON DELETE CASCADE**, so deleting a tenant removes all its associated records.  
- For `AuditLogEntry`, `tenant_id` is **nullable** and configured so that **on tenant deletion, `tenant_id` is set to NULL instead of cascading delete**, preserving the audit history for platform-wide analysis.

Visibility rules:

- Tenant admins see only entries with their `tenant_id`.
- Global admins can see all entries.

---

## 12. Dashboards

### 12.1 DashboardLayout

Tenant-scoped persisted dashboard configuration for agent-facing dashboards.

Key fields:

- `id`
- `tenant_id`
- `scope` (`role_default`, `user_override`)
- `role_name` (nullable; required for `role_default`)
- `user_id` (nullable; required for `user_override`)
- `layout_json` (JSON object/array with widget placement and settings)
- `locked_widgets` (JSON array of widget identifiers that cannot be removed/reconfigured by personal overrides)
- `is_active`
- `created_at`, `updated_at`

Invariants:

- Exactly one active default per `(tenant_id, role_name)` pair.
- At most one active override per `(tenant_id, user_id)`.
- Effective layout precedence:
  1. active user override (`user_override`)
  2. active role default (`role_default`)
  3. hardcoded application fallback.


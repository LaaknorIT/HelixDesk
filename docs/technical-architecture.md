## HelixDesk Technical Architecture

This document turns the high-level requirements in `project-description.md` into concrete technical architecture decisions for the first major version of HelixDesk.

**License:** The application is **AGPL-3.0-or-later** (see repository `LICENSE`). **Billing, payments, and Stripe/Cashier are not part of this codebase.**

---

## 1. High-level system overview

### 1.1 Context

HelixDesk is a **multi-tenant ServiceDesk** platform, typically hosted in **EU regions** when data residency matters. It serves:

- One **tenant per MSP or IT department**.  
- Each tenant serves one or more **customer companies**, each with departments and end users.

At a high level, the system consists of:

- **Web Application** (Laravel + Livewire + Filament)  
- **REST API** (Laravel controllers + OpenAPI docs)  
- **Background workers / Queues** (Laravel Horizon)  
- **AI integration layer** (Laravel AI SDK, when enabled)  
- **Object storage** (S3-compatible, e.g. EU region)  
- **External integrations** (SSO, monitoring, Intune/Zabbix, SMS/email providers, status page, etc.)

---

## 2. Deployment and runtime architecture

### 2.1 Services and processes

Minimum deployment includes:

- **Web (HTTP) nodes**
  - Serve the Laravel/Filament UI and REST API.
  - Stateless; tenant context and auth derived from tokens/session, not local state.
- **Queue worker nodes**
  - Run Laravel Horizon and all queued jobs (AI, email, monitoring event processing, imports, reporting).
  - Use prioritized queues within a single Horizon configuration.
- **Database**
  - Single relational database cluster (e.g. managed PostgreSQL or MySQL) in an EU region.
  - All tenant data separated by `tenant_id` and, where needed, by tenant-specific schemas/constraints.
- **Object storage**
  - DigitalOcean Spaces (S3-compatible, EU region).
  - Used for attachments, exports, backup artifacts, and optionally AI logs.
- **Cache / session store**
  - Redis for:
    - Horizon queues
    - Caching
    - Session storage (if not using cookies-only)
    - Rate limiting
    - Feature flags and tenant configuration caching

All components must be deployable in **multiple web/worker instances** behind a load balancer for horizontal scaling.

### 2.2 Tenancy strategy

**Model:**  
Single shared database with **row-level tenancy**:

- Every tenant-owned table includes `tenant_id`.  
- All application queries run through:
  - A **tenant-aware base model** (e.g. `TenantModel` abstract) that enforces global scopes.
  - A **TenantContext** service that resolves the current `tenant_id` from:
    - authenticated user, or
    - explicit tenant identifier in API token, or
    - subdomain / hostname (if using per-tenant domains).

Key decisions:

- **No cross-tenant entities**: All domain entities (work_items, assets, problems, changes, risks, etc.) are tenant-local.
- **Global-only entities**: Some tables (e.g. `tenants`, `global_integrations`, `ai_providers`) are managed at the global level but may still reference tenant IDs in configuration.
- **Sharding readiness**: The schema and application layers should be designed so that splitting tenants across multiple databases in the future is possible, e.g.:
  - Avoid cross-tenant joins.
  - Use tenant IDs consistently in indexes.

Foreign key behavior:

- For all tenant-owned tables, `tenant_id` foreign keys are configured with **ON DELETE CASCADE** so that deleting a tenant cleans up its data consistently.  
- `audit_log_entries.tenant_id` is the exception:
  - it is nullable, and
  - **on tenant deletion, `tenant_id` is set to NULL** rather than deleting the audit rows, so audit history is preserved.

### 2.3 Security boundaries

- **Tenant isolation at application layer** via:
  - Tenant global scopes on Eloquent queries.
  - Authorization policies checking `tenant_id` and RBAC, with RBAC implemented via `spatie/laravel-permission` (teams/multi-tenancy mode) and wired to `tenant_id`.
- **Queue-level work item partitioning** inside a tenant:
  - Agent ticket access is constrained by **`role_queue.work_item_permissions`** (per queue, per role) plus optional global **`work_item.admin`**, unioned across roles.
  - API and Filament must evaluate work item actions using **`HelixdeskWorkItemAuthorization`** (queue id from the work item or target queue), not bare `work_item.read` / `work_item.update` on `role_permissions` alone.
- **Administrative separation**:
- **Global admins** live in a separate guard/role system and are hidden from tenant UIs.
- Tenant-facing UIs are separated by persona:
  - an **Agent panel** where tenant agents/tenant admins manage work_items,
  - an **End-user panel** where end users can only manage their own work_items (create, reply, close).
- Tenant scoping inside tenant-facing Filament panels is handled via Filament's built-in `tenant()` feature (panel tenancy), not by exposing tenant identifiers in UI forms/tables.
- Work item handling in tenant-facing panels uses a dedicated **record view/edit page** (instead of list-row quick actions), so new workflows can be added without overloading list tables.
- Work item conversation data supports:
  - threaded external replies (`work_item_messages.parent_message_id`),
  - agent-only internal notes (`work_item_messages.is_internal = true`),
  - status and priority updates in the record page workflow.
- **Agent ticket view layout** (`app/Filament/Agent/Resources/WorkItems/`):
  - `ViewWorkItem` overrides Filament `content()` to render a single infolist (no relation-manager block below the page).
  - `WorkItemInfolist` schema: `Grid` ~7/3 columns — left work zone (header, `workZoneForm` quick actions + composer, conversation `RepeatableEntry`), right `Tabs` sidebar for metadata.
  - Relation managers (`AssetsRelationManager`, `DocumentsRelationManager`) are embedded in the Relations tab via `Livewire::make()`; `getRelationManagers()` on the page returns empty to avoid duplicate tables.
  - Inline forms use a dedicated `workZoneForm` Livewire schema on `ViewWorkItem` (status transition via `WorkItemWorkflowService::transition()`, assignee/priority direct updates, composer via `addMessage()` / `addInternalNote()`).
- Global admin access to a tenant is recorded in a dedicated table (e.g. `tenant_impersonations` or similar).
- **Data residency**:
  - All databases and object storage located in EU regions.
  - Backups also stored in EU regions.

---

## 3. Application layers and modules

### 3.1 Laravel application layering

Recommended structure (following Laravel 12 streamlined conventions and existing project patterns):

- `app/Domain/*`
  - Domain models, aggregates, and domain services per bounded context (e.g. `ITIL`, `Documentation`, `Identity`, `Monitoring`, `ISO`, `PasswordVault`).
- `app/Http/*`
  - Controllers (web + API), HTTP middleware, request validation classes.
- `app/Filament/*` (or convention chosen in this project)
  - Filament resources, pages, and widgets for the admin/agent UI.
  - Panel-specific code is organized by panel folder:
    - `app/Filament/GlobalAdmin/*`
    - `app/Filament/Agent/*`
    - `app/Filament/EndUser/*`
  - Each panel provider discovers resources/pages/widgets only inside its own folder.
- `app/Actions/*`
  - Application-level actions (e.g. `CreateIncident`, `ResolveIncident`, `RecordAIUsage`).
- `app/Jobs/*`
  - Jobs for queue processing (AI requests, monitoring event processing, imports).
- `app/Services/*`
  - Integrations (e.g. SSO, Intune, Zabbix, SMS gateway, status page).
- `config/helixdesk_permissions.php`
  - Canonical list of permission **names** (`{module}.{action}`) and default tenant role → permission assignments; tenant seeding expands this config into rows on **`role_permissions`** (string per role). The legacy Spatie `permissions` table is not used.

Each domain area should be modeled as a **bounded context** with its own:

- entities/aggregates
- policies/authorization
- events
- actions/use-cases

### 3.2 Bounded contexts (high level)

Initial major bounded contexts:

1. **Identity & Tenancy**
   - Tenant, Organization (unified tree: `group` / `customer` / `department` on one table), User, Role, Team, Queue, IdP configuration.
   - Agent panel: `OrganizationResource` with **Filament Tree** index (`solution-forest/filament-tree`) and standard edit/relation managers for assets, service offerings, and people. Navigation label defaults to **Customers** (`config/helixdesk.php`).
   - Global Admin panel: `TenantResource` edit page includes **`TenantAdminsRelationManager`** — lists users with the tenant-scoped **Tenant Admin** Spatie role, with an add-only action backed by **`TenantAdminAssignmentService`** (eligible candidates: tenant agents + global admins; excludes existing tenant admins).
   - **Agent panel access gates** (`User` implements `FilamentUser` + `HasTenants`):
     - `canAccessPanel('agent')`: `isTenantStaff()` (`tenant_id` + `type = agent`, or global admin with **Tenant Admin** for current team context) **or** global admin with at least one **Tenant Admin** Spatie assignment (`model_has_roles.team_id`).
     - `isTenantStaff()`: true for tenant agents (`tenant_id` + `type = agent`) and for global admins who hold **Tenant Admin** for `effectiveTenantId()` (from `SyncFilamentTenantContext` / `setPermissionsTeamId`). Filament resource `canAccess()` and `HelixdeskWorkItemAuthorization` rely on this — without it, assigned global admins would only see Dashboard.
     - `canAccessTenant($tenant)`: matching `tenant_id` **or** (`is_admin` && `isTenantAdminFor($tenant)` — explicit `model_has_roles.team_id` check for the **Tenant Admin** role).
     - `getTenants()`: home tenant (if any) **plus** tenants where the user holds **Tenant Admin** (for global admins with assignments). All three methods must agree so Filament does not return 403/404 mismatches.
     - `SyncFilamentTenantContext` middleware sets `team_id` from the URL tenant; no change required for global-admin tenant-admin access.
     - This is **not** impersonation: `tenant_id` and `type` stay unchanged; global admins remain excluded from tenant People queries.
2. **ITIL Core**
   - WorkItem, Problem, Change, MajorIncident, SLA, Workflow.
3. **CMDB & Assets**
   - Asset, AssetType, AssetRelationship, LifecycleRule.
4. **Documentation**
   - KnowledgeArticle, HelpdeskGuide, ITDoc, DocWorkflow, DocVersion.
5. **Monitoring & Events**
   - Event, EventSource, EventRule, EventIncidentLink.
6. **ISO & Risk**
   - NonConformance, Risk, Process, ImprovementAction.
7. **PasswordVault**
   - PasswordEntry, Scope (asset/customer/team), MFA enforcement rules.
8. **AI Integration**
   - AIProviderConfig, AIUsageRecord, AIJob, AIInferenceLog.
9. **Audit & Logging**
   - AuditLogEntry, TenantAuditView, GlobalAuditView.
10. **Dashboards**
   - DashboardLayout, WidgetPreset, role defaults, user overrides.

Historic subscription or metered-billing designs are **not** implemented in the AGPL core.

---

## 4. Queues, jobs, and prioritization

### 4.1 Queue configuration

Use **Laravel Horizon** with a **single logical queue** configured with **priorities**:

- High priority:
  - Live chat AI responses
  - Agent-assist AI suggestions
  - User-triggered actions (e.g. incident creation via portal/chat)
- Medium priority:
  - Monitoring event processing
  - Email/SMS notifications
  - Password MFA checks that can be async
- Low priority:
  - Batch AI analysis (clustering, trend detection)
  - Reporting and KPI aggregations

Implementation approach:

- Use dedicated job classes with explicit `public $priority` or naming conventions (e.g. `ai-high`, `ai-low`) and map them to Horizon priority handling.
- Configure Horizon dashboards to group jobs by domain (ITIL, Monitoring, AI).

### 4.2 Critical jobs

Key job categories:

- `ProcessMonitoringEventJob`
  - Deduplicate events, correlate with existing work_items, create or update work_items.
- `PollMailAccountsJob` / `PollMailAccountJob`
  - Scheduled mailbox polling (IMAP, POP3, Microsoft Graph shared mailboxes).
- `SendWorkItemReplyMailJob`
  - Outbound email when agents reply on email-sourced work_items (and portal tickets with a queue default mailbox).
- `SendMajorIncidentNotificationJob`
  - Send email/SMS/status page/webhook notifications for major work_items.
- `RunAIInferenceJob`
  - Execute calls against configured AI provider via Laravel AI SDK.
- `EnforcePasswordMFAJob`
  - Validate MFA recency and trigger re-challenge if needed.

---

## 5. Integrations

### 5.1 SSO and IdPs

Requirements:

- Multiple IdPs per tenant (e.g. one per customer company).
- Primary: Microsoft Entra (Azure AD).

Design:

- `idp_connections` table keyed by `tenant_id`, `customer_company_id`, `provider`, and configuration JSON.
- Use Laravel Socialite (or equivalent) plus custom integration for enterprise SSO flows.
- Map external identities to internal `User` records via:
  - stable external IDs (e.g. Entra `oid`)
  - email domain and company mapping, when safe.

### 5.2 Monitoring (Intune, Zabbix, others)

Initial integrations:

- **Intune** for device/asset inventory.
- **Zabbix** for monitoring events.

Design:

- `integration_connections` table (tenant-scoped) describing:
  - provider type (`intune`, `zabbix`, `jamf`, etc.)
  - auth/endpoint configuration
  - sync settings (frequency, mapping rules).
- Use **pull** (scheduled sync) and **push** (webhook/event) patterns depending on provider.
- Normalize:
  - assets into `assets` and `asset_relationships`
  - events into `events` with references to assets.

Backlog providers use the same abstraction with new provider-specific services.

### 5.3 Communications (email, SMS, status page, webhooks)

**Ticket email channel** (implemented — see [`email-handling.md`](email-handling.md)):

- Tenant-scoped **`mail_accounts`** with IMAP, POP3, or Microsoft Graph inbound polling and SMTP/Graph outbound.
- Many mail accounts per **`queue`**; **`queues.default_mail_account_id`** for portal-created tickets.
- Inbound ingest via `PollMailAccountJob` → `InboundMailIngestionService` (dedup, threading, requester resolution, attachments).
- Agent replies dispatch `SendWorkItemReplyMailJob` → `OutboundWorkItemMailService` (reply-from-same-address).
- Graph mail reuses organization integrations via `integration_type` + `integration_id` on `mail_accounts`.
- **Google Workspace mail (planned):** Gmail API + service account domain-wide delegation; see [`docs/google-workspace-mail.md`](google-workspace-mail.md).

**Other communications** (planned):

- Abstract broader notifications into a **NotificationService** per tenant:
  - SMS via pluggable SMS gateway (e.g. Twilio or EU-friendly provider).
  - Status page via first-party UI or external integrations.
  - Webhooks with configurable endpoints and payload templates.

---

## 6. API design

### 6.1 Standards

- **RESTful** endpoints under `/api`.
- **OpenAPI/Swagger** documentation:
  - Source of truth: annotations or dedicated YAML/JSON under `docs/api/`.
  - Generated UI (e.g. Swagger UI) for exploration.

### 6.2 Tenant scoping

All API calls must be explicitly tied to a tenant:

- Token contains tenant identifier (preferred).
- Or tenant inferred from subdomain/hostname.

Patterns:

- Use Laravel Sanctum/Passport or equivalent token mechanism.
- Include `tenant_id` claim in JWT-style tokens, or maintain token-to-tenant mapping in DB.

### 6.3 Versioning

- Start with `/api/v1/*`.
- Use semantic versioning at API level to avoid breaking integrators.

### 6.4 Monitoring event ingestion (implemented surface)

- Primary paths: `GET|POST /api/v1/monitoring-events`, `GET /api/v1/monitoring-events/{id}`.
- **Alias** for integrators expecting a generic “events” resource: the same controller actions are also mounted at `GET|POST /api/v1/events` and `GET /api/v1/events/{id}` (same `{id}` as monitoring events).

---

## 7. Observability and audit

### 7.1 Application logging

- Use Laravel’s logging stack:
  - structured JSON logs in production.
  - central log aggregation (e.g. ELK or similar) recommended.

### 7.2 Audit logging

- Prefer `spatie/laravel-activitylog` for an append-only activity/audit trail (immutable by convention; no in-place updates).
- Where domain requirements demand stricter, queryable audit semantics (e.g. before/after snapshots or a specific schema), persist an `AuditLogEntry` entity in DB:
  - `tenant_id` (nullable for global events)
  - actor (user/global admin/service)
  - action type
  - target type and ID
  - metadata JSON (before/after snapshots where appropriate)
  - timestamp
- Views:
  - Tenant-scoped audit UI.
  - Global-only audit UI for platform operators.

---

## 8. Performance and scaling considerations

Key strategies:

- Index common filters:
  - `tenant_id`, `status`, `queue_id`, `assigned_agent_id` on work_items.
  - `tenant_id`, `asset_type`, `lifecycle_status` on assets.
- Use **pagination** and **cursor-based pagination** for large lists.
- Cache:
  - Tenant configuration (SLAs, workflows, integrations) in Redis.
  - Frequently accessed lookup tables (categories, priorities).
- Offload:
  - Heavy AI processing and batch analysis to queues.
  - Report generation to background jobs with downloadable exports.

Target scale:

- ~1,000 tenants.
- ~10,000 concurrent agents.
- Response time \< 500 ms for typical UI/API calls under normal load.


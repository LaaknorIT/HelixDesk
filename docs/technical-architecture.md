## HelixDesk Technical Architecture

This document turns the high-level requirements in `project-description.md` into concrete technical architecture decisions for the first major version of HelixDesk.

---

## 1. High-level system overview

### 1.1 Context

HelixDesk is a **multi-tenant SaaS ServiceDesk** platform hosted in **EU regions only**. It serves:

- One **tenant per MSP or IT department**.  
- Each tenant serves one or more **customer companies**, each with departments and end users.

At a high level, the system consists of:

- **Web Application** (Laravel + Livewire + Filament)  
- **REST API** (Laravel controllers + OpenAPI docs)  
- **Background workers / Queues** (Laravel Horizon)  
- **AI integration layer** (Laravel AI SDK)  
- **Billing and metering** (Laravel Cashier + Stripe)  
- **Object storage** (DigitalOcean Spaces / S3-compatible, EU-only)  
- **External integrations** (SSO, monitoring, Intune/Zabbix, SMS/email providers, status page, etc.)

---

## 2. Deployment and runtime architecture

### 2.1 Services and processes

Minimum deployment includes:

- **Web (HTTP) nodes**
  - Serve the Laravel/Filament UI and REST API.
  - Stateless; tenant context and auth derived from tokens/session, not local state.
- **Queue worker nodes**
  - Run Laravel Horizon and all queued jobs (AI, email, monitoring event processing, imports, reporting, billing metering).
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

- **No cross-tenant entities**: All domain entities (incidents, assets, problems, changes, risks, etc.) are tenant-local.
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
  - Authorization policies checking `tenant_id` and RBAC.
- **Administrative separation**:
  - **Global admins** live in a separate guard/role system and are hidden from tenant UIs.
  - Global admin access to a tenant is recorded in a dedicated table (e.g. `tenant_impersonations` or similar).
- **Data residency**:
  - All databases and object storage located in EU regions.
  - Backups also stored in EU regions.

---

## 3. Application layers and modules

### 3.1 Laravel application layering

Recommended structure (following Laravel 12 streamlined conventions and existing project patterns):

- `app/Domain/*`
  - Domain models, aggregates, and domain services per bounded context (e.g. `ITIL`, `Billing`, `Documentation`, `Identity`, `Monitoring`, `ISO`, `PasswordVault`).
- `app/Http/*`
  - Controllers (web + API), HTTP middleware, request validation classes.
- `app/Filament/*` (or convention chosen in this project)
  - Filament resources, pages, and widgets for the admin/agent UI.
- `app/Actions/*`
  - Application-level actions (e.g. `CreateIncident`, `ResolveIncident`, `RecordAIUsage`).
- `app/Jobs/*`
  - Jobs for queue processing (AI requests, monitoring event processing, imports, billing metering).
- `app/Services/*`
  - Integrations (e.g. SSO, Intune, Zabbix, SMS gateway, status page, Stripe wrappers).

Each domain area should be modeled as a **bounded context** with its own:

- entities/aggregates
- policies/authorization
- events
- actions/use-cases

### 3.2 Bounded contexts (high level)

Initial major bounded contexts:

1. **Identity & Tenancy**
   - Tenant, Organization (tree hierarchy), User, Role, Team, Queue, IdP configuration.
2. **ITIL Core**
   - Incident, Problem, Change, MajorIncident, SLA, Workflow.
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
9. **Billing**
   - Subscription, LicenseAssignment, ConcurrentUsageSample, StripeCustomer, StripeSubscription, MeteredUsageExport.
10. **Audit & Logging**
   - AuditLogEntry, TenantAuditView, GlobalAuditView.

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
  - Billing metering exports to Stripe

Implementation approach:

- Use dedicated job classes with explicit `public $priority` or naming conventions (e.g. `ai-high`, `ai-low`) and map them to Horizon priority handling.
- Configure Horizon dashboards to group jobs by domain (ITIL, Monitoring, AI, Billing).

### 4.2 Critical jobs

Key job categories:

- `ProcessMonitoringEventJob`
  - Deduplicate events, correlate with existing incidents, create or update incidents.
- `SendMajorIncidentNotificationJob`
  - Send email/SMS/status page/webhook notifications for major incidents.
- `RunAIInferenceJob`
  - Execute calls against configured AI provider via Laravel AI SDK.
- `RecordConcurrentUsageSampleJob`
  - Snapshot current concurrent users per tenant every hour.
- `ExportDailyMeteredUsageToStripeJob`
  - Export highest concurrent user count per tenant per day to Stripe.
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

- Abstract communications into a **NotificationService** per tenant:
  - Email via Laravel mailers.
  - SMS via pluggable SMS gateway (e.g. Twilio or EU-friendly provider).
  - Status page via:
    - first-party status page (own UI), or
    - integration with external status page services.
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

---

## 7. Observability and audit

### 7.1 Application logging

- Use Laravel’s logging stack:
  - structured JSON logs in production.
  - central log aggregation (e.g. ELK or similar) recommended.

### 7.2 Audit logging

- **AuditLogEntry** entity in DB:
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
  - `tenant_id`, `status`, `queue_id`, `assigned_agent_id` on incidents.
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


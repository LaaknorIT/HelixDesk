## HelixDesk Domain Model

This document describes the main domain entities, their relationships, and key invariants. It is a working model to guide migrations, Eloquent models, and API design.

> Note: Field lists are indicative, not exhaustive. Types and naming must follow Laravel and project conventions.

---

## 1. Core tenancy and identity

### 1.1 Tenant

Represents one MSP or one internal IT department.

Key fields:

- `id`
- `name`
- `slug` or `subdomain` (optional, for routing)
- `status` (`active`, `readonly`, `disabled`)
- `billing_mode` (Stripe, external/manual)
- `region` (EU region identifier, if multi-region introduced later)

Relationships:

- has many `Organization`
- has many `User`
- has many `Role`, `Team`, `Queue`
- has many `Subscription`, `LicenseAssignment`, `ConcurrentUsageSample`
- has many `IntegrationConnection`, `IdpConnection`

Invariants:

- All tenant-owned entities reference `tenant_id`.
- Tenant cannot be deleted if it has active billing records; must be archived/disabled.

### 1.2 Organization

Represents an organizational unit served by the tenant (MSP or IT department).  
Organizations form a **tree/folder hierarchy** under a tenant, allowing:

- one root organization with nested sub-organizations (similar to departments), or  
- multiple top-level organizations (e.g. multiple customers), each with their own sub-structure.

Key fields:

- `id`
- `tenant_id`
- `parent_organization_id` (nullable, for tree hierarchy)
- `name`
- `external_reference` (for MSP CRM/ERP linking)

Relationships:

- belongs to `Tenant`
- belongs to `Organization` as parent (optional)
- has many `Organization` as children
- has many `User` (typically end users)
- has many `Asset`
- has many `IdpConnection`

### 1.3 User

Represents both agents and end users.

Key fields:

- `id`
- `tenant_id`
- `organization_id` (nullable for agents)
- `type` (`agent`, `end_user`, `tenant_admin`, etc.)
- `email`, `name`
- `last_mfa_at` (for password vault access enforcement)

Relationships:

- belongs to `Tenant`, `Organization` (optional)
- belongs to many `Role`, `Team`
- assigned to many `Queue` (via assignments)
- has many `Incident` as `reported_by` and `assigned_agent`
- has many `PasswordEntry` as last viewed by

Invariants:

- Agents must belong to a tenant and can optionally be linked to an organization.
- End users must belong to an `Organization`.

### 1.4 Role, Team, Queue

**Role**

- `id`, `tenant_id`, `name`, `description`
- has many `Permission` through pivot (e.g. `role_permissions`)
- users belong to many roles.

**Team**

- `id`, `tenant_id`, `name`, `description`
- organizational grouping (e.g. Network, Desktop).
- users belong to many teams.

**Queue**

- `id`, `tenant_id`, `name`, `description`
- routing unit for incidents/events/requests.
- has many `Incident`
- has many `User` (agents assigned to queue).

---

## 2. ITIL core: incidents, problems, changes, major incidents

### 2.1 Incident

Key fields:

- `id`
- `tenant_id`
- `organization_id`
- `department_id` (nullable)
- `reported_by_user_id`
- `assigned_agent_id` (nullable)
- `queue_id`
- `title`
- `description`
- `priority` (enum: Low, Medium, High, Critical)
- `category_id` (FK to incident category)
- `status` (enum with default workflow; customizable via workflow engine)
- `sla_policy_id` (nullable)
- `opened_at`, `resolved_at`, `closed_at`

Relationships:

- belongs to `Tenant`, `Organization` (optional), `Queue`
- belongs to `User` as `reported_by` and as `assigned_agent`
- belongs to many `Asset`
- belongs to many `Problem`
- has many `Event` links
- has many `WorkLog` / `Comment`

Invariants:

- `tenant_id` matches all related entities (organization, queue).
- Status transitions must follow tenant-defined workflow rules.

### 2.2 Problem

Represents the underlying cause of multiple incidents, within a single tenant.

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
- has many `Incident` (many-to-many)
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
- belongs to many `Incident` / `Problem`
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
- has many `Incident`
- has many `MajorIncidentUpdate` (timeline events and communications)

---

## 3. SLAs and workflows

### 3.1 SlaPolicy

Key fields:

- `id`
- `tenant_id`
- `name`
- `applies_to_queue_id` (nullable)
- `applies_to_priority` (nullable)
- `response_time_target` (e.g. minutes)
- `resolution_time_target`

Relationships:

- belongs to `Tenant`
- used by many `Incident`

### 3.2 WorkflowDefinition

Generic workflow definition for entities like incidents, changes, documents.

Key fields:

- `id`
- `tenant_id`
- `entity_type` (`incident`, `change`, `document`)
- `name`
- `definition_json` (states, transitions, guards)
- `is_default`

Relationships:

- belongs to `Tenant`
- used by many entities of `entity_type`

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
- `organization_id` (nullable)
- `department_id` (nullable)
- `asset_type_id`
- `name`
- `asset_identifier` (e.g. asset tag)
- `serial_number`
- `purchase_date`
- `warranty_date`
- `lifecycle_status` (e.g. `in_use`, `in_stock`, `retired`)
- `owner_user_id` (nullable)

Relationships:

- belongs to `Tenant`, `AssetType`
- belongs to `Organization` (optional)
- belongs to `User` as owner (optional)
- belongs to many `Incident`, `Change`, `Event`
- has many `AssetRelationship` as parent/child

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
- belongs to many `Incident`

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
- may link to `Incident`, `Change`, `Risk`, `ImprovementAction`

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

## 9. Billing and subscriptions

### 9.1 Subscription

Key fields:

- `id`
- `tenant_id`
- `stripe_customer_id` (nullable if manual)
- `stripe_subscription_id` (nullable)
- `billing_mode` (`per_seat`, `concurrent`, or mixed)

Relationships:

- belongs to `Tenant`
- has many `LicenseAssignment`
- has many `ConcurrentUsageSample`

### 9.2 LicenseAssignment

Represents per-user licensing (monthly/yearly).

Key fields:

- `id`
- `tenant_id`
- `user_id`
- `license_type` (`monthly`, `yearly`)
- `starts_at`
- `ends_at` (nullable)

Invariants:

- Transferring yearly license: link new assignment to same yearly license bundle if needed.

### 9.3 ConcurrentUsageSample

Hourly snapshot of concurrent active users.

Key fields:

- `id`
- `tenant_id`
- `sampled_at` (hour)
- `active_user_count`

Used to compute **daily peak active users** for metered billing to Stripe.

### 9.4 MeteredUsageExport

Key fields:

- `id`
- `tenant_id`
- `date`
- `peak_active_users`
- `exported_at`
- `stripe_metered_usage_id` (response from Stripe)

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
- `context_type` (`incident_suggestion`, `kb_answer`, etc.)
- `created_at`

Used for:

- internal monitoring
- tenant billing when using HelixDesk-managed AI.

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


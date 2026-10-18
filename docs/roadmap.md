# HelixDesk roadmap

This file is the **working product roadmap**: what is shipped, what is next, and what remains aspirational. It complements the requirements depth in [`project-description.md`](project-description.md) and the technical choices in [`technical-architecture.md`](technical-architecture.md).

**How to use it:** Update checkboxes and short notes as milestones land. Prefer linking to issues/PRs in your tracker when this repo is mirrored.

**Status legend**

| Mark | Meaning |
|------|---------|
| `[x]` | Delivered in this codebase at a useful level (may still need polish). |
| `[~]` | Partially there: scaffolding, CRUD, or API exists; workflows or ops story incomplete. |
| `[ ]` | Not started or explicitly deferred. |

---

## Phase A — Platform core (tenant, identity, RBAC)

- [x] Multi-tenant data model (`tenant_id`, tenant isolation patterns)
- [x] Filament **Agent** and **End-user** panels with tenancy (`Tenant` as Filament tenant)
- [x] **Global admin** panel for tenant lifecycle (separate from tenant staff)
- [x] Organizations (customer hierarchy) and user types (agent, end_user, global_admin)
- [x] Queues for routing and incident scope
- [x] Spatie **roles** with team scoping (`TenantRole`, `team_id` = tenant)
- [x] Config-driven `{module}.{action}` permissions and role matrix UI (**Administration → Roles**)
- [x] **Per-queue work item permissions** on `role_queue` (`work_item_permissions` JSON); global **`work_item.admin`** for full bypass
- [ ] **Teams** as first-class org units (if required beyond current model): CRUD, reporting, optional linkage to queues/workflows
- [~] **SSO / Entra**: synced users sign in with Microsoft through their organization's Entra integration ([`docs/integrations.md`](integrations.md) §9.2); other identity providers still open
- [ ] **Google Workspace Gmail API** mail (service account + domain-wide delegation) — [`docs/google-workspace-mail.md`](google-workspace-mail.md)

---

## Phase B — ITIL core (service desk)

- [x] **Work items (tickets)**: Filament (agent + end-user), `type` enum, default workflow, API v1, queue-scoped authorization
- [x] **Email → work_items**: inbound polling, threaded replies, outbound agent replies from queue mailbox
- [x] **Problems**: Filament resource, permissions, baseline model
- [x] **Changes**: Filament resource, workflow service, permissions, tests
- [x] **Ticket statuses catalog** + admin UI; default statuses via `TenantObserver` (New, Assigned, Working, Waiting for customer, Replied, Closed (pending), Closed)
- [x] **Ticket automations**: multi-action rules (set status, create ticket, send customer email); seeded customer-reply rules; admin UI
- [x] **SLA policies** + matching (priority / customer / category / type), pause on waiting statuses, timers/breach jobs, admin UI
- [ ] **Major work_items**: dedicated entity/workflows/UI (vs normal work_items only)
- [ ] Richer **knowledge base** integration (published docs ↔ work_items) if distinct from current Documentation module

---

## Phase C — CMDB & documentation

- [~] **Assets** / CMDB: models, relationships, Filament, API v1; multi-source identity, patch/software satellites, ambiguity resolution
- [x] **Service catalog**: `Supplier`, `ServiceOffering`, pivots to assets/suppliers, config-driven kinds/roles ([`docs/service-catalog.md`](service-catalog.md))
- [~] **Service offerings + software** (design: [`docs/service-offerings-and-software.md`](service-offerings-and-software.md)): multi-org assignment; `SoftwareProduct` / `SoftwareDeployment`; rich `SoftwareLicense`; partner vs customer docs UX (Filament + models; dedicated software REST API still open)
- [~] **Documents**: Filament, API v1, links to work_items, **assets, suppliers, service offerings** (extend morphs to software product/deployment/license)
- [x] **Microsoft Entra sync**: users, departments, Intune devices → `Asset`, security → `MonitoringEvent` (via `organization_integrations`)
- [x] **Multi-source asset integration** (design: [`docs/integrations.md`](integrations.md)):
  - [x] Phase 1: `organization_integrations` table + migrate from org JSON; CMDB satellite tables; identity resolution (serial + MAC/hostname); tenant auto-retire setting; retirement activity logging
  - [x] Phase 2: Entra refactor (dual IDs, stop overloading `asset_identifier`)
  - [x] Phase 3: Action1 (US/EU endpoints, group scoping, endpoints, software, normalized patch items, alert poll)
  - [x] Phase 4: Filament UI + API includes + `monitoring_event_asset` pivot
- [ ] **Deeper CMDB**: lifecycle policies, compliance views beyond patch snapshot
- [ ] **Documentation approval workflows** (draft → review → publish) per product spec

---

## Phase D — Monitoring & operations

- [~] **Monitoring events**: ingestion service, API, Filament, sources
- [ ] **Processing**: deduplication, correlation, auto-create/update work_items or problems, routing rules
- [ ] **Operator runbooks** (link events ↔ docs ↔ changes)

---

## Phase E — Dashboards & tenant configuration

- [~] **Dashboard layouts** model, Filament resource, seeded role defaults
- [ ] **Widget library** and real KPIs (SLA, backlog, major work_items, monitoring summary)
- [ ] **End-user** dashboard needs (if any) vs agent-focused layouts

---

## Phase F — API, integrations, background processing

- [~] REST **API v1** for core entities (tenants, work_items, assets, documents, monitoring events)
- [ ] **OpenAPI** (or equivalent) published and kept in sync with routes
- [ ] **Laravel Horizon** + **Redis** in deployment and `composer.json` / ops docs (architecture assumes them)
- [x] **Ticket email channel**: mail accounts, IMAP/POP3/Graph inbound polling, SMTP/Graph outbound, queue default address, attachments ([`docs/email-handling.md`](email-handling.md))
- [x] **Organization integrations**: Entra + Google Workspace + Action1 in `organization_integrations`; multi-source CMDB per [`docs/integrations.md`](integrations.md)
- [ ] **Queue-heavy jobs**: major-incident / status notifications, reporting, AI tasks (work item reply mail uses Horizon)
- [ ] **S3-compatible storage** for attachments/exports (config + implementation paths)

---

## Phase G — Security, compliance, AI (later)

- [ ] **Password vault** (encrypted storage, scoped access, audit) per requirements
- [ ] **MFA** enforcement for sensitive actions (e.g. vault) per requirements
- [ ] **Laravel AI** integration layer + usage logging (optional provider wiring)
- [ ] **ISO-oriented modules** (non-conformance, risk, improvement actions) as sketched in `project-description.md`

---

## Explicitly out of scope (this repo)

- [ ] **Subscription billing**, Stripe, metered usage (see `project-description.md` / product boundary)

---

## Suggested next steps (order of execution)

1. **Stabilize**: expand automated tests across problems, changes, assets, documents, monitoring; fix gaps found in manual QA.
2. **Monitoring Phase D**: close the loop from *event ingested* → *actionable ticket/workflow*.
3. **Dashboards Phase E**: ship a small set of high-value widgets tied to real queries.
4. **Service offerings + software** (Phase C): implement [`service-offerings-and-software.md`](service-offerings-and-software.md) when documentation/CMDB depth is the priority.
5. **Operations**: add Horizon + Redis + storage to default deployment story so architecture matches `technical-architecture.md`.
6. **API docs**: OpenAPI for v1 to support integrations.
7. **Major work_items & SLA** when service-desk depth is the priority.

---

*Last updated: 2026-07-29 — service offerings / software product design approved.*

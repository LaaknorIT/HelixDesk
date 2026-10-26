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
- [x] **Laravel Horizon** + **Redis** in deployment and `composer.json` / ops docs: Docker Compose stack and [`docs/installation.md`](installation.md)
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

## Prioritized delivery plan (2026-10)

This plan sets the order of work for making HelixDesk the preferred open source tool for IT departments and MSPs. It is grouped into three priority levels. Priority 1 is split into four releases, each one something users notice. Items that already exist in the phases above are named here by their phase.

### Priority 1 — Required to compete

**Release 1 — "Get started"**

- [x] **Docker image + docker-compose** with Redis and Horizon, and an installation guide (also closes the Horizon/Redis item in Phase F): `Dockerfile`, `compose.yaml`, image published to `ghcr.io/laaknorit/helixdesk`, [`docs/installation.md`](installation.md)
- [x] **Notifications**: new ticket, assigned, customer reply, SLA close to breach. Channels: email, in-app, Microsoft Teams, Slack ([`docs/notifications.md`](notifications.md))
- [ ] **Agent productivity**: canned responses, merge and link tickets, watchers/CC, collision warning when another agent is working on a ticket
- [ ] **MFA (TOTP)** for agents (Phase G)

**Release 2 — "MSP pack"**

- [ ] **Time tracking** on tickets: billable / non-billable, hourly rate, hours
- [ ] **Customer agreements**: prepaid hours, fixed price, SLA per agreement
- [ ] **Billing data export** (CSV + API, later Tripletex / Fiken / PowerOffice). HelixDesk exports what the MSP bills its customers; billing for HelixDesk itself stays out of scope
- [ ] **Recurring tickets** (e.g. monthly patch review, backup test per customer)

**Release 3 — "Self-service"**

- [ ] **Knowledge base** in the end-user portal, with article suggestions when a ticket is created (Phase B; `project-description.md` §8.1)
- [ ] **Request forms** from the service catalog: custom fields and manager approval
- [ ] **Customer satisfaction (CSAT)** survey after a ticket is closed
- [ ] **Customer portal for MSP customers**: own tickets, assets, licenses and monthly report

**Release 4 — "Insight and operations"**

- [ ] **Reports and dashboard widgets**: SLA attainment, backlog, ticket age, time per customer (Phase E)
- [ ] **Monitoring → ticket**: deduplication, correlation, rules that create or update tickets and problems (Phase D)
- [ ] **Webhooks** and a published **OpenAPI** spec for API v1 (Phase F)

### Priority 2 — Differentiators

- [ ] More **RMM integrations**: Tactical RMM (open source), NinjaOne, Datto RMM, Microsoft Defender
- [ ] **Password vault** per customer, with access control and audit log (Phase G; `project-description.md` §12)
- [ ] **Contract and license alerts**: expiry of software licenses, warranties and supplier agreements
- [ ] More **SSO providers**: Google, generic OIDC/SAML (Authentik, Keycloak)
- [ ] **Norwegian and multi-language UI** (i18n)

### Priority 3 — Later

- [ ] **AI agent assist**: reply suggestions, thread summaries, auto-categorization and prioritization, with support for local models so data can stay in the EU (Phase G; §14)
- [ ] **Major incidents** with status updates to affected customers (Phase B; §6.4)
- [ ] **Public status page** per tenant or customer, linked to major incidents
- [ ] **Documentation approval workflow** (draft → review → publish) (Phase C; §8.4)
- [ ] **ISO modules**: non-conformance, risk, improvement actions, linked to problems and changes (Phase G; §11)
- [ ] **Change calendar and CAB approval** with maintenance windows per customer
- [ ] **Mobile-friendly agent view / PWA** for on-call and field work
- [ ] **S3-compatible storage** for attachments and exports (Phase F)

Ongoing alongside all releases: expand automated tests across problems, changes, assets, documents and monitoring, and fix gaps found in manual QA.

---

*Last updated: 2026-10-26 — agent notifications delivered (Release 1).*

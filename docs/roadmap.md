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
- [x] Config-driven `{module}.{action}` permissions and role matrix UI (**Settings → Roles**)
- [x] **Per-queue incident permissions** on `role_queue` (`incident_permissions` JSON); global **`incident.admin`** for full bypass
- [ ] **Teams** as first-class org units (if required beyond current model): CRUD, reporting, optional linkage to queues/workflows
- [ ] **SSO / Entra** and tenant-level IdP configuration (spec item; not wired in app yet)

---

## Phase B — ITIL core (service desk)

- [x] **Incidents**: Filament (agent + end-user), default workflow, API v1, queue-scoped authorization
- [x] **Problems**: Filament resource, permissions, baseline model
- [x] **Changes**: Filament resource, workflow service, permissions, tests
- [ ] **Major incidents**: dedicated entity/workflows/UI (vs normal incidents only)
- [ ] **SLA / priority automation** (rules, timers, escalation) beyond static priority field
- [ ] Richer **knowledge base** integration (published docs ↔ incidents) if distinct from current Documentation module

---

## Phase C — CMDB & documentation

- [~] **Assets** / CMDB: models, relationships, Filament, API v1
- [~] **Documents**: Filament, API v1, links to incidents
- [ ] **Deeper CMDB**: discovery feeds, lifecycle policies, compliance views
- [ ] **Documentation approval workflows** (draft → review → publish) per product spec

---

## Phase D — Monitoring & operations

- [~] **Monitoring events**: ingestion service, API, Filament, sources
- [ ] **Processing**: deduplication, correlation, auto-create/update incidents or problems, routing rules
- [ ] **Operator runbooks** (link events ↔ docs ↔ changes)

---

## Phase E — Dashboards & tenant configuration

- [~] **Dashboard layouts** model, Filament resource, seeded role defaults
- [ ] **Widget library** and real KPIs (SLA, backlog, major incidents, monitoring summary)
- [ ] **End-user** dashboard needs (if any) vs agent-focused layouts

---

## Phase F — API, integrations, background processing

- [~] REST **API v1** for core entities (tenants, incidents, assets, documents, monitoring events)
- [ ] **OpenAPI** (or equivalent) published and kept in sync with routes
- [ ] **Laravel Horizon** + **Redis** in deployment and `composer.json` / ops docs (architecture assumes them)
- [ ] **Queue-heavy jobs**: outbound notifications, imports, reporting, AI tasks
- [ ] **S3-compatible storage** for attachments/exports (config + implementation paths)

---

## Phase G — Security, compliance, AI (later)

- [ ] **Password vault** (encrypted storage, scoped access, audit) per requirements
- [ ] **MFA** enforcement for sensitive actions (e.g. vault) per requirements
- [ ] **Laravel AI** integration layer + usage logging (optional provider wiring)
- [ ] **ISO-oriented modules** (non-conformance, risk, improvement actions) as sketched in `project-description.md`

---

## Explicitly out of scope (this repo)

- [ ] **Subscription billing**, Stripe, metered usage (see `project-description.md` / AGPL product boundary)

---

## Suggested next steps (order of execution)

1. **Stabilize**: expand automated tests across problems, changes, assets, documents, monitoring; fix gaps found in manual QA.
2. **Monitoring Phase D**: close the loop from *event ingested* → *actionable ticket/workflow*.
3. **Dashboards Phase E**: ship a small set of high-value widgets tied to real queries.
4. **Operations**: add Horizon + Redis + storage to default deployment story so architecture matches `technical-architecture.md`.
5. **API docs**: OpenAPI for v1 to support integrations.
6. **Major incidents & SLA** when service-desk depth is the priority.

---

*Last updated: 2026-04-12 — revise this line when you edit the roadmap.*

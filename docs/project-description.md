# **Requirements Specification – HelixDesk**

> **Distribution:** HelixDesk is **open source** under the **GNU Affero General Public License v3 or later (AGPL-3.0-or-later)**. This repository does **not** include subscription billing, payment processing, or Stripe/Cashier integration; see **§16**.

## **1. Product overview**

**HelixDesk** is a multi-tenant ServiceDesk platform designed for:

- IT departments  
- Managed Service Providers (MSP)

The system must support key processes from:

- **ITIL v4**  
- **ISO 9001**  
- **ISO 27001**

Main purposes:

- Efficient handling of work_items and support  
- Structured IT operations and asset management  
- Documentation and knowledge sharing  
- Automation and AI-assisted support  
- Continuous improvement and reporting  

---

## **2. System architecture**

### **2.1 Technology stack**

**Backend:**

- Laravel v12+  
- Laravel Horizon  
- Laravel AI SDK (optional integrations)

**Frontend:**

- Livewire v4+  
- Tailwind CSS  
- Filament

**Infrastructure:**

- Object storage: S3-compatible bucket (e.g. EU region)

---

### **2.2 Architecture requirements**

The system must:

- be **multi-tenant** (one tenant = one MSP or one IT department)  
- support an **API-based architecture**  
- be **queue-driven for heavy processes**  
- support **horizontal scaling**  
- have extensive capabilities for integrations to and from AI  
- be deployable in EU regions only, with a path to future multi-region failover  

---

## **3. Multitenancy**

### **3.1 Tenant model**

- A **tenant** represents either:
  - one MSP, or  
  - one internal IT department.  
- Customers of an MSP will usually only have one MSP in charge, so a customer is **never shared across different tenants**.

Each tenant has:

- its own users (agents and end users)  
- its own customers/companies, departments, and users in a hierarchy  
- its own work_items  
- its own assets and CMDB relationships  
- its own documentation  
- its own configurations  
- its own operational and integration configuration  

**Identity providers (IdP):**

- A single HelixDesk tenant can be connected to **many different IdPs** (e.g. many Microsoft Entra tenants) at the same time.  
- IdPs are attached **in the customer hierarchy** (e.g. per customer/company and/or department), so different customers under an MSP can use different IdPs.  

**Data isolation:**

- `tenant_id` must exist on all relevant tables.  
- No data is ever shared across tenants.  

**Global admin access to tenants:**

- A global admin can add themselves to a tenant’s access list for a **time-limited period** as a self-service operation.  
- This action is fully logged (who, when, which tenant, justification if provided).  
- Global admins **must be hidden** from tenant admin and tenant UIs; tenant admins should not see or manage global admin accounts.  

**Authentication:**

- Support for MFA for all agents.  
- Support for SSO (e.g. Microsoft Entra) for login, with configuration at tenant/customer level.  

---

### **3.2 Tenant administration**

A tenant admin can:

- manage users within the tenant  
- set roles (including which incident queues each role may access)  
- configure dashboards (defaults per role/team)  
- configure tenant-level feature access and limits where the product supports them  
- manage documentation access and workflows  
- configure queues, teams, and workflows (including incident/change workflows and documentation approval workflows)  

When a tenant is created, it must be pre-populated with:

- a default **Tenant Admin** role (Spatie role name; seeded with `*` → all `{module}.{action}` permissions from `config/helixdesk_permissions.php`)  
- a default **Agent** role (subset of explicit `{module}.{action}` permissions from the same config)  

People who administer the tenant are **users with `type = agent`** who are assigned the **Tenant Admin** role (or another role granted `tenant.admin` for role/permission management). There is no separate `tenant_admin` user type.

Tenant admins can create, update, and delete additional custom roles within their tenant.

---

## **4. User types**

### **4.1 End user / Customer**

End users are users belonging to a customer company (served by an MSP or IT department).

End users can:

- register work_items  
- chat with support  
- read the knowledge base (subject to access rules, possibly public or authenticated)  
- see the status of their own cases  
- provide customer satisfaction feedback on resolved/closed cases  

Organizational hierarchy within a tenant:

- Tenant  
  - Company (customer)  
    - Department  
      - Users  

---

### **4.2 Agent / IT staff**

Agents work for the MSP or IT department.

Agents can:

- handle work_items  
- create and manage problems  
- handle changes  
- create and maintain documentation  
- use asset and CMDB data  
- view and customize dashboards (within limits set by tenant admin)  

Agents can belong to:

- **roles** (RBAC capabilities **and** incident queue scope via `role_queue`)  
- **teams** (for organizational and reporting structure)  

These distinctions must be documented in the design document:

- **Queues**: primary routing units for work-items/events/requests; tenant admins attach queues to **roles** so agents inherit queue visibility from their assigned roles.  
- **Teams**: logical organizational units (e.g. “Network team”, “Desktop team”).  

---

### **4.3 Global Admin**

The global admin role administers the overall platform deployment (not just a single tenant).

Global admins can:

- create, deactivate, and set tenants to read-only mode  
- manage tenant lifecycle and platform-wide defaults  
- monitor system usage and performance  
- configure default AI access and global AI providers  
- manage global integrations (e.g. global monitoring integrations, SMS/email providers, status page providers)  
- temporarily grant themselves access to a tenant (hidden from tenant admin), with full auditing  

---

## **5. Permissions and access control**

The system uses **RBAC (Role-Based Access Control)**.

Users receive capabilities by **assigning roles**. Each role carries `{module}.{action}` permissions and the **incident queues** that role may work in; there is no separate access-control group entity.

Permissions are named **`{module}.{action}`** (for example `problem.read`, `change.approve`, `tenant.admin`). Modules and their actions are declared in `config/helixdesk_permissions.php`. Tenant admins manage **roles** under **Settings → Roles**: each role has a **global permission matrix** for non-incident modules, and a separate **per-queue incident matrix** (add a queue, then set read/create/update/delete/admin for work_items in that queue only). **`work_item.admin`** remains a global capability for full incident access.

For incident access, **queue-scoped incident rights** on each role determine what agents can do:

- Each **queue** attached to a role carries a small matrix of incident actions (read, create, update, …).
- **Which work_items** a user sees is the union of queues where they have **read** (or they hold **`work_item.admin`**).

Example: a role may have read/update in `Support` but only read in `Sales`; agents with that role see both queues in the index, but can only update work_items in `Support`.

Tenant admins manage **queues** in the Agent panel (Settings) and configure **roles** (including queue attachments and matrices) under **Settings → Roles**. Effective incident access is the union of queue-specific rights from all roles assigned to the user.

Role capabilities remain **`{module}.{action}`** strings stored on the **`role_permissions`** pivot (no separate global `permissions` catalog table).

Examples:

| Permission        | Description |
| ----------------- | ------------------------ |
| `work_item.read`   | read work_items           |
| `work_item.update` | update work_items         |
| `change.approve`  | approve changes          |
| `document.update` | edit documentation       |
| `tenant.admin`    | manage roles & access    |

Access can also be controlled per:

- queue  
- document type  
- asset type  

Each tenant defines its own mapping of roles → permissions, starting from the default `Admin` and `Agent` roles.

Default role behavior:

- The default `Admin` role in a new tenant is seeded with **all available permissions** to simplify initial setup.  
- Permissions are still **individually assignable**; having `tenant.admin` (manage roles/permissions) **does not implicitly grant all other module permissions** unless those cells are also checked (or the role uses `*`).  
- Tenant admins can:
  - remove specific permissions from the `Admin` role, and/or
  - create custom admin-like roles with more restricted access, even if they retain the ability to change permissions.

---

## **6. ITIL modules**

### **6.1 Incident Management**

An incident can be created via:

- email  
- chat  
- phone  
- self-service portal  
- AI chatbot  
- monitoring events (automatic creation)  

Fields (non-exhaustive):

- `title`  
- `description`  
- `priority`  
- `category`  
- `status`  
- `assigned_agent`  
- `queue`  
- linked assets  
- linked problems  
- SLA information and timers  

Status workflow (default):

- New  
- Assigned  
- In Progress  
- Waiting for User  
- Resolved  
- Closed  

**Workflow customization:**

- The default workflow above is provided out of the box.  
- Tenants can customize workflows (e.g. add statuses, restrict transitions) with a sensible default configuration.  

**SLAs:**

- SLAs are **configurable per tenant** (e.g. per queue, per priority, per customer).  
- SLA configuration should support timers, breach conditions, and be used to drive:
  - escalation rules  
  - SLA breach indicators  
  - reporting and KPIs  

---

### **6.2 Problem Management**

A **problem** represents an *underlying cause of multiple work_items*, within a **single tenant**.

Functions:

- link work_items to a problem  
- root cause analysis  
- workaround description  
- permanent fix description  
- related knowledge base article(s)  

AI capabilities:

- suggest potential problems based on incident patterns within the same tenant  

Problems are **tenant-local**; they never span multiple tenants.

---

### **6.3 Change Management**

A change request must contain:

- description  
- risk assessment (linked to the Risk Management module, see section 11.2)  
- approval information  
- implementation plan  
- rollback plan  

Status workflow (default):

- Draft  
- Review  
- Approved  
- Scheduled  
- Implemented  
- Closed  

Approval model:

- Initial implementation: **single-approver model**.  
- The design must allow for future extension to **multi-step approval workflows** (e.g. CAB-style approvals) without breaking existing data.  

Planning and visualization:

- Provide a **Change Calendar** view to see when changes are scheduled across services, queues, and organizations.  
- Provide a **Kanban board** for changes, grouped by status (e.g. Draft, Review, Approved, Scheduled, Implemented, Closed) to support visual workflow and work-in-progress control.  

---

### **6.4 Major Incident Management**

A **major incident**:

- can link many related work_items  
- can send status updates to all affected stakeholders  

Functions:

- status broadcast  
- incident linking  
- timeline of events and communications  

Supported communication channels for major incident status:

- email  
- status page  
- SMS  
- webhooks (for integration with external tools)  

---

## **7. Inventory / Asset Management (CMDB)**

### **7.1 Asset types**

Examples:

- PC  
- Server  
- Network equipment  
- Application  
- License  

Example fields:

- `asset_id`  
- `serial_number`  
- `purchase_date`  
- `warranty_date`  
- `lifecycle_status`  
- `owner` (user, team, or customer)  
- linked work_items  

The system should support **full CMDB-style relationships**, such as:

- “Service A depends on Server B and Database C”  
- upstream/downstream dependencies  
- impact analysis on work_items and changes  

---

### **7.2 Automatic collection**

Assets can be imported from:

- agent software (HelixDesk agent – planned future component)  
- monitoring systems  
- API integrations  
- vendors  

Initial prioritized integrations:

- **Microsoft Intune**  
- **Zabbix**  

Backlog of other possible sources (not exhaustive):

- Jamf  
- other RMM tools  
- other monitoring systems (e.g. Prometheus, Datadog)  
- other MDM/endpoint management tools  

---

### **7.3 Lifecycle management**

The system must:

- analyze asset age and lifecycle state  
- suggest replacements/end-of-life renewals  
- provide budget notifications for upcoming replacements  

---

## **8. Documentation**

Three main documentation types:

### **8.1 Knowledge Base**

Audience: **end users** (customers).

Examples:

- How to install a printer  
- How to reset a password  

Knowledge base articles:

- can be **public** (unauthenticated) or restricted to authenticated users within a tenant  
- can be linked to work_items, problems, and services  

---

### **8.2 Helpdesk Guides**

Audience: **agents**.

Examples:

- Troubleshooting Outlook  
- Network troubleshooting playbooks  

---

### **8.3 IT Operations Docs**

Audience: **IT operations / engineering**.

Technical documentation:

- installation procedures  
- vendor information  
- architecture diagrams and descriptions  

Access can be controlled per:

- user  
- group  
- application / service  

---

### **8.4 Documentation workflows**

- The system must support **approval workflows** for documentation (e.g. Draft → In review → Published).  
- By default, **approval is turned off** (simple edit/publish flow).  
- Tenants can enable and configure documentation workflows per documentation type.  

---

## **9. Monitoring and Event Management**

The system must be able to receive events from:

- monitoring systems  
- APIs  
- webhooks  

Examples:

- server down  
- disk full  
- service unavailable  

Event handling:

- Events can automatically create work_items.  
- Events can be linked to existing work_items based on rules/correlation.  
- **Event deduplication is required** (e.g. suppressing repeated identical alerts within a window).  
- When the monitoring system detects that the condition is resolved, the event (and optionally its associated incident, based on configuration) can be **automatically closed**.  
- Event-driven work_items can be routed to **separate queues/teams** from normal support work_items (e.g. dedicated NOC/operations teams).  

---

## **10. Continual Improvement**

The system must support:

- improvement suggestions (e.g. improvements derived from work_items, problems, non-conformances)  
- analysis of work_items for trends and recurring issues  
- KPI tracking (e.g. SLA compliance, MTTR, CSAT, risk metrics)  

---

## **11. ISO-related modules**

### **11.1 Non-conformance**

Non-conformances are similar to work_items but used in the quality system (ISO 9001 context).

Examples:

- process breach  
- defect/error in delivery  

Non-conformances can be linked to:

- work_items  
- changes  
- risks  
- improvement actions  

---

### **11.2 Risk Management**

A risk object includes:

- description  
- probability  
- consequence  
- mitigation  

Risk scoring:

- A risk score is calculated as: **probability × consequence**.  
- Risks must be visualized in a **color-coded matrix** (risk heatmap) to support easy assessment.  

Risks can be linked to:

- changes (risk assessment of a change)  
- services and assets  
- processes and non-conformances  

---

### **11.3 Process Management**

The system must allow definition of:

- processes  
- roles  
- responsibilities  

Processes can be linked with:

- non-conformances  
- risks  
- improvement actions  

---

## **12. Password Manager**

Functions:

- encrypted storage of passwords and secrets  
- access control based on roles/asset/customer/team scope  
- audit logging  

Scoping:

- A password can be scoped to:
  - specific assets  
  - customers  
  - teams  
- Agents must also be able to see a **list of all passwords they personally have access to**, regardless of scope, with appropriate filtering.  

Encryption:

- Passwords must be encrypted with a **salt unique per tenant**.  
- Design must allow later introduction of approval workflows for password access, but this is **not required initially**.  

MFA behavior:

- The tenant admin can enforce **MFA** for agents before they can see passwords.  
- If MFA is enforced and the agent has not authenticated with MFA within the last **30 minutes**, a **new MFA challenge** must be triggered before showing passwords.  

Audit log must show:

- who opened the password  
- timestamp  
- reason (justification text if collected)  

---

## **13. Dashboards**

The agent dashboard must be **widget-based**.

Possible widgets include:

- my work_items  
- work_items in my queues  
- major work_items  
- monitoring alerts / events  
- SLA breaches  
- key KPIs (e.g. MTTR, open problems, open changes, risks)  

Tenant admin can:

- define standard dashboard layouts for roles/teams  
- lock specific widgets or dashboard sections (prevent changes by end users)  

---

## **14. AI functionality**

AI capabilities are built on **Laravel AI SDK** and must support **all AI platforms** supported by that SDK.

General principles:

- Prefer **self-hosted AI providers** where possible.  
- Under high load or if self-hosted AIs are unavailable, the system may **fallback to external providers**.  
- Tenants can:
  - bring their own AI keys, or  
  - use HelixDesk-provided AI services.  
- When tenants use HelixDesk AI:
  - AI usage must be **tracked as credits**.  
  - Usage must be available for **invoicing** per tenant.  

All AI-driven changes must be **logged** describing:

- what the AI suggested  
- what was applied (and by whom, if an agent confirmed)  

By default, AI should focus on **assistance** (suggestions), with **manual approval** from agents for actual changes.

---

### **14.1 User chat**

AI chatbot can:

- answer questions based on the knowledge base and documentation  
- create work_items on behalf of the end user  
- escalate to a human agent when needed  

---

### **14.2 Agent assist**

AI can:

- suggest solutions and troubleshooting steps  
- find similar historical work_items and problems  
- suggest creation or linking of a problem  
- help draft responses to customers and documentation articles  

---

### **14.3 Automatic linking**

AI can:

- link work_items to appropriate problems  
- suggest relevant knowledge base and documentation articles  

These actions should normally require **agent confirmation**.

---

### **14.4 AI prioritization**

Task priority for AI processing:

- **High priority**:
  - user interactions (e.g. live chat, agent assist suggestions)  
- **Low priority**:
  - batch analysis  
  - incident clustering  
  - trend analysis  

Queueing:

- Use Laravel Horizon with a **single queue** that supports **priority-based** job handling for different AI tasks.  

---

## **15. API**

All API endpoints must:

- be **tenant-scoped** (MSPs/IT departments do not span multiple tenants)  
- be authenticated (e.g. OAuth / token-based)  
- be documented with **OpenAPI/Swagger**  

REST API is provided for:

- mobile apps  
- integrations (monitoring, CMDB imports, ticketing, etc.)  

Example endpoints:

- `GET /api/work_items`  
- `POST /api/work_items`  
- `GET /api/assets`  
- `POST /api/events`  

Authentication:

- OAuth / tokens, with explicit tenant context in credentials or routing.  

---

## **16. Distribution, licensing, and optional modules**

**Licensing:** The HelixDesk **codebase** is distributed under **AGPL-3.0-or-later**. Network use of modified versions must comply with Affero obligations (see the license text).

**Payments:** In-repo **payment processing, subscriptions, Stripe, and Laravel Cashier are explicitly out of scope**. Operators may run the stack for internal use, offer separate commercial support or hosting, or maintain a compliant fork—without merging proprietary payment logic into the AGPL-covered core unless that code is also AGPL-compatible.

### **16.1 Extra modules (product scope, not payment-gated)**

The following remain **product goals** where implemented; they are **not** tied to in-app billing in this repository:

- **Project module** — portfolio and follow-up  
- **Requirements / tender / evaluation** — structured vendor comparison  
- **Sales module** — quotes, lifecycle budgets (especially for MSPs)  
- **Password administration** — extended vault features on top of core password management  

The boundary between **core** and **add-on** features should stay clear in technical design; enforcement via payment is **not** required here.

---

## **17. Logging and audit**

The system must log:

- user actions  
- changes in work_items, problems, changes, risks, non-conformances, and documentation  
- access to passwords and sensitive data  
- changes to permissions, roles, and tenant configuration  
- global admin access to tenants  

Audit log requirements:

- stored in the standard database (no special WORM storage required)  
- immutable at the application level (no in-place editing)  
- searchable and filterable  

Visibility:

- Tenant admins can see audit logs **for their own tenant**.  
- Some events may be **global-only** (visible only to global admins).  

---

## **18. Non-functional requirements**

**Performance:**

- UI response time \< 500 ms under normal load  
- AI responses \< 5 seconds for interactive use cases  

**Scalability:**

- support at least **1000 tenants**  
- expect **tens of active users per tenant**, targeting around **10,000 concurrent agents** overall  

**Security:**

- RBAC with least-privilege principles  
- audit logs for critical actions  
- encryption of sensitive data (including tenant-unique salt for passwords)  
- EU-only data residency for all primary and backup data  

**Backup:**

- daily backups  
- versioning in S3-compatible object storage (EU regions)  


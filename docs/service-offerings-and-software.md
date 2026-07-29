# Service offerings, software products, and deployments

This document designs how HelixDesk models **customer IT capabilities**, the **software that realizes them**, **partner vs customer documentation**, and **licenses**. It extends the layering in [`service-catalog.md`](service-catalog.md) and updates the entities in [`domain-model.md`](domain-model.md) §4.5+.

**Status:** Implemented in codebase (2026-07-29). Dedicated software REST API endpoints still planned.

---

## 1. Intent

Agents need to document and operate **what the customer needs**, not only inventory CIs.

Example:

- The MSP sells a commercial package such as “IT operations” (out of scope for this model).
- Under that engagement, a **customer capability** might be **Financial software**.
- That capability is realized by **ERP** and **payroll** products, each with a **deployment at that customer**, licenses, servers, and docs.

Naming:

| Everyday language | HelixDesk term |
|-------------------|----------------|
| Customer need / IT system we operate | **Service offering** |
| Shared product in the MSP catalog | **Software product** |
| That product at a specific customer | **Software deployment** |

This is **not** the MSP’s commercial service catalog (what the company sells). A service offering is a **customer-side capability** the tenant supports.

---

## 2. Layering

```text
Service offering  (Financial software)
        │
        │  many-to-many
        ▼
Software deployment  (Acme’s Visma ERP) ──► Licenses, Assets (servers), customer Documents
        │
        │  belongs to
        ▼
Software product  (Visma ERP) ──► partner Documents, optional Supplier
```

| Layer | Answers | Typical docs |
|-------|---------|--------------|
| **Service offering** | What capability does this customer (or set of orgs) need? | Capability overview, who owns it, linked runbooks |
| **Software product** | What product is this in our partner catalog? | Partner/CSP setup, vendor contacts, shared how-tos |
| **Software deployment** | How is this product configured **for this customer**? | Tenant IDs, admin contacts, local quirks, restore steps |
| **License** | What entitlements apply to this deployment? | SKU, seats, expiry, contract refs |
| **Asset** | Which CIs host or relate to this deployment? | Servers, VMs, appliances (existing CMDB) |

**Internet connectivity** and similar non-software offerings remain valid: they keep using **suppliers** and **assets** on the offering (see [`service-catalog.md`](service-catalog.md)). Software-heavy offerings add **deployments** as the primary realization path for applications.

---

## 3. Domain entities

### 3.1 Service offering (extended)

**Meaning:** A customer IT capability (e.g. “Office support”, “Financial software”).

**Organization scope (multi-select):**

- Replace the single required `organization_id` with a pivot **`organization_service_offering`**.
- Agents **explicitly** assign one or more organizations (`customer` and/or `group` nodes).
- **No inheritance:** attaching a group does **not** auto-apply to child customers. Child customers must be selected if they should appear in scope.
- The same customer may have **multiple** offerings of the **same `kind`** (e.g. two “Office support” offerings for different sites or contracts).

**Key fields** (unchanged in spirit):

- `id`, `tenant_id`
- `name`, `description`, `code`
- `kind` (`internet_connectivity`, `business_application_suite`, `custom`, …)
- `attributes_json` (kind-specific facts, e.g. `line_number`)

**Relationships:**

- belongs to many `Organization` via `organization_service_offering`
- belongs to many `Supplier` via `service_offering_supplier` (existing)
- belongs to many `Asset` via `service_offering_asset` (existing — circuits, CPE, etc.)
- belongs to many `SoftwareDeployment` via `service_offering_software_deployment` (new)
- documents via `document_links`

**Invariants:**

- All related rows share `tenant_id`.
- Pivot organizations must belong to the same tenant.
- At least one organization should be assigned for a useful offering (enforce in UI; allow empty only as draft if needed).

**Migration:** Existing `service_offerings.organization_id` rows migrate into `organization_service_offering`, then the column is dropped (or kept nullable deprecated until cutover).

---

### 3.2 Software product

**Meaning:** Tenant-wide catalog entry for a product reused across customers (Microsoft 365, Google Workspace, Visma, Adobe Acrobat, …).

**Key fields:**

| Field | Notes |
|-------|--------|
| `id`, `tenant_id` | Required |
| `name` | Display name |
| `vendor_name` | Optional free-text vendor label |
| `supplier_id` | Optional FK → `suppliers` when the commercial vendor is tracked |
| `category` | Optional string or enum hint (`productivity`, `erp`, `security`, …) |
| `description` | Optional |
| `partner_notes` | Optional short text for MSP-facing partner context (not a substitute for Documents) |
| `external_reference` | Optional partner portal / SKU family id |
| `is_active` | Soft-hide from pickers without deleting |

**Relationships:**

- belongs to `Tenant`
- belongs to `Supplier` (optional)
- has many `SoftwareDeployment`
- documents via `document_links` → **partner-level** documentation

**Invariants:**

- Unique `(tenant_id, name)` recommended (case-insensitive uniqueness in app validation).

There is **one** Microsoft 365 product row for the tenant. Partner/CSP configuration lives here (and on linked Documents), not duplicated per customer.

---

### 3.3 Software deployment

**Meaning:** A concrete use of a software product **at one organization** (normally a `customer` node).

**Key fields:**

| Field | Notes |
|-------|--------|
| `id`, `tenant_id` | Required |
| `software_product_id` | Required |
| `organization_id` | Required; who this instance belongs to |
| `name` | Display name (default: product name + org, editable) |
| `environment` | Optional: `production`, `test`, `dr`, `other` |
| `status` | e.g. `active`, `planned`, `retired` |
| `external_tenant_id` | Optional (Entra tenant id, Google customer id, …) |
| `admin_url` | Optional |
| `notes` | Optional short operational notes |
| `attributes_json` | Optional free-form facts |

**Relationships:**

- belongs to `Tenant`, `SoftwareProduct`, `Organization`
- belongs to many `ServiceOffering` via `service_offering_software_deployment`
- has many `SoftwareLicense`
- belongs to many `Asset` via `software_deployment_asset` (role: e.g. `hosted_on`, `related`, `identity_provider`)
- documents via `document_links` → **customer-level** documentation

**Invariants:**

- `organization.tenant_id` and `software_product.tenant_id` match the deployment’s `tenant_id`.
- The same product may have **many** deployments (one per customer, or multiple per customer for prod/test).
- One deployment may link to **multiple** service offerings (e.g. Microsoft 365 under both “Office support” and “Identity support”).
- One offering may link to **multiple** deployments (ERP + payroll under “Financial software”).

**Why not only Application assets?**  
Assets remain the CMDB inventory (servers, discovered installs). Deployments are the **documented operational instance** of a catalog product: stable for docs, licenses, and offering membership without overloading `Asset`.

---

### 3.4 Software license (dedicated rows)

**Meaning:** Entitlement documentation for a **deployment** (not a generic Asset of type License, though Assets may still represent physical media if needed).

**Key fields:**

| Field | Notes |
|-------|--------|
| `id`, `tenant_id` | Required |
| `software_deployment_id` | Required |
| `name` | Label (e.g. “M365 E3”, “Visma Payroll seats”) |
| `sku` / `product_code` | Optional |
| `license_type` | e.g. `subscription`, `perpetual`, `oem`, `trial`, `other` |
| `metric` | e.g. `users`, `devices`, `cores`, `unlimited`, `other` |
| `seat_count` | Nullable integer (purchased/entitled quantity) |
| `seats_used` | Nullable integer (documented usage; not auto-synced in v1) |
| `starts_on` | Nullable date |
| `expires_on` | Nullable date — primary expiry for renewals |
| `auto_renew` | Boolean |
| `cost_amount` / `cost_currency` | Optional documented cost |
| `billing_period` | Optional: `monthly`, `annual`, `other` |
| `vendor_contract_reference` | Optional |
| `purchase_order_reference` | Optional |
| `notes` | Optional |
| `attributes_json` | Optional extras |

**Relationships:**

- belongs to `Tenant`, `SoftwareDeployment`
- documents via `document_links` (optional — e.g. scanned contract)

**Invariants:**

- License always belongs to exactly one deployment (customer context).
- Partner-wide agreements that are not customer-specific may be documented on the **Software product** (Documents / notes) until a future “tenant-level agreement” entity is needed.

**Renewal UX (v1):** list/filter deployments or licenses by `expires_on` within N days; no separate billing module.

---

### 3.5 Assets and suppliers

Unchanged roles, clarified usage:

- **`service_offering_asset` / `service_offering_supplier`:** still used for non-software realization (connectivity, carriers, CPE).
- **`software_deployment_asset`:** links servers/VMs/IdP appliances to a deployment.
- **RMM `AssetSoftwareInstallation`:** discovered installed software on endpoints — informational; optional future link to `SoftwareProduct` by name matching (out of scope for v1).

---

## 4. Documentation model

### 4.1 Where documents attach

| Link target | Content focus |
|-------------|----------------|
| `SoftwareProduct` | Partner-level: CSP portal, shared architecture, vendor runbooks |
| `SoftwareDeployment` | Customer-level: this tenant’s config, contacts, restore, known issues |
| `ServiceOffering` | Capability overview spanning multiple deployments |
| `SoftwareLicense` | Contract PDFs, quotes (optional) |
| `Asset` / `Supplier` | Existing CMDB/vendor docs |

Polymorphic `document_links` gains morph targets for `SoftwareProduct`, `SoftwareDeployment`, and `SoftwareLicense`.

### 4.2 Agent UX principles

1. **Default context is the customer.** Opening a customer’s deployment (or offering for that customer) shows **customer documents** first.
2. **Partner docs are one click away**, not mixed into the primary list by default. Show a clear control such as “Partner / product documentation” that lists Documents linked to the parent `SoftwareProduct`.
3. From a product page, list **deployments** and deep-link into each customer’s docs.
4. Offering pages list linked deployments; each row emphasizes customer docs for that deployment.

No automatic copy of partner docs onto every deployment — **links and navigation**, not duplication.

---

## 5. Permissions and API

### 5.1 Permissions

Add module permissions alongside existing catalog RBAC (see `config/helixdesk_permissions.php`):

- `software_product.{read,create,update,delete}`
- `software_deployment.{read,create,update,delete}`
- `software_license.{read,create,update,delete}`

Service offering permissions (`service_offering.*`) remain; updating org multi-select and deployment links requires `service_offering.update`.

### 5.2 API v1 (planned)

- `GET/POST /api/v1/software-products`
- `GET/POST /api/v1/software-deployments`
- `GET/POST /api/v1/software-licenses` (or nested under deployments)
- Extend service offerings payloads with `organization_ids` and `software_deployment_ids`

---

## 6. Filament (Agent panel)

Suggested navigation (Infrastructure or Service management — final placement at implementation):

- **Service offerings** (existing resource — add multi-org assignment + deployments relation)
- **Software products** (new)
- **Software deployments** (new; filterable by organization / product / expiry)
- Licenses as relation manager on deployment (and optional standalone list for renewal views)

On **Organization** edit: relation managers for offerings (where org is in pivot), deployments, and related docs.

---

## 7. Worked examples

### 7.1 Office support — mixed products across customers

1. Create **Software products:** Microsoft 365, Google Workspace, Adobe Acrobat (partner docs on M365/Google).
2. Create **Service offering** “Office support” with orgs **Customer 1, 3, 4, 7** selected (multi-select). Separate offering or same kind for customers 5–6 if grouping differs.
3. Create **deployments:** M365 at customers 1, 3, 4, 7; Google Workspace at 5 and 6; Acrobat where needed.
4. Link deployments to the offering(s).
5. Add **licenses** (E3 seats, expiry) on each M365 deployment.
6. Agents working Customer 1 see Customer 1 M365 docs by default; partner CSP notes via product link.

### 7.2 Financial software — ERP + payroll

1. **Service offering** “Financial software” → organization Acme.
2. **Products** ERP X, Payroll Y.
3. **Deployments** Acme ERP, Acme Payroll; link both to the offering.
4. Link app servers via `software_deployment_asset`.
5. License rows for ERP named users and payroll employees; `expires_on` for renewals.

---

## 8. Implementation outline (when built)

1. Migrations: `software_products`, `software_deployments`, `software_licenses`, pivots `organization_service_offering`, `service_offering_software_deployment`, `software_deployment_asset`; migrate `service_offerings.organization_id`.
2. Eloquent models, factories, policies/permissions.
3. Filament resources + relation managers; document link morphs.
4. Feature tests: multi-org pivot, multi-offering deployments, license CRUD, document link targets.
5. API endpoints + OpenAPI when API docs are prioritized.
6. Update [`service-catalog.md`](service-catalog.md) examples to point here for software-heavy flows.

**Implemented (2026-07-29):** items 1–4 and service-offering API updates (`organization_ids`, deployment links). Dedicated software product/deployment/license REST endpoints remain planned (§5.2).

---

## 9. Explicitly out of scope (this design)

- MSP **commercial** offerings / packages (“we sell IT operations”) as a sellable catalog.
- Automatic sync of `seats_used` from Entra / Google / vendors.
- Inheritance of offerings from group → child organizations.
- Auto-matching RMM installed software to `SoftwareProduct`.

---

*Approved design direction: multi-select orgs, SoftwareProduct + SoftwareDeployment, multi-offering deployments, dedicated rich licenses, customer-docs-first UX with navigation to partner product docs.*

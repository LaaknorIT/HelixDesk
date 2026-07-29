# Service catalog and CMDB layering

This document explains how **customer capabilities** (`ServiceOffering`) sit above **configuration items** (`Asset`) and how **software products / deployments** and **documentation** attach. It implements the hierarchy in [`domain-model.md`](domain-model.md) §4.4–4.8.

For software-heavy offerings (shared products across customers, partner vs customer docs, licenses), see the full design: [`service-offerings-and-software.md`](service-offerings-and-software.md).

## Why these layers?

- **Service offering** — *what the customer needs* (a capability we operate): internet access, financial software, office support. **Not** the MSP’s commercial package (e.g. “IT operations”).
- **Software product** — *shared catalog product* (Microsoft 365) with **partner-level** documentation.
- **Software deployment** — *that product at a customer*, with **customer-level** docs, licenses, and server links.
- **Asset (CI)** — *inventory*: routers, circuits, servers, discovered installs.

Keeping these separate avoids overloading `Asset` with catalog semantics, partner docs, and seat/expiry tracking.

## Organization scope

Service offerings use **explicit multi-select** of organizations (`customer` and/or `group`) via `organization_service_offering`. Assigning a group does **not** inherit to child customers. The same customer may have multiple offerings of the same `kind`.

## Examples

### Internet connectivity

1. Create **Suppliers** (e.g. AT&T).
2. Create **Assets** for primary CPE (fiber), backup CPE (5G), and optional abstract “circuit” records if useful.
3. Create a **Service offering**:
   - **Organizations**: one or more customer (or group) nodes via multi-select.
   - **Kind**: `internet_connectivity`.
   - **Attributes** (JSON): at minimum `line_number` (enforced when kind requires it).
   - **Suppliers**: attach with pivot role `primary` (and `backup` if another carrier).
   - **Assets**: attach routers with roles `primary_access` and `backup_access` (see [`config/helixdesk_service_offering.php`](../config/helixdesk_service_offering.php)).

4. Link **Documents** (runbooks, contract summaries) to the offering, suppliers, and/or assets via the Agent panel.

### Financial software (ERP + payroll)

1. Create **Software products** for the ERP and payroll systems (partner docs on each product if useful).
2. Create **Software deployments** for the customer (Acme ERP, Acme Payroll); add **licenses** (seats, expiry) and link **servers** via `software_deployment_asset`.
3. Create a **Service offering**:
   - **Kind**: `business_application_suite` (or `custom`).
   - **Organizations**: Acme (multi-select allows more orgs if shared).
   - Link both **deployments** to the offering (many-to-many).
4. Agents default to **customer docs** on each deployment; navigate to **partner / product docs** when needed.

### Office support across customers (mixed vendors)

1. **Software products:** Microsoft 365, Google Workspace, Adobe Acrobat.
2. **Deployments** per customer (M365 for customers 1, 3, 4, 7; Google Workspace for 5 and 6).
3. **Service offering(s)** “Office support” with explicit org multi-select; link the relevant deployments.
4. Partner CSP notes live on the M365 **product**; per-tenant admin guides live on each **deployment**.

## Configuration

- **Kinds**, **required attribute keys**, and **suggested asset / supplier roles** per kind live in [`config/helixdesk_service_offering.php`](../config/helixdesk_service_offering.php).
- RBAC uses `supplier.*`, `service_offering.*`, and (when implemented) `software_product.*`, `software_deployment.*`, `software_license.*` in [`config/helixdesk_permissions.php`](../config/helixdesk_permissions.php).

## API

- `GET/POST /api/v1/suppliers`, `GET/POST /api/v1/suppliers/{supplier}`
- `GET/POST /api/v1/service-offerings`, `GET/POST /api/v1/service-offerings/{serviceOffering}`
- Planned: software products, deployments, licenses — see [`service-offerings-and-software.md`](service-offerings-and-software.md) §5.2

Payloads support nested `supplier_links` / `asset_links` on create/update where implemented in form requests; organization multi-select and deployment links to follow the same pattern.

---

*See also [`roadmap.md`](roadmap.md) Phase C and [`service-offerings-and-software.md`](service-offerings-and-software.md).*

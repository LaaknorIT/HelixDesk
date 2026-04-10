# Service catalog and CMDB layering

This document explains how **business services** (customer needs) sit above **configuration items** (`Asset`) and how **documentation** attaches at each level. It implements the hierarchy described in [`domain-model.md`](domain-model.md) §4.4–4.5.

## Why two layers?

- **Service offering** — *what* the customer relies on: outcomes and contracts (internet access, finance/HR capability).
- **Asset (CI)** — *what* delivers it: routers, circuits, applications, licenses.

Keeping both avoids overloading `Asset` with supplier roles, line IDs, and catalog semantics that are not inventory attributes.

## Examples

### Internet connectivity

1. Create **Suppliers** (e.g. AT&T).
2. Create **Assets** for primary CPE (fiber), backup CPE (5G), and optional abstract “circuit” records if useful.
3. Create a **Service offering**:
   - **Organization**: customer company.
   - **Kind**: `internet_connectivity`.
   - **Attributes** (JSON): at minimum `line_number` (enforced when kind requires it).
   - **Suppliers**: attach with pivot role `primary` (and `backup` if another carrier).
   - **Assets**: attach routers with roles `primary_access` and `backup_access` (see [`config/helixdesk_service_offering.php`](../config/helixdesk_service_offering.php)).

4. Link **Documents** (runbooks, contract summaries) to the offering, suppliers, and/or assets via the Agent panel.

### Financial and HR systems

1. Create **Assets** typed as **Application** (or equivalent) for the financial system and payroll system.
2. Create a **Service offering**:
   - **Kind**: `business_application_suite`.
   - Attach both applications with roles `financial_application` and `payroll_application`.
3. Link KB or IT docs to the offering and to each application.

## Configuration

- **Kinds**, **required attribute keys**, and **suggested asset / supplier roles** per kind live in [`config/helixdesk_service_offering.php`](../config/helixdesk_service_offering.php).
- RBAC uses `supplier.*` and `service_offering.*` next to `asset.*` in [`config/helixdesk_permissions.php`](../config/helixdesk_permissions.php).

## API

- `GET/POST /api/v1/suppliers`, `GET /api/v1/suppliers/{supplier}`
- `GET/POST /api/v1/service-offerings`, `GET /api/v1/service-offerings/{serviceOffering}`

Payloads support nested `supplier_links` / `asset_links` on create/update where implemented in form requests.

---

*See also [`roadmap.md`](roadmap.md) Phase C.*

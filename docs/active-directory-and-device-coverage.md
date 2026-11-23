# Active Directory integration and device coverage report

**Status:** accepted 2026-11-22; implementation in progress (see §7).

This document designs two related features:

1. An **on-premises Active Directory (AD) integration** that brings computer (and later user) objects from AD into HelixDesk, even though AD is normally not reachable from the internet.
2. A **device coverage report** that compares what each source knows about, so agents can see gaps such as *active AD computers that are not in Intune* (often a missing license or a failed enrollment) or *Intune devices without an Action1 agent*.

It builds on the multi-source CMDB in [`integrations.md`](integrations.md) (`organization_integrations`, `asset_external_identifiers`, `AssetReconciliationService`), the integration gallery and health warnings (`IntegrationCatalog`, `IntegrationHealthInspector`), and the planned Sanctum tokens in [`widgets-and-companion-apps.md`](widgets-and-companion-apps.md).

**Related docs:** [`integrations.md`](integrations.md) §4–§6 and §12, roadmap issues [#40](https://github.com/LaaknorIT/HelixDesk/issues/40) and [#41](https://github.com/LaaknorIT/HelixDesk/issues/41), [`technical-architecture.md`](technical-architecture.md).

---

## 1. Goals and non-goals

### 1.1 Goals

- Read AD computer objects per customer organization, without opening AD to the internet and without storing domain admin credentials in HelixDesk.
- Treat every place a device can be found as its **own source**: Active Directory, Entra ID (device objects), Intune, Microsoft Defender for Endpoint, and Action1. Intune and Defender are shown separately, even though both come from Microsoft.
- Show per device which sources see it, and whether each source considers it **active**.
- Turn missing coverage into actionable findings with a likely cause, a count on the integration health page, and optional tickets.
- Work for both deployment models: HelixDesk in the cloud (MSPs) and HelixDesk self-hosted on the customer's network (IT departments).

### 1.2 Non-goals (this design)

- Writing to AD (creating, moving or disabling objects).
- Replacing Entra Connect / Entra Cloud Sync. HelixDesk reads; it does not sync identities into Entra.
- Group Policy, BitLocker key or LAPS password collection. LAPS may come later together with the password vault (Phase G).
- License purchasing. The report points out the gap; buying the license happens elsewhere.

---

## 2. Active Directory integration

### 2.1 Options for reaching AD

| Option | How it works | Pros | Cons |
| ------ | ------------ | ---- | ---- |
| **A. On-prem collector (push)** | A small collector on a domain-joined machine reads AD over LDAP and pushes snapshots to the HelixDesk API over HTTPS. | Only outbound HTTPS (443). No inbound firewall rules, no VPN. No AD credentials leave the customer network; the collector runs as a low-privilege account. Works for cloud and self-hosted. | Something must be installed and kept running at each customer. |
| **B. Direct LDAPS (pull)** | HelixDesk connects to a domain controller over LDAPS (636) and reads AD itself. | Nothing to install. Simple for self-hosted HelixDesk on the same network. | From the cloud it needs a VPN or a published DC, which most customers will (rightly) refuse. Bind credentials are stored in HelixDesk. |
| **C. Via Entra Connect data** | Read hybrid-joined computers from Entra ID device objects (`onPremisesSyncEnabled = true`) using the existing Entra integration. | No new component. | Only shows computers that Entra Connect syncs (OU filtering) and that completed hybrid join. Servers are usually missing. No `lastLogonTimestamp`. Exactly the devices we want to find (not in Entra/Intune) are the ones it cannot see. |
| D. Run a script through the RMM | Use Action1 (or another RMM) to run an AD export script on a DC and upload the result. | Reuses an agent that is already there. | Ties AD to one RMM vendor, and running scripts on DCs through the RMM is hard to audit. |

### 2.2 Recommendation

**One integration type, `active_directory`, with two connection modes that share the same pipeline:**

- **`collector` (default, option A)** for all cloud installations and for MSPs.
- **`ldaps` (option B)** for self-hosted installations that can reach a DC directly.

Option C is **not** a replacement but is used as a **bridge**: Entra device objects carry `onPremisesSecurityIdentifier`, which equals the AD computer's `objectSid`. That gives a reliable hard match between AD, Entra ID and Intune for hybrid-joined PCs (§4.2). This requires the Entra integration to also read Entra device objects (§5.1).

Both modes produce the same `AssetProviderSnapshot` DTOs (integrations.md §5.1), so reconciliation, provenance and the coverage report do not care how the data arrived.

### 2.3 The collector

**Form, phase 1:** a signed PowerShell script (`HelixDesk.ADCollector.ps1`) run by a Scheduled Task as a dedicated read-only account (a normal domain user can read computer objects; a gMSA is recommended). It uses `System.DirectoryServices` so the RSAT AD module is not required. MSPs can deploy it with Action1 or GPO.

**Form, later:** a small .NET Windows service with an MSI, automatic updates and a local status page, if the script proves too limited.

The collector lives in a separate repository (like the companion apps), is MIT licensed, and is versioned independently. HelixDesk shows the collector version it last heard from and warns when it is outdated.

**Collector key (implemented):** after creating the AD integration, the agent chooses **Create collector key** on the integration page. HelixDesk shows the key once (`hdc_…`) and stores only its SHA-256 hash in the integration's encrypted credentials. The key is bound to **one** `organization_integrations` row and only works on that integration's collector endpoints; it cannot read anything else. *Replace collector key* invalidates the old key at once. This needs no extra package; Sanctum tokens remain the plan for user-facing API tokens.

**What it reads (computers, v1):**

| AD attribute | Used for |
| ------------ | -------- |
| `objectGUID` | Hard identifier (`AdObjectGuid`) |
| `objectSid` | Bridge to Entra device `onPremisesSecurityIdentifier` (`AdObjectSid`) |
| `name`, `dNSHostName` | Name, hostname hints (short name and FQDN) |
| `operatingSystem`, `operatingSystemVersion` | OS, device class (workstation / server) |
| `userAccountControl` | Enabled / disabled, DC flag (`SERVER_TRUST_ACCOUNT`) |
| `lastLogonTimestamp` | Activity (replicated, accurate to roughly 14 days) |
| `pwdLastSet` | Activity (machines rotate their password every 30 days by default) |
| `whenCreated`, `distinguishedName` | Age, OU (for filtering and OU → organization mapping) |
| `description`, `managedBy` | Shown on the asset; `managedBy` may map to owner |

Users and groups are a later scope (`scopes.users`) for organizations that do not use Entra; for hybrid organizations Entra stays the source of truth for people (integrations.md §6).

**Config (non-secret `config` JSON):**

| Key | Description |
| --- | ----------- |
| `mode` | `collector` or `ldaps` |
| `domain_fqdn` | e.g. `corp.example.com`. One integration per domain; several domains means several integrations |
| `search_bases` | OUs to include (default: domain root) |
| `excluded_ous` | OUs to skip (e.g. `OU=Disabled`) |
| `ou_organization_map` | Optional: OU → HelixDesk organization, for IT departments where one AD serves several departments/companies |
| `scopes.computers`, `scopes.users` | Sync toggles |
| `active_threshold_days` | Default `30` (§4.4) |
| `create_assets` | `active_only` (default), `all`, or `never` (§4.3) |

**Credentials (`credentials` column):** collector mode stores only `collector_key_hash` (plus the last four characters as a hint). LDAPS mode stores `bind_dn` and `bind_password`, plus an optional CA certificate for the DC.

### 2.4 Push API

```
GET  /api/v1/collectors/active-directory/{integration}/config                 → domain, search bases, excluded OUs, interval, batch size
POST /api/v1/collectors/active-directory/{integration}/runs                   → { run_id }
POST /api/v1/collectors/active-directory/{integration}/runs/{run}/objects     ← { sequence, objects: [...] } (max 200)
POST /api/v1/collectors/active-directory/{integration}/runs/{run}/complete    ← { object_count }
POST /api/v1/collectors/active-directory/{integration}/heartbeat              ← { collector_version, host, last_error }
```

All calls send `Authorization: Bearer <collector key>` and are rate limited (120 per minute).

- A run is a **full snapshot** (`collector_runs`). Only when `complete` arrives and HelixDesk has received `object_count` objects are computers that were not in the run marked gone. An interrupted or short run never removes anything; it is marked *incomplete* and shown as a sync error.
- Batches are processed in the request (at most 200 objects each), so `complete` always sees every stored object.
- A batch is idempotent on `(run, sequence)`: a retried batch is ignored.
- Starting a new run closes any run still open for the integration.

### 2.4.1 The PowerShell collector

`resources/collectors/active-directory/HelixDesk-ADCollector.ps1`, downloaded from the integration page with the HelixDesk URL and integration ID filled in (never the key).

```powershell
.\HelixDesk-ADCollector.ps1 -Install -CollectorKey 'hdc_...'          # once, as administrator
.\HelixDesk-ADCollector.ps1 -WhatIf                                   # read AD, print a summary, send nothing
.\HelixDesk-ADCollector.ps1 -Install -CollectorKey 'hdc_...' -RunAsUser 'CORP\gmsa-helixdesk$'
.\HelixDesk-ADCollector.ps1 -Uninstall
```

- `-Install` stores the key encrypted with DPAPI (LocalMachine scope) in `%ProgramData%\HelixDesk`, readable only by administrators, SYSTEM and the task account, and registers a scheduled task at the interval set in HelixDesk.
- By default the task runs as SYSTEM, which reads AD as the computer account. `-RunAsUser` runs it as a gMSA instead.
- It uses `System.DirectoryServices` (no RSAT module), reads with paging, and reports errors to HelixDesk through the heartbeat.

### 2.5 Health and the integration gallery

- New catalog entry in `IntegrationCatalog`: **Active Directory (on-premises)**, category *Identity & directory*, with requirements ("A domain-joined Windows machine that can reach HelixDesk over HTTPS") and a download link for the collector.
- New health issues in `IntegrationHealthInspector`:
  - **Error:** no heartbeat for more than `STALE_SYNC_MULTIPLIER` × interval ("The collector on SRV01 has not reported since …").
  - **Warning:** last run incomplete, collector outdated, token about to expire, LDAPS certificate about to expire.
- `IntegrationErrorExplainer` gets messages for common LDAP errors (bind failed, referral, certificate not trusted).

---

## 3. Device sources

### 3.0 Sources are registered, not hard-coded

HelixDesk will have many integrations, so neither the report nor the rules may name specific products. Instead:

- Each integration declares the **device sources** it produces in its `IntegrationCatalog` entry (`device_sources`). One integration can produce several sources: the Microsoft Entra integration produces *Entra ID*, *Intune* and *Defender for Endpoint*.
- Each source has a stable **key** (stored as `asset_source_records.source`), a label, and one or more **roles** (`App\Enums\DeviceSourceRole`):

| Role | Meaning | Examples |
| ---- | ------- | -------- |
| `directory` | The device is joined to / known by a directory | Active Directory, Entra ID |
| `device_management` | The device is enrolled in MDM | Intune, Jamf, Google endpoint management |
| `remote_management` | An RMM / patch agent is installed | Action1, Tactical RMM, NinjaOne, Datto RMM |
| `endpoint_security` | An EDR / antivirus agent is onboarded | Defender for Endpoint, SentinelOne, CrowdStrike |

- A sync service only has to put a `DeviceSourceRecordData` on the `AssetProviderSnapshot` it already builds. `AssetReconciliationService` stores it in `asset_source_records`, linked to the asset it resolved (or unlinked). Nothing in the report changes when a provider is added.
- Report columns are the sources that are enabled for the organization (from `organization_integrations` and the source's scope). Built-in rules (§5.3) are written against **roles**, so a new RMM is checked by "devices in device management must have remote management" without any change.

The sources HelixDesk knows today or in this design:

| Source | Integration type | What it proves | Activity signal | Identifiers |
| ------ | ---------------- | -------------- | --------------- | ----------- |
| Active Directory | `active_directory` (new) | The computer is domain joined | `lastLogonTimestamp`, `pwdLastSet` | `AdObjectGuid`, `AdObjectSid`, hostname |
| Entra ID (devices) | `microsoft_entra` (new scope `devices`) | Entra joined / hybrid joined / registered | `approximateLastSignInDateTime` | `EntraDeviceId`, `EntraAzureAdDeviceId`, `onPremisesSecurityIdentifier` |
| Intune | `microsoft_entra` (scope `computers`, exists) | Managed by Intune (MDM) | `lastSyncDateTime` | `EntraIntuneManagedDeviceId`, `EntraAzureAdDeviceId`, serial |
| Defender for Endpoint | `microsoft_entra` (new scope `defender_devices`) | Onboarded to Defender (EDR/AV) | `lastSeen` | `DefenderMachineId`, `aadDeviceId`, hostname, MAC |
| Action1 | `action1` (exists) | RMM / patch agent installed | `last_seen` | `Action1EndpointId`, serial, MAC, hostname |

Future sources plug into the same registry: Tactical RMM, NinjaOne, Datto RMM ([#39](https://github.com/LaaknorIT/HelixDesk/issues/39)), Google Workspace endpoint management (ChromeOS and Windows devices), Jamf.

### 3.1 Microsoft Defender for Endpoint

*Decided 2026-11-22:* Defender uses the **same app registration and credentials** as the Microsoft Entra integration. There is no separate application in Entra for the same read access.

- New scope `defender_devices` on the `microsoft_entra` integration. It reads `GET /api/machines` from the Defender API (`api.security.microsoft.com`, application permission `Machine.Read.All` on the *WindowsDefenderATP* API, added to the existing app registration).
- It produces its **own source**, `microsoft_defender`, with role `endpoint_security`, so it is shown and checked separately from Intune even though it comes from the same integration row.
- In the integration gallery and on the integration page, *Security incidents* and *Defender devices* are grouped under **Microsoft Defender**, separate from the directory and Intune scopes. The security incident ingest itself stays where it is in code; only the presentation moves.
- Defender also reports **discovered but not onboarded** devices (`onboardingStatus = CanBeOnboarded`). These are stored as source records but never create assets on their own.
- Permission checks in `MicrosoftEntraIntegrationCatalog::runtimePermissionRequirements()` get the Defender permission, so a missing consent shows up as a health warning like the other scopes.

---

## 4. Data model

### 4.1 `asset_source_records` (new)

Today a provider record exists only as `asset_external_identifiers` rows plus merged fields on `assets`. That is not enough for the report: we need **one row per device per source**, with the source's own view of activity, and we need it even when a record is not linked to an asset.

| Column | Type | Notes |
| ------ | ---- | ----- |
| `id` | bigint PK | |
| `tenant_id`, `organization_id` | FK | Tenant scoped, like all CMDB tables |
| `organization_integration_id` | uuid FK | |
| `source` | string | Registered source key (§3.0): `active_directory`, `microsoft_entra_device`, `microsoft_intune`, `microsoft_defender`, `action1`, … |
| `provider_record_id` | string | The source's own ID (objectGUID, Intune id, Defender machine id, …) |
| `asset_id` | FK nullable | Null when the record is not linked to an asset (§4.3) |
| `display_name`, `hostname`, `fqdn`, `serial_number`, `mac_addresses` | strings / JSON | Normalized with `AssetIdentityNormalizer` |
| `operating_system`, `device_class` | string | `device_class`: `workstation`, `server`, `mobile`, `other` |
| `enabled` | bool nullable | AD `userAccountControl`, Entra `accountEnabled` |
| `last_activity_at` | timestamp nullable | The source's own activity time (table in §3) |
| `first_seen_at`, `last_reported_at` | timestamps | When HelixDesk first and last received the record |
| `gone_at` | timestamp nullable | Set when a complete run or sync no longer reports it |
| `metadata_json` | JSON | OU, join type, onboarding status, agent version, … |

Unique `(tenant_id, organization_integration_id, source, provider_record_id)`.

Note that `asset_external_identifiers.last_seen_at` is the time HelixDesk last *synced* the identifier, not when the device was last *active*. The report needs `last_activity_at` from the source.

Entra ID, Intune and Defender come from the same integration row but are stored with different `source` values, so they appear as separate sources.

### 4.2 New identifier types

Added to `AssetExternalIdentifierType`:

| Case | Hard match | Notes |
| ---- | ---------- | ----- |
| `AdObjectGuid` | yes | Stable for the lifetime of the AD object |
| `AdObjectSid` | yes | Bridge to Entra `onPremisesSecurityIdentifier` |
| `DefenderMachineId` | yes | |
| `Fqdn` | no | Soft hint; stronger than short hostname |

Bridges added to the matching order (integrations.md §5.2, step 2 "cross-provider bridge"):

- AD `objectSid` = Entra device `onPremisesSecurityIdentifier` → same asset. Entra device `deviceId` = Intune `azureADDeviceId` → same asset. Together they chain **AD ↔ Entra ↔ Intune** without relying on names.
- Defender `aadDeviceId` = `EntraAzureAdDeviceId` → same asset.
- Action1 already bridges on `EntraAzureAdDeviceId` when it reports one.

### 4.3 How AD records become assets

AD has no serial number or MAC address, so the serial + corroborating identifier rule (integrations.md §5.2 step 3) can never match an AD record. Without care, AD would create duplicates of devices that Intune or Action1 already created.

Rules:

1. Link through the hard bridges in §4.2 when possible.
2. Otherwise, link when exactly **one** asset in the organization has the same computer name (short hostname, or the start of its FQDN). A computer name is unique within a domain, and MDM/RMM sources usually report the short name, so requiring the FQDN would almost never match. Two or more candidates link nothing (`matchByUniqueHostname` on the snapshot). Other providers keep the strict rule: hostname alone never merges.
3. Otherwise, depending on `create_assets`:
   - `active_only` (default, decided 2026-11-22): create an asset only for **enabled and active** computers. Stale and disabled objects stay as unlinked source records, visible in the report but not cluttering the CMDB.
   - `all` / `never`: as named.
4. An asset that was created from AD alone (AD is its only source) may later be **merged** with a provider that reports the same FQDN, because there is nothing on the AD-only asset that could conflict. Merges are logged in provenance and can be undone from the ambiguity screen.
5. Two or more candidates → `asset_identity_ambiguities`, as today.

The duplicate-identifier handling from PR #19 still applies: soft hints stay on the asset that already holds them; hard IDs move to the asset the provider reports them on now.

### 4.4 "Active"

A source record is **active** when it is enabled (where the source has that concept), not `gone_at`, and `last_activity_at` is within the threshold. For AD the activity time is the newest of `lastLogonTimestamp` and `pwdLastSet`.

- Default threshold: 30 days (decided 2026-11-22), per integration (`active_threshold_days`), overridable per tenant.
- Because `lastLogonTimestamp` can lag up to about 14 days, AD thresholds below 21 days are not allowed in the form.
- Records that are not active are *stale*; the report shows them separately so they can also be used for cleanup (§5.3).

---

## 5. Device coverage report

### 5.1 Prerequisites in existing integrations

- **Intune:** add `lastSyncDateTime` (and `managementAgent`, `complianceState`) to the `managedDevices` `$select`.
- **Entra:** new scope `devices` reading `/devices` (`Device.Read.All`) with `deviceId`, `trustType`, `onPremisesSyncEnabled`, `onPremisesSecurityIdentifier`, `approximateLastSignInDateTime`, `accountEnabled`, `operatingSystem`.
- **Action1:** store the endpoint's `last_seen` as `last_activity_at` (it is read today but only kept in metadata).
- All sync services write `asset_source_records` in addition to what they do today.

### 5.2 The matrix

Agent panel → **Assets → Coverage**. One row per device, one column per source enabled for the organization. The columns come from the source registry (§3.0); the example below is what an organization with AD, Microsoft 365 and Action1 would see:

| Device | Class | AD | Entra ID | Intune | Defender | Action1 | Findings |
| ------ | ----- | -- | -------- | ------ | -------- | ------- | -------- |
| PC-0142 | Workstation | ● active | ● active | ✕ missing | ● active | ● active | Not in Intune |
| PC-0177 | Workstation | ● active | ● active | ● active | ● active | ✕ missing | No Action1 agent |
| SRV-FILE01 | Server | ● active | – | – | ✕ missing | ● active | Not in Defender |
| PC-0031 | Workstation | ○ stale (94 d) | ○ stale | ● active | ● active | ● active | Stale in AD |

- **●** active, **○** stale (with age), **✕** missing although a rule expects it, **–** not expected (integration not enabled for the organization, or the source does not apply to the device class or OS).
- A row is an **asset**, or an **unlinked source record** (for example an AD object that was not turned into an asset). Unlinked rows can be matched by short hostname and are shown as "probable match", with a button to link or create an asset.
- Filters: organization, device class, OS, source, "only rows with findings", active/stale.
- Summary tiles at the top: per rule, the share of devices that pass (e.g. "Intune coverage 87 % — 14 missing").
- Export to CSV; the same data at `GET /api/v1/coverage` and `GET /api/v1/coverage/findings`.
- MSP view: a tenant-wide table with one row per customer organization and one column per rule, to see which customers have gaps.

### 5.3 Coverage rules

A finding comes from a **rule**: *devices that are active in a source with role X (and match a filter) are expected in a source with role Y.* Rules can also name source keys when a tenant needs something specific. Built-in rules use roles only, so they apply to any integration that registers sources with those roles, and are switched on for an organization when it has at least one source on each side.

| Rule (built-in) | Condition | Likely cause shown to the agent |
| --------------- | --------- | ------------------------------- |
| **On-prem directory → cloud directory** | Active Windows workstation in AD, not in Entra (both role `directory`; AD flagged `on_premises`) | Hybrid join not configured, or the OU is not in Entra Connect's sync scope |
| **Directory → device management** | Active workstation in a `directory` source, not in any `device_management` source | Missing Intune license for the primary user, enrollment GPO not applied, or enrollment failed |
| **Remote management → device management** | Active workstation in a `remote_management` source (Windows/macOS), not in any `device_management` source | The device has an RMM agent but is not enrolled, often a missing license |
| **Device management → remote management** | Active device in a `device_management` source (Windows/macOS), not in any `remote_management` source | RMM agent not deployed (e.g. missing from the Intune app assignment) |
| **Any → endpoint security** | Active workstation or server in any source, not in any `endpoint_security` source | Onboarding package not deployed; for servers, Defender for Servers not enabled |
| **Discovered, unmanaged** | A source reports a discovered-only device (e.g. Defender `CanBeOnboarded`), no other source knows it | Unknown device on the network |
| **Stale in directory, active elsewhere** | Active in a management source, stale or disabled in a `directory` source | Device left the domain or was rebuilt under another name; clean up AD |
| **Active in directory, stale everywhere else** | Active in a `directory` source, stale in all other sources | Agents broken on a device that is still in use |

Rule definition (stored in `device_coverage_rules`, tenant scoped, optionally organization scoped):

```json
{
  "name": "Directory → device management",
  "when_active_in": { "roles": ["directory"] },
  "expect_in": { "roles": ["device_management"] },
  "device_classes": ["workstation"],
  "os_filter": "windows",
  "severity": "warning"
}
```

Phase 2 ships the rules *Directory → device management*, *Remote management → device management*, *Device management → remote management* and *Any → endpoint security* (`App\Support\DeviceCoverage\CoverageRules`). The remaining rules above need the stale/discovered states and come with phase 5.

**License hint (later):** when Entra users are synced, the rule *Directory → device management* can check whether the device's last signed-in user has a license that contains the Intune service plan (`assignedLicenses` / `INTUNE_A`). The finding then says "User has no Intune license" instead of listing all possible causes.

### 5.4 Findings, exceptions and follow-up

- A nightly job (`ComputeDeviceCoverageJob`, per organization, after syncs) writes `device_coverage_findings` with `first_detected_at` and `resolved_at`. That gives **trends** ("Intune coverage went from 71 % to 94 % this quarter") and lets the report show how long a gap has existed.
- **Exceptions:** an agent can accept a finding with a reason and an optional expiry date (lab PC, kiosk, device being retired). Accepted findings are hidden from the default view and the tiles, and are written to the activity log.
- **Integration health:** the gallery and the organization's integration page show a warning such as "14 active AD computers are not in Intune", linking to the filtered report. This reuses `IntegrationHealthIssue` with a new *coverage* kind.
- **Tickets (optional, per rule):** create or update **one** ticket per organization and rule listing the devices, instead of one ticket per device. This fits the planned recurring tickets ([#28](https://github.com/LaaknorIT/HelixDesk/issues/28)) and the monthly MSP customer report ([#32](https://github.com/LaaknorIT/HelixDesk/issues/32)).
- **Dashboard widget:** coverage per rule, using the widget API from `widgets-and-companion-apps.md`.

---

## 6. Security and multi-tenancy

- All new tables carry `tenant_id` with the global tenant scope; unique keys include `tenant_id`.
- Collector tokens: one ability, one integration, revocable from the integration page, creation and use written to the activity log. Optional IP allow list per integration.
- The collector never receives anything from HelixDesk except its configuration (search bases, interval). It cannot be used to run commands.
- LDAPS mode requires TLS with certificate validation; plain LDAP (389) is not offered.
- Raw AD batches are deleted after processing (default 7 days, for debugging).

---

## 7. Implementation phases

| Phase | Content | Depends on |
| ----- | ------- | ---------- |
| **1. Source records** | `asset_source_records`, `DeviceSourceRole`, `device_sources` in the integration catalog, source records and activity time from Intune and Action1, marking records gone after a complete sync, sources on the asset page | — |
| **2. Coverage report v1** | Generic matrix (columns from registered sources), role-based built-in rules, CSV, health warning | Phase 1 |
| **3. Active Directory** | `active_directory` type, collector key, push API, PowerShell collector. LDAPS mode, the Entra `devices` scope and the SID bridge follow separately | Phase 1 |
| **4. Defender for Endpoint** | `defender_devices` scope on the Entra integration (same credentials), Defender grouping in the UI, discovered devices | Phase 1 |
| **5. Follow-up** | Rule editor, exceptions, trends, tickets per rule, MSP overview, dashboard widget, license hint | Phase 2 |

Each phase is its own pull request. Phases 3 and 4 are independent of each other. Phase 2 is useful on its own (Intune vs Action1 works with today's integrations), so it ships before AD.

---

## 8. Decisions (2026-11-22)

1. AD creates assets for **active** computers only (`create_assets = active_only`). Stale and disabled objects stay as source records.
2. The first collector is a **PowerShell script** authenticating with **API credentials** (an integration-scoped token).
3. **30 days** is the default for "active".
4. Defender is shown as its own source and groups *Security incidents* with *Defender devices*, but uses the **same app registration and credentials** as the Entra integration (§3.1).
5. The report and rules are **generic**: driven by sources that integrations register, with rules written against roles (§3.0).

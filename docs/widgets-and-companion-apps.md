# Widgets and companion apps

Design for the roadmap items **API tokens and widget API** (Release 4) and **Widgets and companion apps** (Priority 2) in [`roadmap.md`](roadmap.md). The goal is that agents and end users can see their most important tickets, tasks and projects from their **own HelixDesk installation** without opening the web app: in the browser, on the desktop (Windows, macOS, Linux) and on the phone (Android, iOS).

Because HelixDesk is self-hosted, every client connects to **any instance URL** the user enters. There is no central cloud service.

## Content

- Counts: my open tickets, unassigned tickets in my queues, SLA close to breach, SLA breached, my tasks (`WorkItemType::Task`), and later my projects (see the project module in the roadmap).
- Short lists ("feeds") the user picks per widget, for example *My tickets*, *Queue: Service desk*, *SLA at risk*, *My tasks*.
- Tapping a row opens the item in HelixDesk.

## Architecture

The server does the work and the clients only show finished counts and lists. New widget types then need only a server change.

### API tokens and pairing

- **Laravel Sanctum** personal access tokens. API v1 today only accepts a browser session, so this also makes the API usable for other integrations.
- Widget tokens carry only the `widgets:read` ability and are bound to **one user and one tenant**. Users with several tenants (global admins, MSP agents) pick the tenant when pairing.
- New profile page **Connected devices** in the agent and end-user panels:
  - *Connect a device* shows a QR code and a short code. The QR code holds the instance URL and a one-time code valid for about five minutes.
  - The client exchanges the one-time code for a token.
  - A list of devices with last-seen time and *Revoke*.
- Token creation and revocation are written to the activity log.
- Clients never change data. Replies and status changes happen in the web app through a deep link.

### Widget API

All endpoints use the existing tenant and queue authorization (`TenantContext`, `WorkItemQueueScope`, `HelixdeskWorkItemAuthorization`), so a widget never shows more than the user sees in the panel.

| Endpoint | Returns |
|----------|---------|
| `GET /api/v1/widgets/summary` | The counts above and `updated_at`. |
| `GET /api/v1/widgets/feeds` | The feeds the user can choose. |
| `GET /api/v1/widgets/feeds/{feed}?limit=10` | A short list: `public_id`, title, priority, status, customer, SLA due time, deep link. |
| `GET /api/v1/widgets/feeds/{feed}/card` | The same data as an **Adaptive Card** (Windows Widgets, later Microsoft Teams). |

Requirements: small responses, `ETag` / `304 Not Modified`, rate limiting per token, CORS for browser extension origins, and a **privacy mode** that hides titles (counts and IDs only) for lock screens and shared screens.

Feed definitions live in one service (for example `WidgetFeedService`) that also powers the Filament dashboard widgets in Release 4, so the web dashboard and external widgets show the same numbers.

### Refresh and notifications

- **Polling is the baseline.** Operating systems refresh widgets on their own budget (Android at most every 15 minutes, iOS typically every 15–60 minutes), so the API must handle frequent, cheap polling.
- **Web Push (VAPID)** for the browser and the PWA. Keys are generated per installation, so no third party is involved.
- **Mobile push (APNs/FCM) and a push relay are deferred.** Mobile apps are installed locally for now and rely on polling.
- Push reuses the events from agent notifications ([`notifications.md`](notifications.md)): new ticket, assigned, customer reply, SLA at risk.

## Clients

| Platform | Solution |
|----------|----------|
| **Browser** | One WebExtension (Manifest V3) for Chrome, Edge and Firefox: count badge on the icon, popup with the chosen feed, system notifications. Distributed as a file for policy rollout (Intune/GPO) and later through the browser stores. |
| **Browser / desktop** | HelixDesk as an installable **PWA**: count on the taskbar or Dock icon (Badging API) and Web Push. This brings forward part of the Priority 3 item *Mobile-friendly agent view / PWA*. |
| **Windows 11** | PWA widget in the Widgets board (Edge `widgets` manifest member with Adaptive Card templates), using the `/card` endpoint. Current Edge support must be verified before building. |
| **macOS** | WidgetKit widget, built from the same SwiftUI code as the iOS app. |
| **Linux** | PWA badge and notifications. KDE Plasma or GNOME widgets can be built by the community against the same API. |
| **Android** | Small native app (Kotlin, Jetpack Glance widgets, WorkManager refresh) that only handles pairing, settings and widgets, and opens the web app for everything else. Installed locally as an APK for now. |
| **iOS / iPadOS** | Small native app (Swift, WidgetKit, home and lock screen widgets). Installed locally for now. |

Home screen widgets cannot be built from a PWA, so the mobile apps are native. Tokens are stored in the Keychain (Apple), Android Keystore, and `storage.local` in the extension.

## Phases

| Phase | Scope | Roadmap |
|-------|-------|---------|
| 0 | Sanctum tokens, pagination and filters on API v1, CORS, OpenAPI for the new endpoints. | Release 4 |
| 1 | `WidgetFeedService`, `/api/v1/widgets/*`, *Connected devices* with QR pairing, Filament dashboard widgets on the same service. | Release 4 |
| 2 | Browser extension, PWA with badge and Web Push, Windows widget through the PWA. | Priority 2 |
| 3 | Android app with widgets. | Priority 2 |
| 4 | iOS and macOS app with widgets. | Priority 2 |
| 5 | Project widgets (after the project module), push relay and store distribution if needed, Linux community widgets, Microsoft Teams tab. | Later |

## Where the code lives

- Server work (phases 0–1 and the PWA) lives in this repository.
- The browser extension and the mobile and desktop apps live in a separate client repository (`helixdesk-clients`), created when phase 2 starts.

# Filament Structure

This project uses panel-specific folders to keep SaaS/operator, agent, and end-user concerns separated.

## Folder layout

- `app/Filament/GlobalAdmin/`
  - Resources, pages, and widgets for the SaaS platform administrators.
- `app/Filament/Agent/`
  - Resources, pages, and widgets for tenant agents / tenant admins.
- `app/Filament/EndUser/`
  - Resources, pages, and widgets for tenant end users.

## Discovery boundaries

Each `PanelProvider` discovers only its own folder:

- `GlobalAdminPanelProvider` -> `app/Filament/GlobalAdmin/*`
- `AgentPanelProvider` -> `app/Filament/Agent/*`
- `EndUserPanelProvider` -> `app/Filament/EndUser/*`

Do not place panel code under a shared `app/Filament/Resources` path.

## Conventions for new code

When adding new Filament classes:

1. Pick the target panel first (`GlobalAdmin`, `Agent`, or `EndUser`).
2. Create classes under that panel folder only.
3. Keep cross-panel behavior in shared application services (for example, `app/Services/*`), not in shared Filament resources.
4. If a feature must exist in multiple panels, create separate panel resources/pages that call shared domain/service logic.


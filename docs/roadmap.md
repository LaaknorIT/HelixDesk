# HelixDesk roadmap

The roadmap now lives in GitHub, not in this file:

- **Roadmap issues:** [issues labelled `roadmap`](https://github.com/LaaknorIT/HelixDesk/issues?q=is%3Aissue+label%3Aroadmap)
- **Releases and priorities:** [milestones](https://github.com/LaaknorIT/HelixDesk/milestones)
- **Roadmap board:** GitHub Projects board for the repository ([Projects tab](https://github.com/LaaknorIT/HelixDesk/projects))

## How it is organised

| Milestone | Content |
|-----------|---------|
| Release 1 — Get started | Priority 1: Docker image, notifications, agent productivity, MFA |
| Release 2 — MSP pack | Priority 1: time tracking, customer agreements, billing export, recurring tickets |
| Release 3 — Self-service | Priority 1: knowledge base, request forms, CSAT, MSP customer portal |
| Release 4 — Insight and operations | Priority 1: reports, monitoring to ticket, webhooks and OpenAPI, API tokens |
| Priority 2 — Differentiators | Project module, companion apps, RMM and AD integrations, device coverage, vault, SSO, i18n |
| Priority 3 — Later | AI assist, major incidents, status page, ISO modules, CAB, S3 storage |
| No milestone | Backlog items that are not yet scheduled |

Labels: `priority: P1`–`P3`, `area: …` for the part of the product, and `status: partial` for items where some of the scope is already delivered.

**Out of scope for this repository:** billing for HelixDesk itself (subscriptions, Stripe, metered usage). HelixDesk exports what an MSP bills its customers ([#27](https://github.com/LaaknorIT/HelixDesk/issues/27)).

Design documents for individual items stay in [`docs/`](.) and are linked from the issues.

When a pull request delivers a roadmap item, write `Fixes #<issue>` in its description so the issue closes on merge.

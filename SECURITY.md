# Security policy

HelixDesk stores tickets, mailbox credentials, asset data and integration secrets for many organizations, so we take security reports seriously.

## Reporting a vulnerability

**Please do not report security problems in public issues, discussions or pull requests.**

Report them privately through GitHub: go to the **Security** tab of this repository and choose **Report a vulnerability**, or use [this link](https://github.com/LaaknorIT/HelixDesk/security/advisories/new).

Please include:

- what the problem is and what an attacker could do with it,
- steps to reproduce, or a proof of concept,
- the commit or version you tested.

We aim to acknowledge reports within a few working days, and will keep you updated until a fix is released. We are happy to credit you in the advisory unless you prefer otherwise.

## Supported versions

HelixDesk does not have versioned releases yet. Security fixes are made on the `main` branch.

## Especially relevant areas

- Tenant isolation: data from one tenant or customer organization must never be visible to another.
- Role and per-queue permissions.
- Inbound email handling and attachments.
- Stored credentials for mailboxes and integrations (Microsoft Entra, Action1, etc.).
- The REST API and its tokens.

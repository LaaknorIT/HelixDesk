# Contributing to HelixDesk

Thanks for helping make HelixDesk a good open source tool for IT departments and MSPs.

## Reporting issues

- **Bugs and feature requests:** open an issue using one of the templates.
- **Security problems:** do not open a public issue. Follow [SECURITY.md](SECURITY.md).
- Never paste passwords, API keys, mailbox contents or customer data into an issue.

Check [`docs/roadmap.md`](docs/roadmap.md) first: your idea may already be planned.

## Development setup

Requirements: PHP 8.4 or newer (CI runs 8.5), Composer, Node.js 22.

```bash
composer setup                                   # install, .env, key, migrate, build assets
php artisan db:seed --class=DemoContentSeeder    # optional demo data
composer run dev                                 # server, queue, logs and Vite
```

Architecture and domain notes are in [`docs/`](docs/). [`AGENTS.md`](AGENTS.md) lists the coding conventions used in this codebase.

## Making a change

1. Fork the repository and create a branch from `main`.
2. Make your change and follow the conventions of nearby code.
3. Add or update PHPUnit tests. Every change should be covered by a test.
4. Run the checks before opening a pull request:

   ```bash
   vendor/bin/pint
   php artisan test --compact
   ```

5. Open a pull request against `main` and fill in the template. CI runs Pint and the test suite on every pull request.

`main` is deployed, so it must always stay green. Pull requests are merged by a maintainer once CI passes and the change has been reviewed.

## License

HelixDesk is licensed under the [GNU AGPL v3.0 or later](LICENSE). By submitting a contribution you agree that it is licensed under the same terms.

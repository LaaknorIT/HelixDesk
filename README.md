# HelixDesk

Multi-tenant IT service desk software (ITIL-oriented) built with **Laravel 12**, **Livewire**, and **Filament 5**.

## License

HelixDesk is free software published under the **[GNU Affero General Public License v3.0 or later** (AGPL-3.0-or-later)](https://www.gnu.org/licenses/agpl-3.0.html). See `[LICENSE](LICENSE)` and `[NOTICE](NOTICE)`.

If you run a **modified** version over a network, AGPL requires you to offer corresponding source to users interacting with it. Review the license carefully before deployment.

## Requirements

- PHP 8.2+
- Composer
- Node.js (for Vite frontend assets)

## Quick start

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
npm install && npm run build
php artisan serve
```

Use `php artisan db:seed --class=DemoContentSeeder` for demo data when developing.

## Documentation

Product and architecture notes live in `[docs/](docs/)`. The working delivery plan is `[docs/roadmap.md](docs/roadmap.md)`.

## Contributing

Contributions are welcome. By submitting changes, you agree they are licensed under AGPL-3.0-or-later, the same as the project.
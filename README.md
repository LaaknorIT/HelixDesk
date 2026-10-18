# HelixDesk

Multi-tenant IT service desk software (ITIL-oriented) built with **Laravel 12**, **Livewire**, and **Filament 5**.

## License

HelixDesk is open source software released under the **[MIT License](https://opensource.org/licenses/MIT)**. See [`LICENSE`](LICENSE).

## Requirements

- PHP 8.4+ (CI runs 8.5)
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

Contributions are welcome. See [`CONTRIBUTING.md`](CONTRIBUTING.md) for setup, tests and how to open a pull request. By submitting changes, you agree they are licensed under the MIT License, the same as the project.

## Security

Please report vulnerabilities privately, as described in [`SECURITY.md`](SECURITY.md).
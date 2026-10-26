# Installing HelixDesk with Docker

This guide runs HelixDesk on one server with Docker Compose. It is the recommended way to self-host HelixDesk. For a development setup, see [`CONTRIBUTING.md`](../CONTRIBUTING.md).

## What you get

[`compose.yaml`](../compose.yaml) starts five containers. The three HelixDesk containers run the same image with a different role.

| Service | Role |
|---------|------|
| `app` | Web server (FrankenPHP) on port 8080. Runs database migrations on start. |
| `horizon` | Laravel Horizon queue workers: outgoing ticket mail, integration syncs and other background jobs. |
| `scheduler` | Runs scheduled tasks: mailbox polling, SLA breach checks and integration imports. |
| `postgres` | PostgreSQL 17 database. |
| `redis` | Redis for queues, cache and sessions. |

Data is kept in three named volumes: `postgres-data`, `redis-data` and `helixdesk-storage` (uploaded files and mail attachments).

## Requirements

- A Linux server with Docker Engine 24 or newer and the Docker Compose plugin.
- At least 2 GB of RAM and 10 GB of disk.
- A DNS name for HelixDesk and a reverse proxy that terminates HTTPS (see [HTTPS](#https-and-reverse-proxy)).

## Install

1. Get the files. Cloning the repository is the simplest way:

   ```bash
   git clone https://github.com/LaaknorIT/HelixDesk.git helixdesk
   cd helixdesk
   ```

   Only `compose.yaml` and `.env.docker.example` are needed when you run the published image.

2. Create the settings file and fill in the required values:

   ```bash
   cp .env.docker.example .env
   echo "APP_KEY=base64:$(openssl rand -base64 32)"   # paste into .env
   openssl rand -hex 24                               # use as DB_PASSWORD
   ```

   Set `APP_URL` to the address people will use, for example `https://helpdesk.example.com`.

   Keep a copy of `APP_KEY` somewhere safe. It encrypts stored secrets such as mailbox and integration credentials, and they cannot be read without it.

3. Start HelixDesk:

   ```bash
   docker compose up -d --wait
   ```

   The first start pulls the image and creates the database. To build the image from your checkout instead, run `docker compose up -d --wait --build`.

4. Create the first global administrator:

   ```bash
   docker compose exec app php artisan helixdesk:create-admin
   ```

5. Sign in at `APP_URL/global-admin`, create your first tenant, and assign yourself as its tenant administrator. Agents work in `/agent`, and end users use `/end-user`.

## HTTPS and reverse proxy

HelixDesk serves plain HTTP on port 8080 and expects a reverse proxy in front of it for HTTPS. Set these in `.env` so links and redirects use the public address:

```dotenv
APP_URL=https://helpdesk.example.com
TRUSTED_PROXIES=*
```

`TRUSTED_PROXIES=*` trusts the proxy that connects to the container. Use it only when the port is not reachable from anywhere else, for example when it is bound to localhost with `HELIXDESK_HTTP_PORT=127.0.0.1:8080`. Otherwise list the proxy addresses, such as `TRUSTED_PROXIES=10.0.0.5,192.168.1.0/24`.

A minimal [Caddy](https://caddyserver.com/) configuration that also gets a certificate automatically:

```caddyfile
helpdesk.example.com {
    reverse_proxy 127.0.0.1:8080
}
```

With nginx, forward the usual headers:

```nginx
location / {
    proxy_pass http://127.0.0.1:8080;
    proxy_set_header Host $host;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
    proxy_set_header X-Forwarded-Host $host;
    client_max_body_size 64m;
}
```

## Mail

The `MAIL_*` settings in `.env` are for system mail such as password resets. Ticket mail is sent and received through the mailboxes you add in the agent panel; see [`email-handling.md`](email-handling.md). The scheduler polls those mailboxes every five minutes.

## Upgrading

Pin a release in `.env` (for example `HELIXDESK_IMAGE=ghcr.io/laaknorit/helixdesk:1.2.0`) so upgrades happen when you choose. To upgrade, change the tag, then:

```bash
docker compose pull
docker compose up -d --wait
```

The `app` container runs new migrations before it starts serving. Take a backup first. To run migrations yourself instead, set `HELIXDESK_MIGRATE=false` and run `docker compose run --rm app php artisan migrate --force`.

## Backup and restore

Back up the database, the storage volume and your `.env` file:

```bash
docker compose exec -T postgres pg_dump -U helixdesk -Fc helixdesk > helixdesk.dump
docker run --rm -v helixdesk_helixdesk-storage:/data -v "$PWD":/backup alpine \
    tar czf /backup/helixdesk-storage.tar.gz -C /data .
```

The volume name starts with the Compose project name, which is the folder name by default; `docker volume ls` shows it.

To restore the database into a fresh installation:

```bash
docker compose up -d --wait postgres
docker compose exec -T postgres pg_restore -U helixdesk -d helixdesk --clean --if-exists < helixdesk.dump
docker compose up -d --wait
```

## Troubleshooting

- `docker compose ps` shows the health of each container. `docker compose logs app` (or `horizon`, `scheduler`) shows its log; HelixDesk logs to the container output.
- The queue dashboard is at `APP_URL/horizon` and is open to global administrators.
- `DB_PASSWORD` is only applied when the database volume is first created. If you change it later, change it in PostgreSQL as well.
- If the `app` container stops with "APP_KEY is not set", add the key to `.env` and run `docker compose up -d` again.

## Configuration reference

All settings from [`.env.example`](../.env.example) can be added to `.env`. Compose sets the database, Redis, queue, cache and session connections itself, so those do not need to be changed. Settings that only apply to the containers:

| Setting | Default | Meaning |
|---------|---------|---------|
| `HELIXDESK_IMAGE` | `ghcr.io/laaknorit/helixdesk:latest` | Image to run. |
| `HELIXDESK_HTTP_PORT` | `8080` | Host port, optionally with an address such as `127.0.0.1:8080`. |
| `HELIXDESK_MIGRATE` | `true` | Run migrations when the `app` container starts. |
| `HELIXDESK_DB_WAIT_SECONDS` | `60` | How long containers wait for the database on start. |
| `TRUSTED_PROXIES` | empty | Reverse proxies whose `X-Forwarded-*` headers are trusted. |

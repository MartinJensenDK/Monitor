# Monitor

Self-hosted uptime monitoring. Watches your websites, records what happened, and
shows it on a dashboard that updates itself while you look at it.

Built as plain PHP 8 and MySQL — no Node, no build step, no container. Upload the
folder, open the site, answer four questions, add one cron line.

---

## What it does today

- **Website monitoring** — status code ranges, keyword on the page, redirects,
  request method, headers, body, basic auth, TLS certificate expiry, and a
  response-time threshold that marks a monitor *degraded* rather than down.
- **Live dashboard** — the page updates every five seconds without reloading.
  Nothing changed since the last poll costs the server one query and returns 304.
- **Charts** — response time with p95, outages painted behind the line, 30 days of
  daily uptime bars, an availability donut and a certificate-expiry meter.
- **Incidents** — opened only after a failure survives its retries, closed
  automatically on recovery, with duration and cause. Acknowledgeable.
- **Roles and groups** — a role sets what a person may do; group membership sets
  which monitors they may do it to.
- **Light and dark** — chosen explicitly or following the visitor's system, applied
  server-side so there is no flash on page load.
- **Audit log** — who changed what, when, from which address.

### Coming next

| Stage | What arrives |
|---|---|
| 2 | Endpoint (JSON assertions), Ping and Port checks · email notifications with SMTP setup and a test button · per-monitor rules: failure threshold, re-notify interval, quiet hours, notify on recovery, degraded, or certificate expiry |
| 3 | Microsoft Entra ID — SSO sign-in and syncing users and groups from Graph, so group membership is maintained in Entra rather than here |
| later | Public status page, maintenance windows, webhooks, API tokens, 2FA |

The database schema for stage 2 and 3 is already in place, so upgrading adds
behaviour without touching your data.

---

## Requirements

- PHP 8.2 or newer with `pdo_mysql`, `curl`, `mbstring`, `openssl`, `sodium`, `json`
- MySQL 8.0+ or MariaDB 10.6+
- A web server that can point a site at the `public/` folder
- Cron (or any scheduler that can run a command every minute)

## Install

```bash
git clone git@github.com:MartinJensenDK/Monitor.git
cd Monitor
composer install --no-dev --optimize-autoloader
```

Then:

1. Put the project somewhere outside your web root — or anywhere, as long as the
   site's document root points at its `public/` folder.
2. Create an empty database and a user with full rights to it.
3. Open the site in a browser. The setup wizard checks the server, tests the
   database connection, and creates your administrator account.
4. Add the cron line it shows you.

Prefer the terminal? `php bin/install.php` asks the same questions, and takes
flags for unattended deployment. See [docs/INSTALL.md](docs/INSTALL.md) for the
details, including CloudPanel, nginx and Apache specifics.

## Where the database password lives

In `.env` in the project root — one level above `public/`, so it is never
reachable over the web. The installer writes it with `chmod 600`; you should not
have to edit it by hand. `.env.example` documents every key.

## Day to day

```bash
php bin/scheduler.php --once --verbose     # run everything that is due, and show it
php bin/scheduler.php --monitor=3 --verbose # check one monitor right now
php bin/scheduler.php --dry-run --verbose   # run the checks, write nothing
php bin/migrate.php --status                # which migrations have run
```

The cron entry runs `bin/scheduler.php` once a minute. Inside that minute it ticks
every few seconds, so intervals shorter than a minute work without a daemon. A
MySQL advisory lock keeps two runs from overlapping.

## How it is put together

```
app/Core/        router, PDO wrapper, views, sessions, auth, roles
app/Domain/      monitors, incidents, groups, stats, settings — and MonitorScope,
                 the single place that decides which monitors a user may see
app/Checks/      one class per monitor type
app/Scheduler/   the runner, the rollups, retention
app/Install/     shared by the browser wizard and bin/install.php
public/          the only folder the web server needs
```

Raw checks are rolled up into per-minute, per-hour and per-day buckets. Every
chart reads the buckets, so a monitor with a year of history draws as fast as a
fresh one, and the raw rows can be pruned aggressively.

### Adding a monitor type

Implement `App\Checks\CheckerInterface`, register the class in
`App\Checks\CheckerFactory`, and add a `data-type-fields` block to
`resources/views/pages/monitor-form.php`. Nothing else needs to change.

### Adding a language

Copy `resources/lang/en.php` to your locale code, translate the values, and set
it as the default under Settings. Missing keys fall back to English. A Danish
translation ships as `da.php`.

## Security

Passwords are hashed with Argon2id (PHP's `PASSWORD_DEFAULT`). Sessions are
`HttpOnly`, `SameSite=Lax`, `Secure` over HTTPS, and regenerated on sign-in.
Every mutation carries a CSRF token. Sign-in attempts are throttled per account
and per address. All SQL is parameterised, all output escaped, and the CSP allows
no inline scripts. Secrets kept in the database — the SMTP password today, the
Entra client secret later — are encrypted with the key in `.env`.

Monitors fetch addresses that people type in, so private and loopback targets are
refused unless an administrator turns them on in Settings. The check verifies the
address it actually reached, which also covers redirects.

## Licence

MIT.

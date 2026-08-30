# Monitor

Self-hosted uptime monitoring. Watches your websites, records what happened, and
shows it on a dashboard that updates itself while you look at it.

Built as plain PHP 8 and MySQL — no Node, no build step, no container. Upload the
folder, open the site, answer four questions, add one cron line.

---

## What it does today

**Nine kinds of check**

| Type | Watches | Says it is down when |
|---|---|---|
| **Website** | A page over HTTP | The status code falls outside the expected range, a keyword is missing (or present), the TLS handshake fails, or it times out |
| **Keyword** | The wording on a page | The words you named are missing — or, the other way round, an error phrase has appeared. Several at once, matched on the visible text rather than the markup |
| **Endpoint** | A JSON API, one call | Any of your assertions about the response body does not hold — `queue.depth` under 100, `status` equal to `ok`, `items.0.state` present |
| **API** | A JSON API, several calls | Any step in the sequence fails. Sign in, capture the token, call the endpoint it unlocks — the failure names the step |
| **Ping** | A host answering | No ICMP echo comes back. Where the server may not send ICMP, it times a TCP connect instead and says so rather than pretending |
| **Port** | A service listening | Nothing accepts the connection, or the greeting it sends is not the one you expected |
| **SSL certificate** | A certificate and its clock | It has expired, the chain is not trusted, it does not cover the host, the issuer changed, or it runs out sooner than you allow |
| **Domain** | A registration | The registry has no record of it, it is on hold, it changed registrar or nameservers, or it expires sooner than you allow |
| **DNS** | A record | The name does not resolve, the record is gone, it no longer holds what you published, or two resolvers disagree about it |

All nine share the same settings: interval, timeout, retries before an incident
opens, and a response-time threshold that marks a monitor *degraded* rather than
down.

Three of them count down to an expiry rather than only reporting the moment
things break. A certificate or a registration that runs low turns the monitor
**amber** first and **down** only at the second threshold, so the alert arrives
while there is still time to renew. See
[docs/MONITOR-TYPES.md](docs/MONITOR-TYPES.md) for what each type asks for and
what it reports back.

**Everything around them**

- **Email notifications** — SMTP or local sendmail, configured in the interface
  with a test button that reports what the mail server actually said. Per monitor
  and per channel you choose the events (down, recovery, slow, expiring
  certificate or registration), how many consecutive failures to wait for, whether to repeat while an
  incident is open, and hours to stay quiet. Every send — and every deliberate
  skip — is logged, so "why didn't I get an email?" has an answer.
- **Live dashboard** — the page updates every five seconds without reloading.
  Nothing changed since the last poll costs the server one query and returns 304.
- **Charts** — response time with p95, outages painted behind the line, 30 days of
  daily uptime bars, an availability donut and a certificate-expiry meter.
- **Incidents** — opened only after a failure survives its retries, closed
  automatically on recovery, with duration and cause. Acknowledgeable.
- **Roles and groups** — a role sets what a person may do; group membership sets
  which monitors they may do it to.
- **Microsoft Entra ID** — sign in with a work account, and mirror directory
  groups so membership is maintained in Entra rather than here. A group can grant
  a role, so "who is an administrator" also lives in one place. Read-only: Monitor
  never writes to your directory. See [docs/ENTRA.md](docs/ENTRA.md)
- **Light and dark** — chosen explicitly or following the visitor's system, applied
  server-side so there is no flash on page load.
- **Audit log** — who changed what, when, from which address.

### Coming next

Public status page, maintenance windows, webhooks and Slack, API tokens, 2FA.

---

## Requirements

- PHP 8.2 or newer with `pdo_mysql`, `curl`, `mbstring`, `openssl`, `sodium`, `json`
- MySQL 8.0+ or MariaDB 10.6+
- A web server that can point a site at the `public/` folder
- Cron (or any scheduler that can run a command every minute)
- Optional: real ICMP for ping checks. Without it, ping falls back to timing a
  TCP connect — see [docs/INSTALL.md](docs/INSTALL.md#ping-and-icmp)

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
app/Checks/      one class per monitor type, plus the protocol clients they
                 use — a DNS resolver, a TLS inspector, an RDAP/WHOIS reader
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
`resources/views/pages/monitor-form.php`. Nothing else needs to change — the
type-specific settings live in the monitor's `config` JSON column, so a new type
needs no migration.

Implement `App\Checks\BatchableChecker` as well if the check is an HTTP request:
the scheduler then sends it with all the others in one `curl_multi` round.

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

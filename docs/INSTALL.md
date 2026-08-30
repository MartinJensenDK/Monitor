# Installing Monitor

Monitor needs three things: PHP 8.2+, a MySQL database, and a cron entry. This
walks through all three, then covers the two ways to run the installer and the
problems people actually hit.

---

## 1. Put the files in place

The web server must serve the `public/` folder, not the project root. Everything
above `public/` — including `.env` with your database password — then stays
unreachable over the web.

```
monitor.example.com/          ← project root (never served)
├── .env                      ← database password lives here, chmod 600
├── app/  bin/  database/  resources/  storage/  vendor/
└── public/                   ← document root
```

The dependencies are not in the repository. After cloning, install them:

```bash
composer install --no-dev --optimize-autoloader
```

That creates `vendor/`. Composer 2.2 or newer. There is exactly one dependency —
PHPMailer — so it finishes in seconds.

Make `storage/` and the project root writable by the web server user — the
installer writes `.env`, `storage/installed.lock`, logs and sessions.

### CloudPanel

1. **Sites → your site → Settings → Root Directory**: set it to
   `/htdocs/<your-domain>/public`.
2. **Settings → PHP Version**: 8.2 or newer.
3. **Databases → Add Database**: note the name, user and password — the installer
   asks for all three.

### nginx (standalone)

```nginx
root /var/www/monitor/public;
index index.php;

location / {
    try_files $uri $uri/ /index.php?$query_string;
}

location ~ \.php$ {
    include snippets/fastcgi-php.conf;
    fastcgi_pass unix:/run/php/php8.3-fpm.sock;
}
```

### Apache

Point `DocumentRoot` at `public/` and allow `.htaccess`, or add this rewrite to
your vhost:

```apache
DocumentRoot /var/www/monitor/public
<Directory /var/www/monitor/public>
    AllowOverride All
    Require all granted
    FallbackResource /index.php
</Directory>
```

---

## 2. Create the database

Monitor needs an empty database and a user with full rights to it. It does not
need permission to create databases.

```sql
CREATE DATABASE `monitor-db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'user-db-monitor'@'localhost' IDENTIFIED BY 'a-long-random-password';
GRANT ALL PRIVILEGES ON `monitor-db`.* TO 'user-db-monitor'@'localhost';
FLUSH PRIVILEGES;
```

A hyphen in the database name is fine — every identifier is quoted.

---

## 3. Run the installer

### In the browser

Open the site. Until `storage/installed.lock` exists, every address redirects to
`/install`; afterwards `/install` returns 404.

1. **Requirements** — PHP version, extensions, writable folders, and whether the
   document root really points at `public/`. Anything with a cross has to be
   fixed first, and the page tells you the command to fix it.
2. **Database** — leave **MySQL runs on this server** ticked to use
   `127.0.0.1:3306`, or untick it to enter a host and port. Fill in the database
   name, user and password, then press **Test the connection**. You cannot
   continue until it succeeds, and a failure says what is actually wrong: wrong
   password, missing database, nothing listening, or a user without rights.
3. **Site** — name, URL and time zone. Times are stored in UTC and displayed in
   the zone you pick.
4. **Administrator** — your name, email and a password of at least 10 characters.

The installer then writes `.env` (chmod 600), creates the tables, creates your
account and a group called *All monitors*, and shows the cron line.

### From the command line

```bash
php bin/install.php
```

Same questions, asked in the terminal. The database password is not echoed. For
unattended deployment:

```bash
php bin/install.php --non-interactive \
  --same-host \
  --db-name=monitor-db \
  --db-user=user-db-monitor \
  --db-pass='…' \
  --site-name='Monitor' \
  --site-url='https://monitor.example.com' \
  --timezone='Europe/Copenhagen' \
  --admin-name='Your Name' \
  --admin-email='you@example.com' \
  --admin-password='…'
```

Drop `--same-host` and pass `--db-host` / `--db-port` for a remote database. Add
`--db-prefix=mon_` when the database is shared with another application.

---

## 4. Add the cron entry

Nothing is checked until this runs. `crontab -e`, then:

```cron
* * * * * /usr/bin/php8.3 /path/to/monitor/bin/scheduler.php >> /path/to/monitor/storage/logs/scheduler.log 2>&1
```

The installer prints the exact line with the right paths, and Settings shows it
again along with whether the scheduler has run recently.

In CloudPanel: **Sites → your site → Cron Jobs → Add Cron Job**, every minute.

---

## Email notifications

Nothing is emailed until two things are true: email is configured, and
**Send notification emails** is on. Both live under **Settings → Email**.

1. Pick **SMTP server** (recommended) or **Local sendmail**.
2. Fill in the sender address — some providers insist it matches the account.
3. For SMTP: host, port, encryption, and a user name and password if the server
   wants them. 587 with STARTTLS suits most providers; 465 uses SSL/TLS.
4. Save, then use **Send a test email**. A failure reports what the mail server
   said — a rejected password reads as a rejected password, not "could not send".

The SMTP password is encrypted with `APP_KEY` before it is stored, so a database
dump alone does not leak it.

### Who gets told, and about what

A **channel** is a named list of recipients — *Ops on call*, *Support*, one
person. Add them under **Settings → Notification channels**.

Each monitor then chooses its channels and, per channel:

| Setting | What it does |
|---|---|
| Events | Down, recovery, slow responses, certificate expiring — any combination |
| Wait for this many failures | Counted after retries, so 1 means the first confirmed failure. Raise it for a flaky endpoint you do not want to hear about immediately |
| Repeat every | While the incident stays open. 0 sends once |
| Certificate warning | Days of notice before a TLS certificate expires |
| Stay quiet between | An hour range, in the site's time zone, that wraps past midnight |

Every decision is written to the log at the bottom of the channels page: sent,
failed with the reason, or skipped with the reason. Start there when an email you
expected did not arrive.

Local sendmail works, but mail from a server without correct SPF and DKIM records
usually lands in spam. If alerts go missing, that is the first thing to check.

## Signing in with Microsoft

Optional. Monitor can use Microsoft Entra ID for sign-in, for group membership,
or for both — see [ENTRA.md](ENTRA.md) for the app registration, the two Graph
permissions it needs, and what the sync does to your accounts.

## Ping and ICMP

Sending an ICMP echo needs a privilege that plenty of hosts do not hand out.
Monitor works out what it is allowed to do and tells you on the ping monitor's
own page rather than reporting numbers it cannot stand behind:

1. **Unprivileged ICMP socket** — used when the kernel allows it.
2. **The system `ping` command** — used when it exists and carries `cap_net_raw`.
3. **TCP connect timing** — the fallback. It times a connection to a port you
   choose (443 by default) instead of sending an echo request.

The fallback answers "is this host reachable and how fast", which is what most
people want from a ping check. It will not notice a host that answers ICMP but
has closed the port you picked, so choose a port the host actually serves.

To enable real ICMP, a server administrator runs this once:

```bash
sudo sysctl -w net.ipv4.ping_group_range="0 2147483647"
```

Make it survive a reboot by adding `net.ipv4.ping_group_range = 0 2147483647` to
`/etc/sysctl.d/99-monitor.conf`. Installing `iputils-ping` works too. Monitor
re-checks once a day and switches over on its own — no restart, no setting.

---

## What the checks need to reach

The scheduler runs from the web server, so whatever the firewall allows *it* to
do is what your monitors can do. Beyond ordinary outbound HTTP and HTTPS:

| Monitor type | Needs |
|---|---|
| Domain | Outbound **HTTPS** to the RDAP service (`rdap.org` by default, which redirects to the registry), and outbound **TCP port 43** for the WHOIS fallback used by registries with no RDAP — `.dk` among them |
| DNS | Outbound **UDP port 53** to each resolver you name, and **TCP port 53** for answers too large for a datagram |
| SSL certificate | Outbound TCP to whichever port you point it at — 443, 993, 587, 5432 |
| Ping, Port | See the ICMP note above; the port check needs outbound TCP to that port |

If a domain monitor reports that the RDAP service could not be reached, and the
WHOIS fallback times out too, outbound port 43 is usually the reason.

The RDAP service can be changed for one that suits you better — a registry's own
endpoint, or an internal mirror. Add `rdap_url` to the settings table with
`{domain}` where the name belongs:

```sql
INSERT INTO `settings` (`key`, `value`, `updated_at`)
VALUES ('rdap_url', 'https://rdap.example.net/domain/{domain}', UTC_TIMESTAMP());
```

---

## Where the database password lives

`.env` in the project root:

```
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=monitor-db
DB_USER=user-db-monitor
DB_PASS=your-password-here
```

Also in that file is `APP_KEY`, generated during install. It encrypts secrets kept
in the database. **Keep it.** Replacing it makes the stored SMTP password
unreadable — you would have to enter it again.

Back up `.env` together with your database dump.

---

## Updating

1. Back up the database and `.env`.
2. Replace the files, keeping `.env` and `storage/`.
3. `php bin/migrate.php`
4. `php bin/migrate.php --status` to confirm.

---

## When something is wrong

**Everything redirects to /install, or /install 404s.**
The lock file decides. It is `storage/installed.lock` — present means installed.

**"Could not write .env"**
The web server user cannot write the project root. `chmod u+w` the folder, or run
`php bin/install.php` as a user who can, then reload the page.

**The dashboard is empty and nothing is ever checked.**
Cron is not running the scheduler. Settings shows when the last check ran; run
`php bin/scheduler.php --once --verbose` by hand to see what happens, and check
`storage/logs/scheduler.log`.

**A monitor fails with "resolves to the private address …".**
Private and loopback targets are blocked by default so a monitor cannot be aimed
at internal services. Turn on **Allow private targets** in Settings if you are
monitoring your own network.

**Two scheduler runs at once.**
They cannot: the runner takes a MySQL advisory lock and a second run exits
immediately. If checks are consistently late, raise **Checks at once** in
Settings or lengthen the intervals.

**No email arrives.**
Work down the list: is **Send notification emails** on, does **Send a test email**
succeed, does the monitor have a channel with your address, and does the log at the
bottom of the channels page show `sent`, `failed` or `skipped`? A `skipped` row
names the rule that stopped it — quiet hours, or a failure threshold not reached
yet. A `failed` row carries the mail server's own words.

**A ping check reports TCP connect timing.**
This server may not send ICMP. That is a host restriction, not a fault — see
[Ping and ICMP](#ping-and-icmp) for the one command that changes it.

**Assets 404 after install.**
The document root is still the project root rather than `public/`. The
requirements step warns about this.

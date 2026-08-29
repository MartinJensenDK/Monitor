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

**Assets 404 after install.**
The document root is still the project root rather than `public/`. The
requirements step warns about this.

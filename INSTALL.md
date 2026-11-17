# Installing and Updating TBOps

This guide is for whoever hosts the site: installing it on a server, scheduling
its background jobs, updating it and fixing the usual problems. To learn how the
site is used day to day, read the [User Manual](MANUAL.md).

- [Prerequisites](#prerequisites)
- [Installation](#installation)
- [Scheduled jobs (cron)](#scheduled-jobs-cron)
- [Connecting the chest collector](#connecting-the-chest-collector)
- [Updating](#updating)
- [Troubleshooting](#troubleshooting)
- [Useful commands](#useful-commands)
- [Project structure](#project-structure)

Related: [INSTALL_DATABASE.md](INSTALL_DATABASE.md) (database details) ·
[FIRST_ADMIN.md](FIRST_ADMIN.md) (first administrator).

---

## Prerequisites

- **PHP >= 8.1** with the extensions `pdo_mysql`, `mbstring`, `intl`, `openssl`,
  `json`, `xml`, `curl` and `gd` (`gd` validates and converts uploaded logos,
  favicons and banners)
- **MySQL 5.7+** or **MariaDB 10.3+**
- **Composer**, **Git** and **SSH** access to the server
- **Apache** with `mod_rewrite` (or Nginx with an equivalent rewrite)
- `crontab` and the `mysqldump` client, for the scheduled maintenance and backup
- **Remote MySQL access** from the computer that runs the chest collector, since
  the collector writes chests straight into the database (see
  [Connecting the chest collector](#connecting-the-chest-collector))

One installation serves **one clan**. To host several clans, install one copy per
clan, each in its own folder with its own database.

---

## Installation

### 1. Connect and choose the folder

```bash
ssh user@your-server.com
cd ~/public_html        # or /var/www/html, or the folder your host uses
```

### 2. Clone the repository

```bash
git clone https://github.com/crashbrtb/tbops.git .
```

The trailing `.` extracts the files into the current folder. For several clans,
create one folder per clan and clone into each.

### 3. Install the dependencies

```bash
composer install --no-dev --optimize-autoloader
```

Without a global Composer:

```bash
php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
php composer-setup.php
php -r "unlink('composer-setup.php');"
php composer.phar install --no-dev --optimize-autoloader
```

> If it fails on disabled functions, try
> `php -d disable_functions="" composer install`.

### 4. Create the database

```sql
CREATE DATABASE tbops CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'tbops_user'@'localhost' IDENTIFIED BY 'secure_password_here';
GRANT ALL PRIVILEGES ON tbops.* TO 'tbops_user'@'localhost';
FLUSH PRIVILEGES;
```

Replace the user name and password with your own.

### 5. Configure `config/app_local.php`

```bash
cp config/app_local.example.php config/app_local.php
vi config/app_local.php
```

> In `vi`: press `i` to edit, `:wq` to save and exit, `:q!` to exit without saving.

```php
<?php
return [
    'debug' => filter_var(env('DEBUG', false), FILTER_VALIDATE_BOOLEAN),

    'Security' => [
        'salt' => env('SECURITY_SALT', 'your_very_long_and_random_secret_key_here'),
    ],

    'Datasources' => [
        'default' => [
            'host' => 'localhost',
            'username' => 'tbops_user',
            'password' => 'secure_password_here',
            'database' => 'tbops',
            'encoding' => 'utf8mb4',
        ],
    ],

    // Optional: "Sign in with Google". Create a Web application OAuth client at
    // https://console.cloud.google.com/apis/credentials and add your domain to
    // "Authorized JavaScript origins".
    'Google' => [
        'clientId' => env('GOOGLE_CLIENT_ID', 'YOUR_GOOGLE_CLIENT_ID_HERE'),
    ],
];
```

Generate a salt with:

```bash
php -r "echo hash('sha256', random_bytes(64));"
```

### 6. Directory permissions

```bash
mkdir -p tmp/cache/models tmp/cache/persistent tmp/cache/views tmp/sessions logs
chmod -R 775 tmp logs
chown -R username:username tmp logs
```

Replace `username` with the user the web server runs as (`apache`, `nginx`,
`www-data`, or your own account on shared hosting).

### 7. Create the tables and the initial data

Both steps are required: migrations create empty tables, the seed fills them.

```bash
php bin/cake.php migrations migrate   # tables, indexes, foreign keys
php bin/cake.php migrations seed      # roles, configs, chest catalogue, tournament catalogue
```

Check it:

```sql
SELECT COUNT(*) FROM roles;            -- 3
SELECT COUNT(*) FROM config;           -- 21 or more
SELECT COUNT(*) FROM standard_chests;  -- 96
SELECT COUNT(*) FROM game_tournaments; -- tournament catalogue, grows over time
```

If `config` has fewer rows, pages fail to render because parameters such as
`reference_day` are missing. Re-run the seed: it only inserts what is absent.

### 8. Create the first administrator

```bash
php bin/cake.php create_admin
# or, without prompts:
php bin/cake.php create_admin --name "Administrator" --email "admin@example.com" --password "secure_password"
```

See [FIRST_ADMIN.md](FIRST_ADMIN.md) for details.

### 9. Web server

The `.htaccess` files already send every request to `webroot/index.php`; Apache
only needs `mod_rewrite`. If the site lives at the **domain root**, comment out
`RewriteBase /tbops/` in `webroot/.htaccess`; in a **subfolder**, set
`RewriteBase` to that path.

### 10. Set your clan's values

Sign in and open **Admin › System › Configs**. The seed ships placeholders
(`K001`, `ABC`, ...). Set at least:

| Parameter | What to put |
|---|---|
| `clan_name`, `clan_acronym`, `kingdom_number` | Your clan |
| `reference_day` | Any past date on which a counting cycle started, in UTC (`2025-07-30 17:00:00`) |
| `every_how_many_days` | Cycle length in days (7 = weekly) |
| `minimum_chest_score`, `minimum_epic_chest_score` | The chest goals |

The [User Manual](MANUAL.md#system-configs) explains every parameter.

### 11. Final checks

1. Open `https://your-domain.com`: the scoreboard should load.
2. Sign in with the administrator from step 8.
3. Make sure `debug` is `false` in `config/app_local.php`.

---

## Scheduled jobs (cron)

Nothing inside the site runs on its own. Without cron, finished cycles are never
archived, closed events have no recorded result and old chests are never purged.

### Daily maintenance

`daily_maintenance` runs, in order:

1. **Cycle summaries**: calculates and archives the scores of every finished cycle.
2. **Event results**: records the final standings of events whose window closed,
   before their chests are purged.
3. **Chest purge**: deletes collected chests older than
   `collected_chests_retention_days` (default 30).

It exits with an error, and records which step failed, when one step fails.

**From the site (recommended):** open **Admin › System › Maintenance**. The page
reads the web server user's crontab, says whether the job is scheduled and
installs it with **Install in crontab**. Times are picked on the Brazil clock
(`America/Sao_Paulo`) and written in UTC with `CRON_TZ=UTC`. The suggestion is
14:15 and 02:15 Brazil time (17:15 and 05:15 UTC). Only the lines between the
page's own marker comments are rewritten.

> Installing from the page needs PHP's `exec()` and the `crontab` command. When
> they are not available, the page shows the block to paste by hand.

**By hand:**

```bash
crontab -e
```

```cron
CRON_TZ=UTC
# 02:15 America/Sao_Paulo = 05:15 UTC
15 5 * * * cd /var/www/html/tbops && /usr/bin/php bin/cake.php daily_maintenance >> /var/www/html/tbops/logs/maintenance_cron.log 2>&1
# 14:15 America/Sao_Paulo = 17:15 UTC
15 17 * * * cd /var/www/html/tbops && /usr/bin/php bin/cake.php daily_maintenance >> /var/www/html/tbops/logs/maintenance_cron.log 2>&1
# Database backup at the 17:00 UTC game reset (only if you turned it on)
0 17 * * * cd /var/www/html/tbops && /usr/bin/php bin/cake.php database_backup >> /var/www/html/tbops/logs/database_backup_cron.log 2>&1
```

Replace `/var/www/html/tbops` with your path. If your cron does not support
`CRON_TZ`, drop that line and write the hours in the server's timezone.

Test without changing anything:

```bash
php bin/cake.php daily_maintenance --dry-run
```

### Nightly database backup

`database_backup` writes a gzipped `mysqldump` of the whole database as
`backup_<database>_<date>.sql.gz`, logs each step to `backup.log` in the same
folder and deletes dumps older than the retention period. It is **off** until
turned on in **Admin › System › Maintenance**:

| Setting | Parameter | Default |
|---|---|---|
| Whether it runs | `database_backup_enabled` | off |
| Folder | `database_backup_dir` | `~/bkpdb` |
| Retention | `database_backup_retention_days` | 7 days |

The folder must be a full path (`~` is the web server user's home); it is
created on the first run. The time is fixed at **17:00 UTC**, the game's daily
reset. Credentials come from the site's own database connection and are passed
to `mysqldump` through a temporary options file, never on the command line.

```bash
php bin/cake.php database_backup            # back up now
php bin/cake.php database_backup --dry-run  # show what it would write and delete
php bin/cake.php database_backup --force    # run even while turned off
```

Needs the `mysqldump` client on the server.

### Monitoring the jobs

Every job writes a heartbeat to the `job_runs` table (`running`, then `success`,
`partial`, `failed` or `cancelled`).

| Job | Written by | Watched by default |
|---|---|---|
| `collector` | the chest collector, straight into MySQL | yes, 180 min |
| `daily_maintenance` | `daily_maintenance` | yes, 780 min |
| `database_backup` | `database_backup` | yes, 1500 min |
| `tournament_import` | the tournament upload API | no |
| `member_list` | the members list API | no |

A job is **down** when it had no good run within its limit, or its last run
never closed. A run that found no chests is still a good run: the process is
watched, not the data.

**Admin › System › Monitoring** shows each job, the last 50 runs and the limits,
and holds the read-only key for an external monitor:

```bash
curl -H "X-Monitor-Key: <key>" https://your-domain.com/api/v1/health.json
```

It answers `200` with the right key (`401` without), with `ok: false` when a job
is down. The alarm must come from outside the server: the Monitoring page carries
a ready-made Google Apps Script that checks every 30 minutes and e-mails you only
when a job stops or comes back.

> Set the `collector` limit a little above the interval the collector is
> scheduled at on Windows, or every gap between runs looks like an outage.

---

## Connecting the chest collector

The collector ([chestcounter_collector](https://github.com/crashbrtb/chestcounter_collector))
runs on a Windows PC and needs two things from this site:

1. **Database access**, for chests and the heartbeat. Create a MySQL user that
   may connect from the collector PC's address (most hosts call this "Remote
   MySQL") with `SELECT, INSERT, UPDATE, DELETE` on the site's database:

   ```sql
   CREATE USER 'collector'@'203.0.113.10' IDENTIFIED BY 'another_password';
   GRANT SELECT, INSERT, UPDATE, DELETE ON tbops.* TO 'collector'@'203.0.113.10';
   FLUSH PRIVILEGES;
   ```

2. **An API token**, for tournament rankings, the members list and the live
   tournaments. Create it in **Admin › System › API Tokens**; it is shown once.

Then fill both into the collector's *Configure* window. The
[User Manual](MANUAL.md#the-chest-collector) describes the whole setup.

If your host runs PHP as FastCGI and drops the `Authorization` header, add this
line to `webroot/.htaccess` right after `RewriteEngine On` (the collector also
sends `X-Api-Token`, which usually avoids the problem):

```apache
RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]
```

---

## Updating

Updates come from the same Git repository. Local settings are not versioned
(`config/app_local.php`, `logs/` and `tmp/` are in `.gitignore`), so credentials,
salt and sessions survive the update.

```bash
cd /var/www/html/tbops

# 1. Back up
mysqldump -u user -p tbops > backup_$(date +%Y%m%d).sql
cp config/app_local.php config/app_local.php.bak

# 2. Pull the new version (replace main with your branch if needed)
git pull origin main

# 3. Dependencies
composer install --no-dev --optimize-autoloader

# 4. New tables/columns, then new configs and catalogue rows (both idempotent)
php bin/cake.php migrations migrate
php bin/cake.php migrations seed

# 5. Cache and permissions
rm -rf tmp/cache/models/* tmp/cache/persistent/* tmp/cache/views/*
chmod -R 775 tmp logs
chown -R username:username tmp logs
```

`php bin/cake.php migrations status` lists what is pending before applying it.

After updating: sign in, check that **Configs** still shows your clan's values,
and watch `tail -f logs/error.log`. If a release ships new emblems, run
`php bin/cake.php branding_presets`.

### Problems during `git pull`

**`Your local changes ... would be overwritten by merge`**: a versioned file was
edited on the server.

```bash
git status                  # see what changed
git stash && git pull origin main && git stash pop   # keep your edits
git checkout -- path/to/file                         # or discard one file
```

**`Permission denied`**: run the update as the folder's owner (or with `sudo`),
then restore ownership of `tmp` and `logs`.

**Blank page after updating**: almost always a missing migration/seed or a stale
cache. Repeat steps 4 and 5 and read `logs/error.log`.

---

## Troubleshooting

### Error 500

1. Read the logs: `tail -f logs/error.log logs/debug.log`
2. Check permissions on `tmp` and `logs` (step 6).
3. Check `config/app_local.php`: `php -l config/app_local.php`; the salt must not
   be `__SALT__`; the database credentials must be right.
4. Check `RewriteBase` in `webroot/.htaccess` (step 9).
5. Clear the cache: `rm -rf tmp/cache/*`
6. Only to diagnose, set `'debug' => true` in `app_local.php`, and turn it off
   again afterwards.

### The scoreboard shows no new chests

Open **Admin › System › Monitoring**. If the `collector` job is down, the problem
is on the collector PC: read its `execution_logs\collector_<date>.log` (the
**RUN SUMMARY** block at the end says what failed) or run `tbcollector diagnose`.

### Logo upload rejected / no emblem artwork

Uploads need the GD extension. To redraw the shipped emblems:

```bash
php bin/cake.php branding_presets            # draw the missing ones
php bin/cake.php branding_presets --force    # redraw all
php bin/cake.php branding_presets --only=dragon-crest
```

---

## Useful commands

```bash
php bin/cake.php create_admin                    # first administrator
php bin/cake.php migrations status               # pending migrations
php bin/cake.php daily_maintenance --dry-run     # maintenance, simulated
php bin/cake.php daily_maintenance               # maintenance, for real
php bin/cake.php database_backup                 # back up now
php bin/cake.php branding_presets --force        # redraw the emblems
rm -rf tmp/cache/*                               # clear the cache
tail -f logs/error.log                           # follow the error log
```

---

## Project structure

```
tbops/
├── bin/                    # cake console
├── config/
│   ├── Migrations/         # database schema
│   ├── Seeds/              # initial data (roles, configs, catalogues)
│   ├── data/               # tournament catalogue shipped with the app
│   └── app_local.php       # local settings (not versioned)
├── resources/locales/      # translations (10 languages)
├── src/
│   ├── Command/            # console commands (maintenance, backup, ...)
│   ├── Controller/         # pages; Controller/Api = collector and health API
│   ├── Model/              # tables and entities
│   ├── Service/            # business rules (goals, penalties, rewards, fines, bank)
│   └── View/
├── templates/              # page templates
├── tests/
└── webroot/                # public entry point, CSS, JS, branding images
```

### Developer notes

- **Themes**: `webroot/css/theme-score.css` declares the colour tokens and
  `webroot/css/themes.css` redefines them per theme. To add a theme, add a
  palette there and an entry in `src/Service/ThemeService.php`.
  `tests/TestCase/Service/ThemeServiceTest.php` requires every palette to define
  the whole token set and to meet WCAG AA contrast.
- **Tournament catalogue shipped with the app**:
  `config/data/game_tournament_defaults.php`, loaded by `GameTournamentsSeed`
  only for game types not yet in `game_tournaments`, so it never overwrites what
  an administrator changed.
- **Translations**: every UI string must exist in all 10 `resources/locales/*/default.po`
  files. Delete `tmp/cache/persistent/myapp_cake_core_translations.*` after
  editing them.

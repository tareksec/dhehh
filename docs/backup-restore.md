# Backup and Restore Guide

Business Dashboard includes database backups, full app backups, backup downloads, and optional encrypted off-site copies on Google Drive.

## Backup Types

| Type | Contents | Location |
|---|---|---|
| Database backup | MySQL/MariaDB dump (`.sql.gz`, compressed) or SQLite file (`.sqlite`) | `storage/app/private/backups/database` |
| Full app backup | App files plus a database backup of the same kind (`app-backup-20261023-020000-daily.zip`) | `storage/app/private/backups/app` |
| Google Drive copy | The same files, **encrypted** (`….sql.gz.enc`, `….zip.enc`), sent after they are made | Configured Drive folder |

Every backup runs under one **kind**, which is the last part of the file name
(`database-backup-20261023-020000-mysql-daily.sql.gz`):

| Kind | Made by | Kept (default) |
|---|---|---|
| `hourly` | the hourly schedule: a database backup at five past every hour | latest 48 |
| `daily` | the daily schedule at `BACKUP_SCHEDULE_TIME`: a full app backup, which holds that day's database backup | database latest 30; full app archives latest 10 |
| `predeploy` | `deploy:migrate` right before migrations that are pending, or `php artisan backup:database --kind=predeploy` by hand | database latest 10; archives latest 3 |
| `manual` | the **Create Backup** / **Create App Backup** buttons, or the commands with no kind. Files from before kinds existed count as `manual` | latest 10 |

Each kind is pruned on its own, so a stream of hourly files can never push out
a daily or pre-deploy backup, and a manual backup never pushes out a daily one.

## Schedules

All of these run only while the scheduler runs (`php artisan schedule:run` every
minute, see `docs/deployment.md`):

| When | What |
|---|---|
| every hour at :05 | `backup:database --kind=hourly --auto-upload` (switch off with `BACKUP_HOURLY_ENABLED=false`) |
| every day at `BACKUP_SCHEDULE_TIME` (02:00) | `backup:app --kind=daily --auto-upload`: files + that day's database backup in one zip. With `BACKUP_DAILY_FULL_APP=false` it is `backup:database --kind=daily --auto-upload` (database only) instead |

Both run in the background, so a slow upload never holds up the other scheduled
jobs. Each job has an overlap lock that expires (1 hour for a database backup
and its upload, 2 hours for the full backup), and every run is recorded under
**Backup history**. Before migrations
that are pending, `deploy:migrate` takes a `predeploy` database backup; if that
backup fails the failure is reported to the Super Admins and the migrations
**still run** (the deploy command never stops the app from starting).

## Create Database Backup

From CLI:

```bash
php artisan backup:database                      # manual
php artisan backup:database --kind=predeploy     # before an update
```

From the admin panel:

```txt
/admin/settings/backups
```

Database backup supports SQLite and MySQL/MariaDB.

For MySQL the server needs the MySQL client tools (`mysqldump`). The production
`Dockerfile` installs them (`default-mysql-client`); on the old Nixpacks setup
they are not there and the backup fails with "[mysqldump] was not found". The
dump is streamed through gzip into a `.part` file and only renamed once it ended
with mysqldump's "Dump completed" line, so a half-written dump never shows up in
the list. The database password is given to mysqldump through a temporary
option file (mode 0600), not on the command line.

Create a database backup before every production update, before running migrations, and before changing server database/storage settings.

Every attempt, successful or not, is listed under **Backup history** on the
Backups page with its size, checksum (SHA-256) or the reason it failed. A
scheduled backup that fails is also reported to Super Admins (Deploy Error
Logs and the bell), once per run of failures, so a failing hourly job does not
send 24 alerts a day.

## Verify a Backup

```bash
php artisan backup:verify                                  # the newest backup
php artisan backup:verify database-backup-YYYYMMDD-HHMMSS-mysql-daily.sql.gz
```

- **SQLite:** the backup is restored into an automatically deleted disposable database and integrity-checked.
- **MySQL dump (`.sql.gz`):** the file is read to the end and must finish with the "Dump completed" line and contain the `migrations` table, and its SHA-256 must still match the one recorded when it was made.

This command never overwrites the configured database. It does **not** load a MySQL dump into a database: a full restore drill into a scratch MySQL database should still be done by hand (manual procedure below) until it is automated.

## Create Full App Backup

Use the Backups page in the admin panel:

```txt
/admin/settings/backups
```

Or from the CLI:

```bash
php artisan backup:app                       # manual
php artisan backup:app --kind=predeploy
php artisan backup:app --kind=daily --upload # also upload to Google Drive
```

Full app backups require PHP Zip extension. The archive is written to a `.part`
file and renamed when complete; the database backup is made first, so it exists
even if the archive step fails (for example a full disk). The archive holds the
folders listed in `config/backup.php` (`app.include_paths`): the code, the
uploads in `storage/app/public`, the private documents in `storage/app/private`
(KYC and other files kept off the public disk; the backup folders themselves
and temporary uploads are left out) and **`.env` with every secret**. The copy
on this server is **not encrypted**; the copy sent to Google Drive is (see
below). Files kept on Cloudflare R2 are not part of the archive.

## Retention

Configure retention in `.env`:

```env
# database backups
BACKUP_KEEP_HOURLY=48
BACKUP_KEEP_DAILY=30
BACKUP_KEEP_PREDEPLOY=10
# full app archives (they hold the uploaded files, so they are the big ones)
BACKUP_APP_KEEP_DAILY=10
BACKUP_APP_KEEP_PREDEPLOY=3
# manual backups, database and full app
BACKUP_RETAIN_FILES=10
```

Old backups beyond the retention count of their kind are cleaned automatically by the backup services, and so are half-written `.part` files older than 6 hours. At least one file of each kind is always kept. These numbers are for the copies on this server; the copies on Google Drive have their own (see below).

Other settings: `BACKUP_MYSQLDUMP_BINARY` (default `mysqldump`) and `BACKUP_DUMP_TIMEOUT` (seconds, default 900).

## Google Drive: the off-site copy

A backup that sits only on the server is lost with the server (or with a
redeploy that replaces the container's disk). The off-site copy is the one that
survives.

Configure (or use **Configure** on the Backups page, which stores the same
values, with the service account JSON encrypted):

```env
GOOGLE_DRIVE_BACKUP_ENABLED=true
GOOGLE_DRIVE_BACKUP_AUTO_UPLOAD=true
GOOGLE_DRIVE_BACKUP_FOLDER_ID=
GOOGLE_DRIVE_SERVICE_ACCOUNT_PATH=
GOOGLE_DRIVE_SERVICE_ACCOUNT_JSON=
BACKUP_ENCRYPTION_KEY=
```

Use either service account JSON content or a path to a service account JSON file.
Use a folder in a **Shared Drive**, and give the service account the **Content
manager** role there: a service account has no storage quota of its own (Google
refuses uploads into a normal "My Drive" folder with "Service Accounts do not
have storage quota"), and it needs to delete old copies.

### What is sent

With **Enable Google Drive backup** and **Auto upload to Drive** both on:

| Backup | Sent to Drive | Copies kept on Drive (default) |
|---|---|---|
| hourly database backup | yes | latest 48 (`BACKUP_DRIVE_KEEP_HOURLY`) |
| daily full app backup | yes | latest 7 (`BACKUP_DRIVE_APP_KEEP_DAILY`) |
| `deploy:migrate` pre-deploy database backup | yes | latest 10 (`BACKUP_DRIVE_KEEP_PREDEPLOY`) |
| daily database-only backup (`BACKUP_DAILY_FULL_APP=false`) | yes | latest 30 (`BACKUP_DRIVE_KEEP_DAILY`) |
| manual backups | only with **Upload to Drive** (full app) or `--upload` | latest 10 (database), 5 (full app) |

`BACKUP_OFFSITE_DATABASE_KINDS` (default `hourly,daily,predeploy`) and
`BACKUP_OFFSITE_APP_KINDS` (default `daily`) choose which kinds `--auto-upload`
sends. After every upload the folder is trimmed to the newest copies of each
kind; only files named like our backups (`database-backup-…`, `app-backup-…`,
with or without `.enc`) that this service account created are ever deleted, so
anything else in the folder is safe. Older uploads made before backup types
existed have no type in their name and count as `manual`.

The upload is streamed in 8 MiB pieces (`GOOGLE_DRIVE_BACKUP_CHUNK_BYTES`): a
dropped connection costs one piece, which is retried, and a large archive is
never loaded into memory. If Drive is not set up properly, the key is missing or
the upload fails, the run is recorded as failed with the reason and reported to
the Super Admins; the backup itself stays on the server and the local retention
still applies. A problem while trimming Drive is reported under
`backup:offsite-prune` and never fails the backup.

### Encryption

A database dump holds every customer and the app archive holds `.env`, so nothing
is uploaded in the clear. Set `BACKUP_ENCRYPTION_KEY` (generate one with
`openssl rand -base64 32`, at least 16 characters). Until it is set, **nothing is
uploaded** and each attempt fails with a message saying so; the Backups page
shows the state under **Off-site encryption**. (`BACKUP_OFFSITE_REQUIRE_ENCRYPTION=false`
removes the guard for an owner who accepts plain copies on Drive.)

- **Keep a copy of the key outside the server** (a password manager, a printed
  page in a safe). It is a separate passphrase, not `APP_KEY`, on purpose:
  `APP_KEY` is inside the archive, so it would be lost together with the server.
  **Without the key the off-site copies cannot be opened by anyone**, and
  changing the key does not re-encrypt copies already on Drive.
- Only the Drive copy is encrypted. Files on this server stay readable, so
  **Download**, **Verify** and the restore steps below work on them as before.
- The format is exactly what `openssl enc` writes, so a copy opens with plain
  `openssl` on any computer, with no Laravel and no PHP:

```bash
BACKUP_KEY='the key' openssl enc -d -aes-256-cbc -pbkdf2 -iter 100000 -md sha256 \
  -in database-backup-20261023-120500-mysql-hourly.sql.gz.enc \
  -out database-backup-20261023-120500-mysql-hourly.sql.gz -pass env:BACKUP_KEY
```

  Inside the app: `php artisan backup:decrypt path/to/file.sql.gz.enc` (uses
  `BACKUP_ENCRYPTION_KEY`, or asks for the key; add `--output=` for another
  name, `--force` to replace a file). A wrong key or a damaged file is reported
  and nothing is written.
- The cipher is AES-256-CBC with a key derived by PBKDF2 (100,000 rounds, random
  salt per file). CBC does not detect deliberate tampering; accidental damage is
  caught by the checksums inside the gzip / zip and by the SHA-256 recorded in
  **Backup history**.

### Restoring from Google Drive

1. Download the newest copy you want from the Drive folder (the file name tells
   the type and the time).
2. Decrypt it with one of the commands above.
3. A `.sql.gz` is restored with **Restore MySQL/MariaDB Backup** below. A `.zip`
   holds the code and files plus the `database/` dump of that day.

## Download Permissions

Backup download requires:

- Super Admin, or
- `backups.manage`

Backup downloads are written to the audit log as `backup_downloaded`.

## Restore SQLite Backup

1. Put the app in maintenance mode:

```bash
php artisan down
```

2. Copy the selected backup over the active database file:

```bash
cp storage/app/private/backups/database/database-backup-YYYYMMDD-HHMMSS-sqlite-daily.sqlite database/database.sqlite
```

3. Clear caches and bring the app back:

```bash
php artisan optimize:clear
php artisan up
```

## Restore MySQL/MariaDB Backup

1. Put the app in maintenance mode:

```bash
php artisan down
```

2. Import the selected dump (new backups are gzip-compressed; very old `.sql` files are loaded with `mysql ... < file.sql`):

```bash
gunzip -c storage/app/private/backups/database/database-backup-YYYYMMDD-HHMMSS-mysql-daily.sql.gz | mysql -u DB_USERNAME -p DB_DATABASE
```

   The dump sets its own character set, so Bangla text restores correctly without any extra option.

3. Run migrations if the code is newer than the backup:

```bash
php artisan migrate --force
```

4. Clear caches and bring the app back:

```bash
php artisan optimize:clear
php artisan up
```

## Restore Checklist

1. Confirm the backup file date and environment.
2. Download a copy before restoring.
3. Put the app in maintenance mode.
4. Restore database.
5. Run migrations if needed.
6. Clear caches.
7. Verify admin login.
8. Check dashboard totals.
9. Check recent orders, purchases, payments, and stock.
10. Review audit logs.
11. Verify company settings and invoice branding.
12. Confirm `/health/version` shows the expected app version after the restore or redeploy.

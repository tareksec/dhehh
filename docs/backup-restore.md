# Backup and Restore Guide

Business Dashboard includes database backups, full app backups, backup downloads, and optional Google Drive upload.

## Backup Types

| Type | Contents | Location |
|---|---|---|
| Database backup | MySQL/MariaDB dump (`.sql.gz`, compressed) or SQLite file (`.sqlite`) | `storage/app/private/backups/database` |
| Full app backup | App files plus a database backup of the same kind (`app-backup-20261023-020000-daily.zip`) | `storage/app/private/backups/app` |
| Google Drive backup | Uploaded full app backup | Configured Drive folder |

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
| every hour at :05 | `backup:database --kind=hourly` (switch off with `BACKUP_HOURLY_ENABLED=false`) |
| every day at `BACKUP_SCHEDULE_TIME` (02:00) | `backup:app --kind=daily --auto-upload`: files + that day's database backup in one zip. With `BACKUP_DAILY_FULL_APP=false` it is `backup:database --kind=daily` (database only) instead |

Each job has an overlap lock that expires (30 minutes, 2 hours for the full
backup), and every run is recorded under **Backup history**. Before migrations
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
folders listed in `config/backup.php` (`app.include_paths`), **including `.env`
with every secret, unencrypted** - treat the archive, and the Drive folder it
is uploaded to, as secret (encryption is a later step of the backup plan).

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

Old backups beyond the retention count of their kind are cleaned automatically by the backup services, and so are half-written `.part` files older than 6 hours. At least one file of each kind is always kept. Only the copies on this server are pruned; files already uploaded to Google Drive are not deleted yet.

Other settings: `BACKUP_MYSQLDUMP_BINARY` (default `mysqldump`) and `BACKUP_DUMP_TIMEOUT` (seconds, default 900).

## Google Drive Upload

Configure:

```env
GOOGLE_DRIVE_BACKUP_ENABLED=true
GOOGLE_DRIVE_BACKUP_AUTO_UPLOAD=true
GOOGLE_DRIVE_BACKUP_FOLDER_ID=
GOOGLE_DRIVE_SERVICE_ACCOUNT_PATH=
GOOGLE_DRIVE_SERVICE_ACCOUNT_JSON=
```

Use either service account JSON content or a path to a service account JSON file.

The upload is streamed, so a large archive is never loaded into memory. With
**Enable Google Drive backup** and **Auto upload daily** both on, the daily full
app backup is uploaded after it is made; if Drive is not set up properly, or the
upload fails, the run is recorded as failed with the reason and reported to the
Super Admins (the archive stays on the server). Use a folder in a **Shared
Drive**: a service account has no storage quota of its own, and Google may refuse
uploads into a normal "My Drive" folder with "Service Accounts do not have
storage quota".

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

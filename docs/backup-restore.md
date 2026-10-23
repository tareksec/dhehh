# Backup and Restore Guide

Business Dashboard includes database backups, full app backups, backup downloads, and optional Google Drive upload.

## Backup Types

| Type | Contents | Location |
|---|---|---|
| Database backup | MySQL/MariaDB dump (`.sql.gz`, compressed) or SQLite file (`.sqlite`) | `storage/app/private/backups/database` |
| Full app backup | App files plus database backup | `storage/app/private/backups/app` |
| Google Drive backup | Uploaded full app backup | Configured Drive folder |

Every backup runs under one **kind**, which is the last part of the file name
(`database-backup-20261023-020000-mysql-daily.sql.gz`):

| Kind | Made by | Kept (default) |
|---|---|---|
| `hourly` | the hourly schedule (not switched on yet) | latest 48 |
| `daily` | the daily schedule at `BACKUP_SCHEDULE_TIME` | latest 30 |
| `predeploy` | `php artisan backup:database --kind=predeploy`, before an update | latest 10 |
| `manual` | the **Create Backup** button, or `backup:database` with no kind. Files from before kinds existed count as `manual` | latest 10 |

Each kind is pruned on its own, so a stream of hourly files can never push out
a daily or pre-deploy backup.

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

Full app backups require PHP Zip extension.

## Retention

Configure retention in `.env`:

```env
BACKUP_KEEP_HOURLY=48
BACKUP_KEEP_DAILY=30
BACKUP_KEEP_PREDEPLOY=10
BACKUP_RETAIN_FILES=10     # manual backups, and full app backups of every kind
```

Old backups beyond the retention count of their kind are cleaned automatically by the backup services. At least one file of each kind is always kept.

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

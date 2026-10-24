# Backup and Restore Guide

Business Dashboard includes database backups, full app backups, backup downloads, and optional encrypted off-site copies on Cloudflare R2 and/or Google Drive.

## Backup Types

| Type | Contents | Location |
|---|---|---|
| Database backup | MySQL/MariaDB dump (`.sql.gz`, compressed) or SQLite file (`.sqlite`) | `storage/app/private/backups/database` |
| Full app backup | App files plus a database backup of the same kind (`app-backup-20261023-020000-daily.zip`) | `storage/app/private/backups/app` |
| Off-site copy | The same files, **encrypted** (`….sql.gz.enc`, `….zip.enc`), sent after they are made | Cloudflare R2 bucket and/or Google Drive folder (either, or both) |

Every backup runs under one **kind**, which is the last part of the file name
(`database-backup-20261023-020000-mysql-daily.sql.gz`):

| Kind | Made by | Kept (default) |
|---|---|---|
| `hourly` | the hourly schedule: a database backup at five past every hour | latest 48 |
| `daily` | the daily schedule at `BACKUP_SCHEDULE_TIME`: a full app backup, which holds that day's database backup | database latest 30; full app archives latest 10 |
| `predeploy` | `deploy:migrate` right before migrations that are pending, or `php artisan backup:database --kind=predeploy` by hand | database latest 10; archives latest 3 |
| `prerestore` | `backup:restore`, right before it replaces the database: the way back if the restore was a mistake. Never sent to Drive | latest 5 (`BACKUP_KEEP_PRERESTORE`) |
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
| every Sunday at 03:30 (`BACKUP_DRILL_SCHEDULE_TIME`) | `backup:drill`: the restore test of the newest dump on this server. At 03:50, with R2 on, `backup:drill --r2` tests the newest R2 copy; at 04:10, with Drive on, `backup:drill --drive` tests the newest Drive copy. Only when `BACKUP_DRILL_DB_USERNAME` is set (see **Restore test**) |
| every 30 minutes | `backup:check`: tells the Super Admins when a backup is overdue (see **Backup health**) |

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

This command never overwrites the configured database. It does **not** load the dump into a database; that is the next section.

## Restore test (backup:drill)

A file that is complete and has the right checksum can still fail to load. The restore
test loads the newest dump into a scratch MySQL database, checks what came back, and
**always drops the scratch database again**. The live database is never touched.

```bash
php artisan backup:drill                                    # the newest .sql.gz on this server
php artisan backup:drill --file=database-backup-YYYYMMDD-HHMMSS-mysql-hourly.sql.gz
php artisan backup:drill --offsite                          # downloads, decrypts and tests the newest Drive copy
```

What it checks: the dump is complete and matches its recorded checksum; it loads without
an SQL error; the restored database has as many tables as the dump defines and a filled
`migrations` table. The result ("162 tables, 264 migrations, latest …, 8 s") is in
**Backup history** under *Restore test*; a failure is reported to the Super Admins like
any other backup failure. `--offsite` is the one that proves the Drive copy and the
`BACKUP_ENCRYPTION_KEY` really work together, which nothing else does until the day the
server is gone.

It needs a MySQL user that may create databases. The app's own user usually may not, and
should not, so make a second one that can only touch schemas named `backup_drill_…`:

```sql
CREATE USER 'drill'@'%' IDENTIFIED BY 'a long password';
GRANT ALL ON `backup\_drill\_%`.* TO 'drill'@'%';
```

(use the host the app connects from instead of `%` if you know it), then set in the app's
environment:

```env
BACKUP_DRILL_DB_USERNAME=drill
BACKUP_DRILL_DB_PASSWORD=
# BACKUP_DRILL_DB_HOST / BACKUP_DRILL_DB_PORT default to DB_HOST / DB_PORT
```

Until `BACKUP_DRILL_DB_USERNAME` is set nothing is scheduled and **Restore test** on the
dashboard card says "Not set up". The scratch database is named
`backup_drill_{time}_{random}`; one left behind by a killed run is dropped by the next run
once it is a day old. The `mysql` client comes with `default-mysql-client` in the
Dockerfile (`BACKUP_MYSQL_BINARY` to change it). The import may take as long as the
dump does to load: `BACKUP_DRILL_TIMEOUT` (default 1800 seconds).

## Backup health

The dashboard shows a **Backup health** card to people who may manage backups: the
hourly database backup, the daily backup, the copy on Google Drive (when Drive and
auto upload are on) and the restore test (when set up), each with its age and, when
something is overdue, the last error.

| Check | Overdue after | Setting |
|---|---|---|
| hourly database backup | 150 minutes | `BACKUP_STALE_HOURLY_MINUTES` |
| daily backup | 30 hours | `BACKUP_STALE_DAILY_HOURS` |
| copy on Google Drive | the same limit as the backup it follows | |
| restore test | 9 days (a warning, not an alarm) | `BACKUP_STALE_DRILL_DAYS` |

`backup:check` runs every 30 minutes and tells the Super Admins (Deploy Error Logs and the
bell) when a check is overdue: once, then again every 12 hours while it stays that way.
Nothing recorded at all is "waiting", not an alarm, until backups have existed for longer
than the allowed age.

**What this cannot catch: the scheduler itself stopping.** Every job above, `backup:check`
included, runs from `php artisan schedule:run`; if that Coolify scheduled task is missing
or dead, nothing runs and nobody is told. For that, point an outside uptime monitor
(UptimeRobot, Better Stack, …) at:

```
https://your-erp-domain/health/backups?token=THE_TOKEN
```

after setting `BACKUP_HEALTH_TOKEN` (any long random string; the address answers 404 without
it). It returns **200** while backups are fresh and **503** when one is overdue or none was
ever recorded, with only check names and states in the body. A monitor that can send
headers may use `Authorization: Bearer THE_TOKEN` instead, which keeps the token out of
access logs.

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
# the safety copy backup:restore takes before it replaces the database
BACKUP_KEEP_PRERESTORE=5
# full app archives (they hold the uploaded files, so they are the big ones)
BACKUP_APP_KEEP_DAILY=10
BACKUP_APP_KEEP_PREDEPLOY=3
# manual backups, database and full app
BACKUP_RETAIN_FILES=10
```

Old backups beyond the retention count of their kind are cleaned automatically by the backup services, and so are half-written `.part` files older than 6 hours. At least one file of each kind is always kept. These numbers are for the copies on this server; the copies on Google Drive have their own (see below).

Other settings: `BACKUP_MYSQLDUMP_BINARY` (default `mysqldump`), `BACKUP_DUMP_TIMEOUT` (seconds, default 900), `BACKUP_MYSQL_BINARY` (the `mysql` client, default `mysql`) and `BACKUP_RESTORE_TIMEOUT` (seconds a restore's import may run, default 3600).

## Cloudflare R2: the off-site copy (no Google Workspace needed)

R2 is Cloudflare's S3-compatible storage. A private bucket in your own Cloudflare account keeps the
encrypted copy of every backup away from the server. It needs no Google Workspace (a free Gmail account
cannot be used for the Drive copy: see the next chapter), downloads are free, and the monthly free
allowance covers small backups completely. The **R2 Backups** page (Settings → R2 Backups) shows what is
stored, how much of the free allowance is used and what the bill is likely to be.

### Set it up

1. Create a Cloudflare account and open **R2 Object Storage** in the dashboard. Cloudflare may ask for a
   payment card before R2 can be used, even for the free allowance: use a card that works for dollar
   payments.
2. **Create bucket** for backups only, for example `zamzam-erp-backups`. Leave it **private**: do not
   connect a public domain and do not enable the `r2.dev` address. Do not reuse the buckets of
   *Cloud Storage* (product images, customer files): a backup bucket must hold nothing else.
3. **R2 → Manage R2 API Tokens → Create API token**: permission **Object Read & Write**, *Specify
   bucket(s)* → the backup bucket only. Copy the **Access Key ID** and the **Secret Access Key** at once:
   the secret is shown one time only. (Why a token of its own: if it leaks, it can reach the backups and
   nothing else; and a token for the media buckets cannot delete the backups.)
4. The **Account ID** is on the R2 overview page (right side). It is 32 letters and digits. For a bucket
   in the EU jurisdiction paste the full address
   `https://<account-id>.eu.r2.cloudflarestorage.com` instead.
5. In the ERP open **Settings → R2 Backups → Configure**: switch on **Enable Cloudflare R2 backup** and
   **Auto upload to R2**, fill in the Account ID, bucket name and the two keys. Press **Save**, then
   **Test connection**: it writes, reads, lists and deletes a small test file, which is what backups
   need. Then **Back up now** sends a first database backup.
6. Make sure `BACKUP_ENCRYPTION_KEY` is set (see **Encryption**) and a copy is kept outside the server.
   Nothing is uploaded without it.

The same values can come from the environment instead (what is saved on the page wins; the secret is
saved encrypted):

```env
BACKUP_R2_ENABLED=true
BACKUP_R2_AUTO_UPLOAD=true
BACKUP_R2_ACCOUNT_ID=          # or the full https://… endpoint address
BACKUP_R2_BUCKET=zamzam-erp-backups
BACKUP_R2_PREFIX=erp-backups   # the folder inside the bucket; everything the app writes or deletes stays in it
BACKUP_R2_ACCESS_KEY_ID=
BACKUP_R2_SECRET_ACCESS_KEY=
BACKUP_ENCRYPTION_KEY=
```

### What is sent, and what happens when something fails

The same kinds as for Drive (see **What is sent**; the numbers kept per kind are `backup.offsite.keep`,
the same for every place). The backup is encrypted once and the same file goes to every place that is
switched on:

- **Auto upload** is a switch of each place: a scheduled backup goes only to places with it on. A person
  pressing **Upload off-site** / **Back up now**, or running `--upload`, reaches every place that is on
  (or, when none is on, the ones that are set up).
- A place that fails does not stop the other one. The backup counts as sent when at least one place got
  it; the place that did not is named in the **Backup history** note ("Not sent to r2: …"), reported to
  the Super Admins, and its **Backup health** card ("Copy on Cloudflare R2") turns red when no copy has
  arrived in time. Only when no place got it is the run a failure.
- After every upload the folder is trimmed to the newest copies of each kind. Only files named like our
  backups (`database-backup-…`, `app-backup-…`, with or without `.enc`) inside the folder are ever
  deleted; any other file in the folder is counted in the bill and left alone. The app also refuses to
  download or delete anything outside the folder.
- Big files go up in parts of `BACKUP_R2_PART_BYTES` (default 16 MiB). A part that fails is retried from
  where the upload stopped; an upload that is given up is aborted, so no half-uploaded file is left. The
  stored size is checked after every upload.
- If R2 refuses, the error is explained in words: wrong Secret Access Key, unknown Access Key ID, token
  without access to the bucket, bucket not found, server cannot reach Cloudflare.

### What it costs

Prices below are Cloudflare's published prices for the **Standard** storage class, checked on
2026-10-23 (<https://developers.cloudflare.com/r2/pricing/>); they are in `config/backup.php`
(`r2.pricing`, or `BACKUP_R2_PRICE_*`) and must be updated there when Cloudflare changes them.

| Item | Price | Free every month (whole Cloudflare account) |
|---|---|---|
| Storage | $0.015 per GB-month | 10 GB-month |
| Class A operations (writes, uploads of parts, lists) | $4.50 per million | 1 million |
| Class B operations (reads, size checks) | $0.36 per million | 10 million |
| Deleting, aborting an upload | free | |
| Data going out (restoring, restore tests) | free | |

Storage is billed on the highest size of each day, averaged over the month. The free 10 GB is for the
whole account, so if the *Cloud Storage* buckets (product images, customer files) are on the same
account they use it too; the R2 Backups page cannot see them. Operations are never the problem: an hour
of backups is a handful of requests (for a 1 GB database about 63 an hour, against a million free a
month), so the bill is storage.

What sits in the bucket once the schedule has been running for a while (hourly 48 copies, before-deploy
10, daily full app archive 7, with the default `backup.offsite.keep`), as examples only:

| Database copy | Full app archive | Stored | Bill per month |
|---|---|---|---|
| 50 MB | 500 MB | about 6.4 GB | $0.00 (inside the free 10 GB) |
| 200 MB | 2 GB | about 25.6 GB | about $0.23 |
| 1 GB | 10 GB | about 128 GB | about $1.77 |

The page works this out for the real database from the copies already in the bucket (and the backup
history), shows it next to the bill so far this month, and shows what 2×, 5× and 10× bigger backups would
cost. To keep the bill down, lower the numbers kept per kind (`BACKUP_DRIVE_KEEP_HOURLY` etc. apply to
every off-site place) or switch the hourly schedule off.

### The R2 Backups page

Settings → R2 Backups, for people who may manage backups. It shows, at a glance:

- the status (connected, switched off, not set up, problem), auto upload, encryption, the last upload
  and the bucket;
- how much is stored, the share of the free allowance, the estimated bill this month and the bill once
  the schedule has filled the bucket;
- the backup health checks (hourly, daily, copy on R2, restore test);
- the bill line by line (storage, Class A, Class B) now and when full, growth scenarios, and examples with
  typical sizes while there is nothing to measure yet;
- the copies by type against how many are kept, the latest files, and the latest uploads, restore tests
  and failed attempts.

The bucket is read from R2 itself, which is one list request per 1,000 files; the answer is reused for 10
minutes, **Refresh** reads again, and a connection problem is shown on the page instead of an error. The
page can only see the backup folder and the operations this app makes; Cloudflare's own dashboard is the
final word on the bill. Add a **dollar-to-taka rate** under **Configure** to see it in taka as well.

### Restoring from R2

`php artisan backup:restore --r2` downloads the newest database copy from R2, decrypts it and restores
it (see **Restore a backup**); `--offsite` takes the first place that is set up (R2, else Drive).
`php artisan backup:drill --r2` tests that copy without touching the live database.

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
have storage quota"), and it needs to delete old copies. Shared Drives exist only
in paid Google Workspace: with a free Gmail account use Cloudflare R2 (previous
chapter) instead.

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

`php artisan backup:restore --drive` (or `--offsite`, `--r2` for Cloudflare R2) downloads the newest
database copy, decrypts it and restores it (see **Restore a backup**). For anything else, download the file you want from the Drive folder (the
name tells the type and the time), decrypt it with one of the commands above and give the result to
`backup:restore`, or give `backup:restore` the `.enc` file itself and it decrypts it (it asks for the
key when `BACKUP_ENCRYPTION_KEY` is not set).

## Download Permissions

Backup download requires:

- Super Admin, or
- `backups.manage`

Backup downloads are written to the audit log as `backup_downloaded`.

## Restore a backup (backup:restore)

`php artisan backup:restore` puts a backup back. It is the one command that **replaces the live
database**, so it is built to ask first, try the dump first, keep a copy of what it replaces, and undo
itself when the load fails. Everything below was run for real against MySQL (see *What was tested*).

```bash
php artisan backup:restore                                   # no file: lists the backups on this server
php artisan backup:restore database-backup-20261023-120500-mysql-hourly.sql.gz
php artisan backup:restore app-backup-20261023-020000-daily.zip            # database and uploaded files
php artisan backup:restore app-backup-20261023-020000-daily.zip --only=files
php artisan backup:restore /home/me/downloads/database-backup-….sql.gz.enc  # a file you downloaded (decrypted for you)
php artisan backup:restore --drive                           # the newest database copy on Google Drive
```

**What it does, in this order** (nothing is touched until step 5):

1. Finds the file: a name in the backup folders, a path anywhere, or `--drive`. A `.enc` file is decrypted
   with `BACKUP_ENCRYPTION_KEY` (or the key you type). From a full app archive (`.zip`) it takes out the
   `database/….sql.gz` dump.
2. Reads the dump to the end: it must be complete, hold the `migrations` table and still match the
   SHA-256 recorded when it was made.
3. **Tries the dump in a scratch database** (the restore test, see above) when `BACKUP_DRILL_DB_USERNAME`
   is set. A dump that does not load is refused here, with the live database untouched.
4. Shows what it is about to do (the file, how old it is, the database it will replace) and asks you to
   **type the database name**. Without a keyboard, `--confirm=THE_DATABASE_NAME` must be given: a script
   cannot restore by accident.
5. Copies the current database (`prerestore` backup), switches maintenance mode on, **empties** the
   database (every table and view, never the database itself), loads the dump, runs pending migrations
   (the code may be newer than the backup), clears the caches and switches maintenance mode off.
   Emptying first matters: a dump only replaces the tables it contains, and a table created by a newer
   migration would stay behind and make the next `migrate` fail with "already exists".

**If something goes wrong**

| What happened | What the command does |
|---|---|
| The file is damaged, incomplete, or the key is wrong | Stops at step 1 or 2. Nothing was changed. |
| The dump does not load into the scratch database | Stops at step 3. Nothing was changed. |
| The safety copy cannot be made (disk full) | Stops before maintenance mode. Nothing was changed. |
| The load fails half-way, or the restored database has a different number of tables than the dump | Loads the safety copy back automatically, switches maintenance mode off, says so. Nothing was lost. |
| Putting the safety copy back fails too | Leaves the app in maintenance mode and names the safety copy: `php artisan backup:restore <that file>`. |
| The data is restored but a migration fails | Keeps the restored data, leaves maintenance mode **on** and says what to run: `php artisan migrate --force`, then `php artisan up`. |
| The command is killed (a dropped SSH session) | Run it in `tmux`/`screen` or the Coolify terminal. If it was killed anyway, run the same command again: it starts over from an empty database. |

**Options**

| Option | Effect |
|---|---|
| `--only=database` / `--only=files` | For a `.zip`: only the database, or only the uploaded files (no maintenance mode, no database work). A `.sql.gz` is always database only. |
| `--confirm=NAME` | The database name (or `files` for `--only=files`), instead of being asked. |
| `--skip-rehearsal` | Do not try the dump in a scratch database first. |
| `--skip-safety-backup` | Do not copy the current database first. Only for a database that is already ruined. |
| `--no-migrate` | Do not run pending migrations afterwards. |

**What it will not do**

- It replaces a **MySQL/MariaDB** database only. A SQLite database is restored by hand (below).
- From a full app archive it copies back **only the uploaded files** (`storage/app/public` and
  `storage/app/private`, `backup.restore.file_paths`): files with the same name are replaced, nothing is
  deleted, links are not followed, and a name that climbs out of those folders is refused. The **code**
  comes from the deploy and the **`.env`** from the server's environment; neither is overwritten. (The
  `.env` inside an archive is how you recover the secrets after losing the server, see below.)
- It does not touch the database user's privileges: it uses the app's own connection, which can
  create and drop tables because migrations need that.

**After a restore**

The summary prints the number of tables and migrations, the name of the safety copy, any warnings, and
the state of maintenance mode. Then check: sign in, the dashboard totals, the latest orders and
payments, `/health/version`. Things to know:

- Everything saved **after the backup was made is gone** (the safety copy still has it). The prompt tells
  you how old the backup is.
- Sessions, caches and queues in Redis are not part of a database restore. If the queue lives in the
  database (`QUEUE_CONNECTION=database`) its jobs come back with the backup and the summary warns you.
- A backup made by a **newer** version of the app than the running code is flagged: deploy that version
  before using the app.
- The history on the Backups page starts again from the backup's own moment, plus one **Restore** row
  written after the restore. "Running" rows that every dump carries are removed.
- The first backup after a restore is the next hourly one; the dashboard card may say a backup is
  overdue until then.

## If the server is gone

The ERP can be rebuilt from three things that live outside the server: **the code** (GitHub), **the
off-site folder** (the Cloudflare R2 bucket and its keys, or the Drive folder), and
**`BACKUP_ENCRYPTION_KEY`**. Check today that you have all three, plus Coolify access and the DNS login.
Without the key the off-site copies cannot be opened by anyone.

1. **Download from the bucket or Drive folder** (R2: the Cloudflare dashboard → R2 → the bucket → the
   folder; Drive: in a browser. Both always work): the newest `app-backup-…-daily.zip.enc` and the
   newest `database-backup-…-hourly.sql.gz.enc`.
2. **Decrypt them on any computer** with `openssl` (see *Encryption* above; no PHP needed).
3. **Get the secrets back.** The `.env` is inside the archive:
   `unzip -p app-backup-…-daily.zip .env`. Put its values (`APP_KEY` above all: encrypted settings such
   as API keys and the Drive service account cannot be read without it) into the new Coolify application.
4. **New server:** deploy `main` from GitHub with those environment variables and a new, empty MySQL
   database. The container starts, runs the migrations and serves an empty ERP.
5. **Put the data back**, from the container terminal, with the two files on the server (or, when the
   R2 or Drive variables are set, `php artisan backup:restore --offsite` fetches the newest database copy itself):

   ```bash
   php artisan backup:restore database-backup-…-hourly.sql.gz
   php artisan backup:restore app-backup-…-daily.zip --only=files
   ```

6. Check the list under **After a restore**, then set the uptime monitor on `/health/backups?token=…`
   again and run `php artisan backup:drill` once the drill user exists.

What is lost in this case: the data entered since the newest hourly backup (up to an hour), and files
kept on Cloudflare R2 are not in the archive at all.

## Restore by hand

Use these only when `php artisan` cannot run. `backup:restore` does the same with more care.

**SQLite**

```bash
php artisan down
cp storage/app/private/backups/database/database-backup-YYYYMMDD-HHMMSS-sqlite-daily.sqlite database/database.sqlite
php artisan optimize:clear
php artisan up
```

**MySQL/MariaDB** (empty the database first, or tables created by newer migrations stay behind):

```bash
php artisan down
gunzip -c storage/app/private/backups/database/database-backup-YYYYMMDD-HHMMSS-mysql-daily.sql.gz | mysql -u DB_USERNAME -p DB_DATABASE
php artisan migrate --force      # if the code is newer than the backup
php artisan optimize:clear
php artisan up
```

The dump sets its own character set, so Bangla text restores correctly without any extra option. Very old
plain `.sql` files are loaded with `mysql … < file.sql`.

## Restore Checklist

1. Confirm the backup file date and environment.
2. Download a copy before restoring (`backup:restore` also makes a `prerestore` copy by itself).
3. Run `php artisan backup:restore` (or restore by hand with the app in maintenance mode).
4. Run migrations if needed (automatic unless `--no-migrate`).
5. Clear caches (automatic).
6. Verify admin login.
7. Check dashboard totals.
8. Check recent orders, purchases, payments, and stock.
9. Review audit logs.
10. Verify company settings and invoice branding.
11. Confirm `/health/version` shows the expected app version after the restore or redeploy.

## What was tested

`backup:restore` was run for real against MySQL 8.0 with the whole ERP schema (163 tables, 264
migrations) and the `mysql` client from the image: a plain dump, an `openssl`-encrypted copy from outside
the backup folder, a full app archive (database and files, and files only), a wrong confirmation, a
wrong key, a dump with a broken statement in the middle (stopped by the scratch test, and, with the test
skipped, rolled back from the safety copy with the app reopened), and an app that was already in
maintenance mode. Not tried: a real Google Drive (`--drive` is covered with a fake), MySQL 8.4, and the
live server.

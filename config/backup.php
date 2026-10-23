<?php

return [
    'schedule_time' => env('BACKUP_SCHEDULE_TIME', '02:00'),

    'database' => [
        // Files kept per kind (the kind is the last part of the file name, see
        // BackupRun::KINDS). Each kind is pruned on its own, so a
        // stream of hourly files can never push out a daily or pre-deploy one.
        // Files without a kind (made before kinds existed) count as "manual".
        'keep' => [
            'hourly' => (int) env('BACKUP_KEEP_HOURLY', 48),
            'daily' => (int) env('BACKUP_KEEP_DAILY', 30),
            'predeploy' => (int) env('BACKUP_KEEP_PREDEPLOY', 10),
            // The safety copy backup:restore takes first: the way back if the restore was a mistake.
            'prerestore' => (int) env('BACKUP_KEEP_PRERESTORE', 5),
            'manual' => (int) env('BACKUP_RETAIN_FILES', 10),
        ],

        // Needs the MySQL client tools in the image (the Dockerfile installs them).
        'mysqldump_binary' => env('BACKUP_MYSQLDUMP_BINARY', 'mysqldump'),

        // Seconds a single dump may run before it is stopped.
        'timeout' => (int) env('BACKUP_DUMP_TIMEOUT', 900),
    ],

    // Switches for the schedules in bootstrap/app.php.
    'hourly' => [
        // A database backup every hour, at five past (kind "hourly").
        'enabled' => (bool) env('BACKUP_HOURLY_ENABLED', true),
    ],

    'daily' => [
        // A full app backup (files + database) every day at schedule_time
        // (kind "daily"). Off = a database-only backup at that time instead.
        'full_app' => (bool) env('BACKUP_DAILY_FULL_APP', true),
    ],

    'app' => [
        'database_connection' => env('BACKUP_DB_CONNECTION'),

        // Archives kept per kind, like database.keep. A daily archive holds the
        // uploaded files too, so it is the one that fills the disk.
        'keep' => [
            'hourly' => 10,
            'daily' => (int) env('BACKUP_APP_KEEP_DAILY', 10),
            'predeploy' => (int) env('BACKUP_APP_KEEP_PREDEPLOY', 3),
            'prerestore' => 1,
            'manual' => (int) env('BACKUP_RETAIN_FILES', 10),
        ],

        'directory' => 'backups/app',
        'include_paths' => [
            'app',
            'bootstrap',
            'config',
            'database',
            'public',
            'resources',
            'routes',
            'storage/app/public',
            // Files kept off the public disk (KYC and other private documents).
            'storage/app/private',
            '.env',
            'artisan',
            'composer.json',
            'composer.lock',
            'package.json',
            'package-lock.json',
            'vite.config.js',
        ],
        'exclude_paths' => [
            '.git',
            'node_modules',
            'vendor',
            'storage/app/private/backups',
            'storage/app/private/livewire-tmp',
            'storage/framework',
            'storage/logs',
            'database/database.sqlite',
            'database/demo.sqlite',
        ],
    ],

    // The restore test (php artisan backup:drill, weekly): loads the newest dump into a
    // scratch database named backup_drill_*, checks it, and drops it. The app's own
    // database user usually may not create databases, so it needs a user of its own -
    // see docs/backup-restore.md ("Restore test") for the one-line GRANT that limits it
    // to backup_drill_* schemas. Off until BACKUP_DRILL_DB_USERNAME is set.
    'drill' => [
        'enabled' => filled(env('BACKUP_DRILL_DB_USERNAME')),
        'connection' => 'backup_drill',
        'mysql_binary' => env('BACKUP_MYSQL_BINARY', 'mysql'),
        'schedule_time' => env('BACKUP_DRILL_SCHEDULE_TIME', '03:30'),
        // Seconds the import may run before it is stopped.
        'timeout' => (int) env('BACKUP_DRILL_TIMEOUT', 1800),
    ],

    // php artisan backup:restore: puts a backup back (MySQL/MariaDB). See docs/backup-restore.md.
    'restore' => [
        'mysql_binary' => env('BACKUP_MYSQL_BINARY', 'mysql'),
        // Seconds the import may run before it is stopped; a restore that is cut off half-way
        // is rolled back from the safety copy.
        'timeout' => (int) env('BACKUP_RESTORE_TIMEOUT', 3600),
        // The only folders of a full app archive that are copied back. The code comes from the
        // deploy and the .env from the server's environment; neither is ever overwritten.
        // Files matching app.exclude_paths are skipped here as well.
        'file_paths' => [
            'storage/app/public',
            'storage/app/private',
        ],
    ],

    // How old the newest successful backup of each kind may be before it counts as stale
    // (the dashboard card, `backup:check` and /health/backups). A little over the schedule,
    // so one slow run is not an alarm.
    'health' => [
        'hourly_max_age_minutes' => (int) env('BACKUP_STALE_HOURLY_MINUTES', 150),
        'daily_max_age_hours' => (int) env('BACKUP_STALE_DAILY_HOURS', 30),
        'drill_max_age_days' => (int) env('BACKUP_STALE_DRILL_DAYS', 9),
        // GET /health/backups?token=... (or "Authorization: Bearer ...") answers 200 when
        // backups are fresh and 503 when not, for an outside uptime monitor: the only thing
        // that notices when the scheduler itself has stopped. Off (404) without a token.
        'token' => env('BACKUP_HEALTH_TOKEN'),
    ],

    // Encrypts the copy that goes to Google Drive (see App\Services\BackupEncryptor).
    // A passphrase of its own, NOT APP_KEY: APP_KEY sits in the .env inside the app
    // backup, so a backup locked with it could not be opened after losing the server.
    // Keep a copy of this key OUTSIDE the server (password manager, printed): without
    // it the off-site copies cannot be opened by anyone. `openssl rand -base64 32`
    // makes a good one. Local backup files on the server are not encrypted.
    'encryption' => [
        'key' => env('BACKUP_ENCRYPTION_KEY'),
    ],

    // What goes off-site (Google Drive and/or Cloudflare R2) when "Auto upload" is switched on
    // for a place (Backups page, R2 Backups page).
    'offsite' => [
        // Backup kinds sent by --auto-upload. A daily full app archive already holds
        // that day's dump, so the kinds only decide which stand-alone dumps also go.
        'database_kinds' => array_values(array_filter(array_map('trim', explode(',', (string) env('BACKUP_OFFSITE_DATABASE_KINDS', 'hourly,daily,predeploy'))))),
        'app_kinds' => array_values(array_filter(array_map('trim', explode(',', (string) env('BACKUP_OFFSITE_APP_KINDS', 'daily'))))),

        // Nothing is uploaded unless BACKUP_ENCRYPTION_KEY is set. A dump holds every
        // customer and the app archive holds the .env. Set false only if you accept
        // plain copies off-site.
        'require_encryption' => (bool) env('BACKUP_OFFSITE_REQUIRE_ENCRYPTION', true),

        // Copies kept per kind in each off-site place; older ones are deleted after each
        // upload. Only files named like our backups are ever touched.
        'keep' => [
            'database' => [
                'hourly' => (int) env('BACKUP_DRIVE_KEEP_HOURLY', 48),
                'daily' => (int) env('BACKUP_DRIVE_KEEP_DAILY', 30),
                'predeploy' => (int) env('BACKUP_DRIVE_KEEP_PREDEPLOY', 10),
                'prerestore' => 5,
                'manual' => 10,
            ],
            'app' => [
                'hourly' => 5,
                'daily' => (int) env('BACKUP_DRIVE_APP_KEEP_DAILY', 7),
                'predeploy' => 2,
                'prerestore' => 1,
                'manual' => 5,
            ],
        ],
    ],

    // Cloudflare R2: an off-site place that needs no Google Workspace. The S3 API is used
    // (aws-sdk-php), so any S3-compatible bucket works. Use a bucket of its own - never the
    // media buckets of Cloud Storage - and an API token limited to that one bucket
    // (Object Read & Write). Settings saved on the R2 Backups page win over these values.
    'r2' => [
        'enabled' => env('BACKUP_R2_ENABLED', false),
        'auto_upload' => env('BACKUP_R2_AUTO_UPLOAD', false),
        // The Cloudflare Account ID (32 hex characters) or the full endpoint
        // https://<account-id>.r2.cloudflarestorage.com (also for the EU jurisdiction).
        'account' => env('BACKUP_R2_ACCOUNT_ID', env('BACKUP_R2_ENDPOINT')),
        'bucket' => env('BACKUP_R2_BUCKET'),
        // A folder inside the bucket; everything this app writes or deletes stays under it.
        'prefix' => env('BACKUP_R2_PREFIX', 'erp-backups'),
        'access_key_id' => env('BACKUP_R2_ACCESS_KEY_ID'),
        'secret_access_key' => env('BACKUP_R2_SECRET_ACCESS_KEY'),
        // Bytes per part of a big upload (at least 5 MiB; every part but the last has this size).
        'part_bytes' => (int) env('BACKUP_R2_PART_BYTES', 16 * 1024 * 1024),
        // Optional: shows the estimated bill in taka as well.
        'usd_bdt_rate' => env('BACKUP_R2_USD_BDT_RATE'),

        // What Cloudflare charges for the Standard storage class, in US dollars. Used only by
        // the estimate on the R2 Backups page - it never limits anything. Check
        // https://developers.cloudflare.com/r2/pricing/ and change the values (or set the
        // environment variables) when Cloudflare changes its prices.
        'pricing' => [
            'as_of' => env('BACKUP_R2_PRICING_AS_OF', '2026-10-23'),
            'url' => 'https://developers.cloudflare.com/r2/pricing/',
            'storage_per_gb_month' => (float) env('BACKUP_R2_PRICE_STORAGE_GB', 0.015),
            'class_a_per_million' => (float) env('BACKUP_R2_PRICE_CLASS_A', 4.50),
            'class_b_per_million' => (float) env('BACKUP_R2_PRICE_CLASS_B', 0.36),
            // Free every month for the whole Cloudflare account (all buckets together).
            'free_storage_gb' => (float) env('BACKUP_R2_FREE_STORAGE_GB', 10),
            'free_class_a' => (int) env('BACKUP_R2_FREE_CLASS_A', 1_000_000),
            'free_class_b' => (int) env('BACKUP_R2_FREE_CLASS_B', 10_000_000),
        ],
    ],

    'google_drive' => [
        // Bytes per upload request for big files (rounded to a multiple of 256 KiB).
        'chunk_bytes' => (int) env('GOOGLE_DRIVE_BACKUP_CHUNK_BYTES', 8 * 1024 * 1024),
        'enabled' => env('GOOGLE_DRIVE_BACKUP_ENABLED', false),
        'auto_upload' => env('GOOGLE_DRIVE_BACKUP_AUTO_UPLOAD', false),
        'folder_id' => env('GOOGLE_DRIVE_BACKUP_FOLDER_ID'),
        'service_account_json' => env('GOOGLE_DRIVE_SERVICE_ACCOUNT_JSON'),
        'service_account_path' => env('GOOGLE_DRIVE_SERVICE_ACCOUNT_PATH'),
    ],
];

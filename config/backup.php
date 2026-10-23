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
            'storage/framework',
            'storage/logs',
            'database/database.sqlite',
            'database/demo.sqlite',
        ],
    ],

    'google_drive' => [
        'enabled' => env('GOOGLE_DRIVE_BACKUP_ENABLED', false),
        'auto_upload' => env('GOOGLE_DRIVE_BACKUP_AUTO_UPLOAD', false),
        'folder_id' => env('GOOGLE_DRIVE_BACKUP_FOLDER_ID'),
        'service_account_json' => env('GOOGLE_DRIVE_SERVICE_ACCOUNT_JSON'),
        'service_account_path' => env('GOOGLE_DRIVE_SERVICE_ACCOUNT_PATH'),
    ],
];

<?php

use App\Models\User;
use App\Support\AdminPassword;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('admin:ensure-super {--email=} {--password=} {--name=ZamZam Admin}', function () {
    $email = $this->option('email') ?: env('ADMIN_EMAIL', 'admin@example.com');
    $password = $this->option('password') ?: env('ADMIN_PASSWORD');

    if (! $password) {
        $this->error('Please provide --password or set ADMIN_PASSWORD.');

        return 1;
    }

    try {
        AdminPassword::assertStrong($password, '--password/ADMIN_PASSWORD');
    } catch (RuntimeException $exception) {
        $this->error($exception->getMessage());

        return 1;
    }

    User::query()->updateOrCreate(
        ['email' => $email],
        [
            'name' => $this->option('name'),
            'password' => $password,
            'role' => 'super_admin',
            'is_active' => true,
            'email_verified_at' => now(),
        ],
    );

    $this->info("Super admin is ready: {$email}");

    return 0;
})->purpose('Create or reset an active Super Admin user');

Artisan::command('demo:refresh {--database=}', function () {
    $database = $this->option('database')
        ?: config('database.connections.demo.database', database_path('demo.sqlite'));
    $originalDefault = config('database.default');
    $originalDemoPath = config('database.connections.demo.database');

    if ($database !== ':memory:') {
        File::ensureDirectoryExists(dirname($database));

        if (! File::exists($database)) {
            File::put($database, '');
        }
    }

    Config::set('database.connections.demo.database', $database);
    DB::purge('demo');

    try {
        $this->call('migrate:fresh', [
            '--database' => 'demo',
            '--seed' => true,
            '--seeder' => DemoDataSeeder::class,
            '--force' => true,
        ]);
    } finally {
        Config::set('database.default', $originalDefault);
        Config::set('database.connections.demo.database', $originalDemoPath);
        DB::setDefaultConnection($originalDefault);
    }

    DB::purge('demo');

    $this->info("Demo database refreshed: {$database}");

    return 0;
})->purpose('Refresh an isolated demo SQLite database with demo ERP data');

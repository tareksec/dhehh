<?php

namespace App\Console\Commands;

use App\Services\BackupEncryptor;
use Illuminate\Console\Command;
use Throwable;

class DecryptBackup extends Command
{
    protected $signature = 'backup:decrypt
        {file : The encrypted backup (a .enc file downloaded from Google Drive)}
        {--output= : Where to write the decrypted file; default: the same name without .enc, in the current folder}
        {--force : Replace the output file if it already exists}';

    protected $description = 'Decrypt an off-site backup (.enc) with BACKUP_ENCRYPTION_KEY';

    public function handle(BackupEncryptor $encryptor): int
    {
        $source = (string) $this->argument('file');

        if (! is_file($source)) {
            $this->error("File not found: {$source}");

            return self::FAILURE;
        }

        $output = (string) ($this->option('output') ?: '');

        if ($output === '') {
            if (! str_ends_with($source, BackupEncryptor::EXTENSION)) {
                $this->error('The file does not end with .enc, so pass --output= to say where the decrypted file goes.');

                return self::FAILURE;
            }

            $output = getcwd().'/'.basename(substr($source, 0, -strlen(BackupEncryptor::EXTENSION)));
        }

        if (file_exists($output) && ! $this->option('force')) {
            $this->error("{$output} already exists. Use --force to replace it, or --output= for another name.");

            return self::FAILURE;
        }

        $key = $encryptor->isConfigured() ? null : (string) $this->secret('Backup encryption key');

        try {
            $encryptor->decryptFile($source, $output, $key ?: null);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Decrypted to {$output}");

        return self::SUCCESS;
    }
}

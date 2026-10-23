<?php

namespace Tests\Feature;

use App\Services\BackupEncryptor;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class BackupEncryptionTest extends TestCase
{
    private const KEY = 'a-long-enough-test-passphrase "with" quotes & ünïcode';

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = storage_path('framework/testing/backup-encryption-'.str()->random(8));
        File::ensureDirectoryExists($this->directory);
        Config::set('backup.encryption.key', self::KEY);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);

        parent::tearDown();
    }

    /** A gzip-looking file of an exact size, so the "does it look right" check has something to read. */
    private function plainFile(int $size, string $name = 'dump.sql.gz'): string
    {
        $path = $this->directory.'/'.$name;
        File::put($path, "\x1f\x8b".($size > 2 ? random_bytes($size - 2) : ''));

        return $path;
    }

    /** @return array<string, array{int}> */
    public static function sizes(): array
    {
        // Around the cipher block (16 bytes) and the 1 MiB working block.
        return [
            'just the header bytes' => [2],
            'one byte short of a block' => [15],
            'exactly one block' => [16],
            'one byte over a block' => [17],
            'one byte under the working block' => [1048575],
            'exactly the working block' => [1048576],
            'one byte over the working block' => [1048577],
            'several working blocks' => [3500011],
        ];
    }

    #[DataProvider('sizes')]
    public function test_a_file_survives_an_encrypt_and_decrypt_round_trip_at_every_boundary(int $size): void
    {
        $plain = $this->plainFile($size);
        $encryptor = app(BackupEncryptor::class);

        $encryptor->encryptFile($plain, $this->directory.'/dump.sql.gz.enc');
        $encryptor->decryptFile($this->directory.'/dump.sql.gz.enc', $this->directory.'/restored.sql.gz');

        $this->assertSame(hash_file('sha256', $plain), hash_file('sha256', $this->directory.'/restored.sql.gz'));
        $this->assertStringStartsWith('Salted__', (string) file_get_contents($this->directory.'/dump.sql.gz.enc'));
        // The ciphertext is not the plain text, and the same file never encrypts to the same bytes twice (random salt).
        $encryptor->encryptFile($plain, $this->directory.'/again.enc');
        $this->assertNotSame(file_get_contents($this->directory.'/dump.sql.gz.enc'), file_get_contents($this->directory.'/again.enc'));
    }

    public function test_the_encrypted_file_opens_with_plain_openssl_and_openssl_files_open_here(): void
    {
        if ((new ExecutableFinder)->find('openssl') === null) {
            $this->markTestSkipped('The openssl command line tool is not installed.');
        }

        $plain = $this->plainFile(2500003);
        $encryptor = app(BackupEncryptor::class);
        $env = ['BACKUP_KEY' => self::KEY];

        // Here -> openssl: the command printed in docs/backup-restore.md.
        $encryptor->encryptFile($plain, $this->directory.'/ours.enc');
        $decrypt = new Process([
            'openssl', 'enc', '-d', '-aes-256-cbc', '-pbkdf2', '-iter', (string) BackupEncryptor::ITERATIONS, '-md', 'sha256',
            '-in', $this->directory.'/ours.enc', '-out', $this->directory.'/by-openssl.sql.gz', '-pass', 'env:BACKUP_KEY',
        ], null, $env);
        $decrypt->run();

        $this->assertSame(0, $decrypt->getExitCode(), $decrypt->getErrorOutput());
        $this->assertSame(hash_file('sha256', $plain), hash_file('sha256', $this->directory.'/by-openssl.sql.gz'));

        // openssl -> here.
        $encrypt = new Process([
            'openssl', 'enc', '-aes-256-cbc', '-pbkdf2', '-iter', (string) BackupEncryptor::ITERATIONS, '-md', 'sha256',
            '-in', $plain, '-out', $this->directory.'/theirs.enc', '-pass', 'env:BACKUP_KEY',
        ], null, $env);
        $encrypt->run();

        $this->assertSame(0, $encrypt->getExitCode(), $encrypt->getErrorOutput());

        $encryptor->decryptFile($this->directory.'/theirs.enc', $this->directory.'/by-us.sql.gz');

        $this->assertSame(hash_file('sha256', $plain), hash_file('sha256', $this->directory.'/by-us.sql.gz'));
    }

    public function test_a_wrong_key_is_refused_and_leaves_nothing_behind(): void
    {
        $plain = $this->plainFile(70000);
        $encryptor = app(BackupEncryptor::class);
        $encryptor->encryptFile($plain, $this->directory.'/dump.sql.gz.enc');

        try {
            $encryptor->decryptFile($this->directory.'/dump.sql.gz.enc', $this->directory.'/restored.sql.gz', 'some-other-passphrase-123');
            $this->fail('A wrong key must not decrypt.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('wrong key', $exception->getMessage());
        }

        $this->assertFileDoesNotExist($this->directory.'/restored.sql.gz');
    }

    public function test_a_truncated_or_foreign_file_is_refused(): void
    {
        $plain = $this->plainFile(70000);
        $encryptor = app(BackupEncryptor::class);
        $encryptor->encryptFile($plain, $this->directory.'/dump.sql.gz.enc');
        File::put($this->directory.'/cut.enc', substr((string) file_get_contents($this->directory.'/dump.sql.gz.enc'), 0, 30001));
        File::put($this->directory.'/foreign.enc', 'this was never encrypted');

        foreach (['cut.enc' => 'damaged or incomplete', 'foreign.enc' => 'not an encrypted backup'] as $file => $message) {
            try {
                $encryptor->decryptFile($this->directory.'/'.$file, $this->directory.'/out.sql.gz');
                $this->fail("{$file} must not decrypt.");
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString($message, $exception->getMessage());
            }
        }

        $this->assertFileDoesNotExist($this->directory.'/out.sql.gz');
    }

    public function test_a_missing_or_short_key_stops_the_backup_instead_of_weakening_it(): void
    {
        $plain = $this->plainFile(100);
        $encryptor = app(BackupEncryptor::class);

        Config::set('backup.encryption.key', 'short');
        $this->assertTrue($encryptor->isConfigured());

        try {
            $encryptor->encryptFile($plain, $this->directory.'/short.enc');
            $this->fail('A short key must be refused.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('too short', $exception->getMessage());
        }

        Config::set('backup.encryption.key', null);
        $this->assertFalse($encryptor->isConfigured());

        try {
            $encryptor->encryptFile($plain, $this->directory.'/none.enc');
            $this->fail('No key must be refused.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('BACKUP_ENCRYPTION_KEY is not set', $exception->getMessage());
        }

        $this->assertFileDoesNotExist($this->directory.'/short.enc');
        $this->assertFileDoesNotExist($this->directory.'/none.enc');
    }

    public function test_backup_decrypt_command_restores_the_file_and_never_overwrites_by_accident(): void
    {
        $plain = $this->plainFile(123456);
        app(BackupEncryptor::class)->encryptFile($plain, $this->directory.'/database-backup-20261023-120500-mysql-hourly.sql.gz.enc');
        $output = $this->directory.'/restored.sql.gz';

        $this->artisan('backup:decrypt', [
            'file' => $this->directory.'/database-backup-20261023-120500-mysql-hourly.sql.gz.enc',
            '--output' => $output,
        ])->expectsOutputToContain('Decrypted to')->assertExitCode(0);

        $this->assertSame(hash_file('sha256', $plain), hash_file('sha256', $output));

        // It will not overwrite a file by accident.
        $this->artisan('backup:decrypt', [
            'file' => $this->directory.'/database-backup-20261023-120500-mysql-hourly.sql.gz.enc',
            '--output' => $output,
        ])->expectsOutputToContain('already exists')->assertExitCode(1);

        $this->artisan('backup:decrypt', [
            'file' => $this->directory.'/database-backup-20261023-120500-mysql-hourly.sql.gz.enc',
            '--output' => $output,
            '--force' => true,
        ])->assertExitCode(0);
    }

    public function test_backup_decrypt_command_explains_a_bad_request(): void
    {
        $this->artisan('backup:decrypt', ['file' => $this->directory.'/missing.enc'])
            ->expectsOutputToContain('File not found')
            ->assertExitCode(1);

        File::put($this->directory.'/notes.txt', 'x');
        $this->artisan('backup:decrypt', ['file' => $this->directory.'/notes.txt'])
            ->expectsOutputToContain('--output')
            ->assertExitCode(1);

        $this->artisan('backup:decrypt', ['file' => $this->directory.'/notes.txt', '--output' => $this->directory.'/o.txt'])
            ->expectsOutputToContain('not an encrypted backup')
            ->assertExitCode(1);
    }
}

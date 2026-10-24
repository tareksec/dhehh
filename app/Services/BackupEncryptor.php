<?php

namespace App\Services;

use RuntimeException;
use Throwable;

/**
 * Encrypts a backup before it leaves the server, and opens it again.
 *
 * The format is exactly what `openssl enc -aes-256-cbc -pbkdf2 -iter 100000
 * -md sha256` writes ("Salted__", an 8 byte salt, then the ciphertext), so a
 * copy from Google Drive can be opened with plain `openssl` on any computer
 * even if this application is gone. That matters more than anything else for
 * a backup: it must be restorable on the worst day. The cost is that CBC does
 * not detect tampering; accidental damage is caught by the gzip / zip
 * checksums inside and by the sha256 recorded for the file.
 *
 * The passphrase is BACKUP_ENCRYPTION_KEY and is deliberately not APP_KEY:
 * APP_KEY lives in the .env that sits inside the app backup, so a backup
 * encrypted with it could not be opened after losing the server.
 *
 * Files are processed in 1 MiB blocks, never loaded whole into memory.
 */
class BackupEncryptor
{
    public const EXTENSION = '.enc';

    /** Iterations for PBKDF2; the command in the docs must use the same number. */
    public const ITERATIONS = 100000;

    private const CIPHER = 'aes-256-cbc';

    private const MAGIC = 'Salted__';

    private const BLOCK = 16;

    /** A multiple of the cipher block size. */
    private const CHUNK = 1048576;

    private const MIN_KEY_LENGTH = 16;

    public function isConfigured(): bool
    {
        return filled(config('backup.encryption.key'));
    }

    /**
     * Encrypt $source into $destination (replacing it). A failure leaves no
     * partial file behind.
     */
    public function encryptFile(string $source, string $destination, ?string $key = null): void
    {
        $passphrase = $this->passphrase($key);
        $salt = random_bytes(8);
        [$cipherKey, $iv] = $this->derive($passphrase, $salt);

        $input = $this->open($source, 'rb');
        $output = $this->open($destination, 'wb');

        try {
            $this->write($output, self::MAGIC.$salt);

            $buffer = '';

            while (! feof($input)) {
                $read = fread($input, self::CHUNK);

                if ($read === false) {
                    throw new RuntimeException('The backup file could not be read for encryption.');
                }

                $buffer .= $read;
                $whole = strlen($buffer) - strlen($buffer) % self::BLOCK;

                if ($whole > 0) {
                    $this->write($output, $this->encryptBlocks(substr($buffer, 0, $whole), $cipherKey, $iv));
                    $buffer = substr($buffer, $whole);
                }
            }

            // PKCS#7 padding: always at least one byte, so a decryptor can tell where the data ends.
            $padding = self::BLOCK - strlen($buffer);
            $this->write($output, $this->encryptBlocks($buffer.str_repeat(chr($padding), $padding), $cipherKey, $iv));
        } catch (Throwable $exception) {
            fclose($output);
            fclose($input);
            @unlink($destination);

            throw $exception;
        }

        fclose($input);

        if (! fclose($output)) {
            @unlink($destination);

            throw new RuntimeException('The encrypted backup could not be written (is the disk full?).');
        }
    }

    /**
     * Decrypt $source into $destination. A wrong passphrase or a damaged file
     * is reported and leaves no output behind.
     */
    public function decryptFile(string $source, string $destination, ?string $key = null): void
    {
        $passphrase = $this->passphrase($key);
        $input = $this->open($source, 'rb');
        $output = null;

        try {
            $header = (string) fread($input, strlen(self::MAGIC) + 8);

            if (strlen($header) !== strlen(self::MAGIC) + 8 || ! str_starts_with($header, self::MAGIC)) {
                throw new RuntimeException('This file is not an encrypted backup (the "Salted__" header is missing).');
            }

            [$cipherKey, $iv] = $this->derive($passphrase, substr($header, strlen(self::MAGIC)));
            $output = $this->open($destination, 'wb');
            $buffer = '';

            while (! feof($input)) {
                $read = fread($input, self::CHUNK);

                if ($read === false) {
                    throw new RuntimeException('The encrypted backup could not be read.');
                }

                $buffer .= $read;

                // The last block carries the padding, so it is held back until the end is known.
                $take = (intdiv(strlen($buffer), self::BLOCK) - 1) * self::BLOCK;

                if ($take > 0) {
                    $this->write($output, $this->decryptBlocks(substr($buffer, 0, $take), $cipherKey, $iv));
                    $buffer = substr($buffer, $take);
                }
            }

            if (strlen($buffer) !== self::BLOCK) {
                throw new RuntimeException('The encrypted backup is damaged or incomplete.');
            }

            $last = $this->decryptBlocks($buffer, $cipherKey, $iv);
            $padding = ord($last[self::BLOCK - 1]);

            if ($padding < 1 || $padding > self::BLOCK || substr($last, -$padding) !== str_repeat(chr($padding), $padding)) {
                throw new RuntimeException('The backup could not be decrypted: wrong key, or the file is damaged.');
            }

            $this->write($output, substr($last, 0, self::BLOCK - $padding));
        } catch (Throwable $exception) {
            is_resource($output) && fclose($output);
            fclose($input);
            @unlink($destination);

            throw $exception;
        }

        fclose($input);

        if (! fclose($output)) {
            @unlink($destination);

            throw new RuntimeException('The decrypted backup could not be written (is the disk full?).');
        }

        // CBC has no checksum of its own: one wrong key in 256 still ends in valid padding.
        // A backup is a gzip, zip or SQLite file, so its first bytes give the real answer.
        if (! $this->looksRight($destination)) {
            @unlink($destination);

            throw new RuntimeException('The backup could not be decrypted: wrong key, or the file is damaged.');
        }
    }

    /** True when the file starts the way its extension says (gzip, zip, SQLite); unknown extensions pass. */
    protected function looksRight(string $path): bool
    {
        $expected = match (true) {
            str_ends_with($path, '.gz') => "\x1f\x8b",
            str_ends_with($path, '.zip') => 'PK',
            str_ends_with($path, '.sqlite') => 'SQLite format 3',
            default => null,
        };

        if ($expected === null) {
            return true;
        }

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return false;
        }

        $start = (string) fread($handle, strlen($expected));
        fclose($handle);

        return $start === $expected;
    }

    private function passphrase(?string $key): string
    {
        $key ??= config('backup.encryption.key');

        if (blank($key)) {
            throw new RuntimeException('BACKUP_ENCRYPTION_KEY is not set, so the backup cannot be encrypted or decrypted.');
        }

        if (strlen((string) $key) < self::MIN_KEY_LENGTH) {
            throw new RuntimeException('BACKUP_ENCRYPTION_KEY is too short: use at least '.self::MIN_KEY_LENGTH.' characters, for example the output of: openssl rand -base64 32');
        }

        return (string) $key;
    }

    /** @return array{0: string, 1: string} the AES key and the first IV, as `openssl enc -pbkdf2` derives them */
    private function derive(string $passphrase, string $salt): array
    {
        $derived = hash_pbkdf2('sha256', $passphrase, $salt, self::ITERATIONS, 48, true);

        return [substr($derived, 0, 32), substr($derived, 32, 16)];
    }

    /** Encrypts whole blocks without padding and moves $iv on, so the next call continues the same CBC chain. */
    private function encryptBlocks(string $plain, string $key, string &$iv): string
    {
        $cipher = openssl_encrypt($plain, self::CIPHER, $key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $iv);

        if ($cipher === false) {
            throw new RuntimeException('The backup could not be encrypted.');
        }

        $iv = substr($cipher, -self::BLOCK);

        return $cipher;
    }

    private function decryptBlocks(string $cipher, string $key, string &$iv): string
    {
        $plain = openssl_decrypt($cipher, self::CIPHER, $key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $iv);

        if ($plain === false) {
            throw new RuntimeException('The backup could not be decrypted.');
        }

        $iv = substr($cipher, -self::BLOCK);

        return $plain;
    }

    /** @return resource */
    private function open(string $path, string $mode)
    {
        $handle = @fopen($path, $mode);

        if ($handle === false) {
            throw new RuntimeException("The file [{$path}] could not be opened.");
        }

        return $handle;
    }

    /** @param resource $handle */
    private function write($handle, string $data): void
    {
        if ($data !== '' && fwrite($handle, $data) !== strlen($data)) {
            throw new RuntimeException('The file could not be written (is the disk full?).');
        }
    }
}

<?php

namespace App\Services;

use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

/**
 * Google Drive uploads through the service account configured on Settings →
 * Backups → Google Drive Backup. Used for app backups (upload(), into the
 * backup folder) and, since Video Generation Phase 4, for "Save to Google
 * Drive" (uploadStream(), into a folder the caller names).
 */
class GoogleDriveBackupService
{
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    /** Resumable upload: any size, streamed; supportsAllDrives lets the target be a Shared Drive folder. */
    private const RESUMABLE_UPLOAD_URL = 'https://www.googleapis.com/upload/drive/v3/files?uploadType=resumable&supportsAllDrives=true&fields=id,name,webViewLink';

    private const FILES_URL = 'https://www.googleapis.com/drive/v3/files';

    private const SCOPE = 'https://www.googleapis.com/auth/drive.file';

    /** Drive wants every chunk but the last to be a multiple of this. */
    private const CHUNK_UNIT = 262144;

    /** How many times one chunk is tried before the upload is given up. */
    private const CHUNK_ATTEMPTS = 3;

    private ?string $token = null;

    private int $tokenExpiresAt = 0;

    public function isConfigured(): bool
    {
        return app(BackupSettingsService::class)->googleDriveEnabled()
            && filled($this->folderId())
            && $this->credentials() !== [];
    }

    public function upload(string $absolutePath, ?string $filename = null, string $mimeType = 'application/zip'): array
    {
        if (! File::exists($absolutePath)) {
            throw new RuntimeException('Backup file was not found for Google Drive upload.');
        }

        $filename ??= basename($absolutePath);
        $folderId = $this->folderId();

        if (blank($folderId)) {
            throw new RuntimeException('Google Drive backup folder ID is required.');
        }

        // Streamed (resumable) upload: a backup of hundreds of megabytes is never
        // loaded into memory, and there is no 5 MB multipart ceiling.
        $handle = fopen($absolutePath, 'rb');

        if ($handle === false) {
            throw new RuntimeException('Backup file could not be opened for Google Drive upload.');
        }

        try {
            return $this->uploadStream(Utils::streamFor($handle), $filename, $mimeType, $folderId);
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }
    }

    /** True when service account credentials are saved, whether or not backup uploads are switched on. */
    public function hasServiceAccount(): bool
    {
        $credentials = $this->credentials();

        return filled($credentials['client_email'] ?? null) && filled($credentials['private_key'] ?? null);
    }

    /** The address a Drive folder must be shared with, so the admin can copy it. */
    public function serviceAccountEmail(): ?string
    {
        return $this->credentials()['client_email'] ?? null;
    }

    /**
     * Stream a file into a given Drive folder with a resumable upload — never
     * loads the whole file into memory, and has no 5 MB multipart ceiling.
     *
     * @return array{id?: string, name?: string, webViewLink?: string}
     */
    public function uploadStream(StreamInterface $contents, string $filename, string $mimeType, string $folderId): array
    {
        $token = $this->accessToken();

        $session = Http::withToken($token)
            ->withHeaders(array_filter([
                'X-Upload-Content-Type' => $mimeType,
                'X-Upload-Content-Length' => $contents->getSize() !== null ? (string) $contents->getSize() : null,
            ]))
            ->withBody((string) json_encode(['name' => $filename, 'parents' => [$folderId]]), 'application/json; charset=UTF-8')
            ->post(self::RESUMABLE_UPLOAD_URL);

        $location = $session->header('Location');

        if (! $session->successful() || blank($location)) {
            throw new RuntimeException($session->json('error.message') ?: 'Google Drive did not accept the upload.');
        }

        $size = $contents->getSize();

        // A big file goes in pieces, so one dropped connection costs a piece
        // and not the whole upload (and no single request outlives its timeout).
        if ($size !== null && $size > $this->chunkBytes()) {
            return $this->uploadInChunks($token, $location, $contents, $mimeType, $size);
        }

        $response = Http::withToken($token)
            ->timeout(300)
            ->withBody($contents, $mimeType)
            ->put($location);

        if (! $response->successful()) {
            throw new RuntimeException($response->json('error.message') ?: 'Google Drive upload failed.');
        }

        return (array) $response->json();
    }

    /**
     * The backup files in the configured folder, newest information from Drive.
     * With the drive.file scope only files this service account created are
     * visible, so a backup cleanup can never touch anything else in the folder.
     *
     * @return array<int, array{id: string, name: string, size?: string, createdTime?: string}>
     */
    public function listBackupFiles(): array
    {
        $folderId = $this->folderId();

        if (blank($folderId)) {
            throw new RuntimeException('Google Drive backup folder ID is required.');
        }

        $token = $this->accessToken();
        $files = [];
        $pageToken = null;

        do {
            $response = Http::withToken($token)->get(self::FILES_URL, array_filter([
                'q' => "'".str_replace(['\\', "'"], ['\\\\', "\\'"], $folderId)."' in parents and trashed = false",
                'fields' => 'nextPageToken,files(id,name,size,createdTime)',
                'pageSize' => 1000,
                'corpora' => 'allDrives',
                'includeItemsFromAllDrives' => 'true',
                'supportsAllDrives' => 'true',
                'pageToken' => $pageToken,
            ]));

            if (! $response->successful()) {
                throw new RuntimeException($response->json('error.message') ?: 'Could not list the Google Drive backup folder.');
            }

            foreach ((array) $response->json('files', []) as $file) {
                $files[] = $file;
            }

            $pageToken = $response->json('nextPageToken');
        } while (filled($pageToken));

        return $files;
    }

    /** Save a Drive file to $destination, streamed to disk (a copy is never held in memory). */
    public function download(string $fileId, string $destination): void
    {
        $response = Http::withToken($this->accessToken())
            ->timeout(600)
            ->withOptions(['sink' => $destination])
            ->get(self::FILES_URL.'/'.rawurlencode($fileId).'?alt=media&supportsAllDrives=true');

        if (! $response->successful()) {
            File::delete($destination);

            throw new RuntimeException('Google Drive did not send the file (HTTP '.$response->status().').');
        }
    }

    /** Remove a file by its Drive id. A file that is already gone counts as removed. */
    public function delete(string $fileId): void
    {
        $response = Http::withToken($this->accessToken())
            ->delete(self::FILES_URL.'/'.rawurlencode($fileId).'?supportsAllDrives=true');

        if (! $response->successful() && $response->status() !== 404) {
            throw new RuntimeException($response->json('error.message') ?: 'Google Drive did not delete the file.');
        }
    }

    /**
     * Send the file in fixed-size pieces (Content-Range). Drive answers 308 with
     * the range it holds until the last piece, then 200/201 with the file. When
     * a piece fails, Drive is asked how much it has before trying again.
     *
     * @return array<string, mixed>
     */
    protected function uploadInChunks(string $token, string $location, StreamInterface $contents, string $mimeType, int $size): array
    {
        $chunkBytes = $this->chunkBytes();
        $offset = 0;
        $failures = 0;

        while (true) {
            $contents->seek($offset);
            $chunk = '';
            $want = min($chunkBytes, $size - $offset);

            while (strlen($chunk) < $want && ! $contents->eof()) {
                $chunk .= $contents->read($want - strlen($chunk));
            }

            if (strlen($chunk) !== $want) {
                throw new RuntimeException('The backup file ended before it was fully uploaded to Google Drive.');
            }

            try {
                $response = Http::withToken($token)
                    ->timeout(120)
                    ->withHeaders(['Content-Range' => sprintf('bytes %d-%d/%d', $offset, $offset + $want - 1, $size)])
                    ->withBody($chunk, $mimeType)
                    ->put($location);
            } catch (ConnectionException) {
                $response = null;
            }

            if ($response?->successful()) {
                return (array) $response->json();
            }

            if ($response?->status() === 308) {
                $next = $this->receivedBytes($response);
                $progressed = $next > $offset;
                $offset = $next;

                if ($progressed) {
                    $failures = 0;

                    continue;
                }
            } elseif ($response !== null && $response->status() < 500 && ! in_array($response->status(), [408, 429], true)) {
                // An expired session or a missing permission will not get better by trying again.
                throw new RuntimeException($response->json('error.message') ?: 'Google Drive upload failed.');
            }

            if (++$failures >= self::CHUNK_ATTEMPTS) {
                throw new RuntimeException($response?->json('error.message') ?: 'Google Drive upload failed: the connection kept dropping.');
            }

            Sleep::for($failures * 2)->seconds();

            // How much does Drive have? Zero-length PUT with an open range.
            try {
                $status = Http::withToken($token)
                    ->timeout(60)
                    ->withHeaders(['Content-Range' => "bytes */{$size}"])
                    ->withBody('', $mimeType)
                    ->put($location);
            } catch (ConnectionException) {
                continue;
            }

            if ($status->successful()) {
                return (array) $status->json();
            }

            if ($status->status() === 308) {
                $offset = $this->receivedBytes($status);
            }
        }
    }

    /** The next byte to send, from the "Range: bytes=0-N" header of a 308 answer. */
    protected function receivedBytes(Response $response): int
    {
        return preg_match('/bytes=0-(\d+)/', (string) $response->header('Range'), $match) === 1
            ? (int) $match[1] + 1
            : 0;
    }

    /** Size of one piece: a multiple of 256 KiB, as Drive requires. */
    protected function chunkBytes(): int
    {
        $configured = (int) config('backup.google_drive.chunk_bytes', 8 * 1024 * 1024);

        return max(self::CHUNK_UNIT, intdiv($configured, self::CHUNK_UNIT) * self::CHUNK_UNIT);
    }

    protected function accessToken(): string
    {
        if ($this->token !== null && time() < $this->tokenExpiresAt) {
            return $this->token;
        }

        $credentials = $this->credentials();
        $clientEmail = $credentials['client_email'] ?? null;
        $privateKey = $credentials['private_key'] ?? null;

        if (blank($clientEmail) || blank($privateKey)) {
            throw new RuntimeException('Google Drive service account credentials are invalid.');
        }

        $now = time();
        $jwt = $this->jwt([
            'iss' => $clientEmail,
            'scope' => self::SCOPE,
            'aud' => self::TOKEN_URL,
            'iat' => $now,
            'exp' => $now + 3600,
        ], $privateKey);

        $response = Http::asForm()->post(self::TOKEN_URL, [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $jwt,
        ]);

        if (! $response->successful()) {
            throw new RuntimeException($response->json('error_description') ?: 'Could not authenticate with Google Drive.');
        }

        // A listing plus a few deletes after an upload would otherwise sign in once each.
        $this->tokenExpiresAt = $now + 3000;

        return $this->token = (string) $response->json('access_token');
    }

    protected function credentials(): array
    {
        $settings = app(BackupSettingsService::class);
        $json = $settings->serviceAccountJson();

        if (filled($json)) {
            return json_decode($json, true) ?: [];
        }

        $path = $settings->serviceAccountPath();

        if (filled($path) && File::exists($path)) {
            return json_decode(File::get($path), true) ?: [];
        }

        return [];
    }

    protected function folderId(): ?string
    {
        return app(BackupSettingsService::class)->googleDriveFolderId();
    }

    protected function jwt(array $claims, string $privateKey): string
    {
        $header = ['alg' => 'RS256', 'typ' => 'JWT'];
        $segments = [
            $this->base64Url(json_encode($header)),
            $this->base64Url(json_encode($claims)),
        ];
        $payload = implode('.', $segments);

        openssl_sign($payload, $signature, $privateKey, OPENSSL_ALGO_SHA256);

        $segments[] = $this->base64Url($signature);

        return implode('.', $segments);
    }

    protected function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}

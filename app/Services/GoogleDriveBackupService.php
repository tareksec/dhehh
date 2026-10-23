<?php

namespace App\Services;

use GuzzleHttp\Psr7\Utils;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
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

    private const SCOPE = 'https://www.googleapis.com/auth/drive.file';

    public function isConfigured(): bool
    {
        return app(BackupSettingsService::class)->googleDriveEnabled()
            && filled($this->folderId())
            && $this->credentials() !== [];
    }

    public function upload(string $absolutePath, ?string $filename = null): array
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
            return $this->uploadStream(Utils::streamFor($handle), $filename, 'application/zip', $folderId);
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

        $response = Http::withToken($token)
            ->timeout(300)
            ->withBody($contents, $mimeType)
            ->put($location);

        if (! $response->successful()) {
            throw new RuntimeException($response->json('error.message') ?: 'Google Drive upload failed.');
        }

        return (array) $response->json();
    }

    protected function accessToken(): string
    {
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

        return $response->json('access_token');
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
